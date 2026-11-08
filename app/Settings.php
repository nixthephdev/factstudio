<?php
declare(strict_types=1);
namespace Fact;

final class Settings {
    private Database $db;
    public const DEFAULTS = [
        'studio_name'=>'FACT Studio', 'niche'=>'Space and astronomy explained simply', 'language'=>'English',
        'duration'=>'40', 'daily_limit'=>'1', 'publish_time'=>'18:00', 'schedule_enabled'=>'0', 'auto_publish'=>'0', 'review_required'=>'1',
        'ollama_url'=>'http://127.0.0.1:11434', 'ollama_model'=>'qwen3:4b',
        'comfy_url'=>'http://127.0.0.1:8188', 'comfy_checkpoint'=>'v1-5-pruned-emaonly.safetensors',
        'eleven_voice'=>'', 'eleven_model'=>'eleven_multilingual_v2', 'speech_limit'=>'2500',
        'ffmpeg_path'=>'', 'ffprobe_path'=>'', 'youtube_query'=>'space astronomy science explained',
        'youtube_privacy'=>'public', 'youtube_enabled'=>'0', 'youtube_channel'=>'',
        'google_client_id'=>'', 'app_url'=>'http://localhost:8085/',
        'facebook_page_id'=>'', 'facebook_enabled'=>'0', 'instagram_id'=>'', 'instagram_enabled'=>'0',
        'instagram_video_base'=>'', 'graph_version'=>'v23.0', 'remote_hub_url'=>''
    ];
    public const SECRETS = ['eleven_api_key','youtube_api_key','google_client_secret','google_refresh_token','facebook_token','instagram_token','remote_hub_token'];
    public function __construct(Database $db) { $this->db = $db; }
    private function key(): string {
        $path = storage('secret.key');
        $handle = fopen($path, 'c+b');
        if (!$handle || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Cannot open encryption key.'); }
        $value = stream_get_contents($handle);
        if ($value === '') { $value = random_bytes(32); fwrite($handle, $value); fflush($handle); @chmod($path, 0600); }
        flock($handle, LOCK_UN); fclose($handle);
        if (strlen($value) !== 32) { throw new \RuntimeException('Encryption key is damaged. Restore storage/secret.key.'); }
        return $value;
    }
    public function get(string $key): string {
        $row = $this->db->query('SELECT value,secret FROM settings WHERE key=?', [$key])->fetch();
        if (!$row) { return self::DEFAULTS[$key] ?? ''; }
        if (!$row['secret']) { return $row['value']; }
        $raw = base64_decode($row['value'], true);
        if ($raw === false || strlen($raw) < 28) { throw new \RuntimeException('Stored credential is damaged.'); }
        $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16));
        if ($value === false) { throw new \RuntimeException('Cannot decrypt stored credential.'); }
        return $value;
    }
    public function set(string $key, string $value): void {
        $secret = in_array($key, self::SECRETS, true);
        if (!array_key_exists($key, self::DEFAULTS) && !$secret) { throw new \InvalidArgumentException('Unknown setting.'); }
        if ($secret) {
            $iv = random_bytes(12); $tag = '';
            $cipher = openssl_encrypt($value, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) { throw new \RuntimeException('Cannot protect credential.'); }
            $value = base64_encode($iv . $tag . $cipher);
        }
        $this->db->query('INSERT INTO settings(key,value,secret) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,secret=excluded.secret', [$key,$value,(int)$secret]);
    }
    public function publicValues(): array {
        $out = [];
        foreach (self::DEFAULTS as $key=>$default) { $out[$key] = $this->get($key); }
        foreach (self::SECRETS as $key) { $out[$key . '_set'] = $this->get($key) !== ''; }
        return $out;
    }
    public function save(array $input): void {
        foreach ($input as $key=>$value) {
            if (!is_scalar($value)) { throw new \InvalidArgumentException('Invalid setting value.'); }
            $value = trim((string)$value);
            if (in_array($key, self::SECRETS, true)) { if ($value !== '') { $this->set($key,$value); } continue; }
            if (!array_key_exists($key,self::DEFAULTS)) { continue; }
            if (strlen($value)>1000) { throw new \InvalidArgumentException('Setting is too long.'); }
            if (in_array($key,['ollama_url','comfy_url'],true) && !preg_match('~^http://(127\.0\.0\.1|localhost):[0-9]{2,5}/?$~',$value)) { throw new \InvalidArgumentException('Local AI addresses must use localhost or 127.0.0.1 and a port.'); }
            if ($key === 'app_url' && !preg_match('~^http://(localhost|127\.0\.0\.1)(:[0-9]{2,5})?(/[a-zA-Z0-9_-]+)*/?$~',$value)) { throw new \InvalidArgumentException('Use your local studio URL for the OAuth callback.'); }
            if ($key === 'instagram_video_base' && $value !== '' && !filter_var($value,FILTER_VALIDATE_URL)) { throw new \InvalidArgumentException('Invalid public video URL.'); }
            if ($key === 'remote_hub_url' && $value !== '' && !Hub::validUrl($value)) { throw new \InvalidArgumentException('The hosted dashboard must use a valid HTTPS address.'); }
            if ($key === 'duration') { $value = (string) max(20,min(60,(int)$value)); }
            if ($key === 'daily_limit') { $value = (string) max(1,min(3,(int)$value)); }
            if ($key === 'speech_limit') { $value = (string) max(0,min(20000,(int)$value)); }
            if ($key === 'publish_time' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$value)) { throw new \InvalidArgumentException('Invalid production time.'); }
            if ($key === 'youtube_privacy' && !in_array($value,['public','private','unlisted'],true)) { throw new \InvalidArgumentException('Invalid YouTube visibility.'); }
            if ($key === 'graph_version' && !preg_match('/^v[0-9]{2}\.0$/',$value)) { throw new \InvalidArgumentException('Invalid Graph API version.'); }
            if (substr($key,-8)==='_enabled' || in_array($key,['auto_publish','review_required'],true)) { $value = $value === '1' ? '1' : '0'; }
            $this->set($key,$value);
        }
    }
    public function redact(string $message): string {
        foreach (self::SECRETS as $name) { $secret=$this->get($name); if($secret!=='') { $message=str_replace($secret,'[redacted]',$message); } }
        return mb_substr($message,0,1500);
    }
}
