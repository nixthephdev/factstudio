<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use function Fact\db;
use function Fact\settings;
use function Fact\storage;

function transferFiles(string $snapshot): array {
    $files=['studio.sqlite'=>base64_encode(file_get_contents($snapshot))];
    foreach(['secret.key','media.key']as$file){if(is_file(storage($file))){$files[$file]=base64_encode(file_get_contents(storage($file)));}}
    $jobs=storage('jobs');
    if(is_dir($jobs)){
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jobs,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as$file){
            if(!$file->isFile()||$file->isLink()){continue;}
            $relative=str_replace('\\','/',substr($file->getPathname(),strlen(rtrim(storage(),'/\\'))+1));
            $files[$relative]=base64_encode(file_get_contents($file->getPathname()));
        }
    }
    return $files;
}

function exportBackup(string $destination): void {
    if(is_file($destination)||is_file($destination.'.recovery-key.txt')){throw new RuntimeException('Choose a new backup filename; an existing backup will not be overwritten.');}
    if(!is_dir(dirname($destination))){throw new RuntimeException('The backup destination folder does not exist.');}
    $lock=fopen(storage('worker.lock'),'c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){throw new RuntimeException('Wait for the production worker to finish before making a transfer backup.');}
    $snapshot=storage('transfer-snapshot-'.bin2hex(random_bytes(5)).'.sqlite');
    try{
        db()->query('VACUUM INTO '.db()->pdo->quote($snapshot));
        $files=transferFiles($snapshot);
        $payload=gzencode(json_encode(['version'=>1,'created_at'=>date('c'),'files'=>$files],JSON_THROW_ON_ERROR),6);
        if($payload===false){throw new RuntimeException('Cannot compress the transfer backup.');}
        $key=random_bytes(32);$iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($payload,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'FACT-STUDIO-BACKUP-1');
        if($cipher===false){throw new RuntimeException('Cannot encrypt the transfer backup.');}
        $recovery=$destination.'.recovery-key.txt';
        if(file_put_contents($recovery,base64_encode($key).PHP_EOL,LOCK_EX)===false){throw new RuntimeException('Cannot write recovery key.');}
        @chmod($recovery,0600);
        if(file_put_contents($destination,'FACTBK1' . $iv . $tag . $cipher,LOCK_EX)===false){throw new RuntimeException('Cannot write backup file.');}
        @chmod($destination,0600);
        echo "Encrypted backup: $destination\nRecovery key file: $recovery\nIncluded files: ".count($files)."\nCopy both files privately. Keep the recovery key out of GitHub.\n";
    }finally{if(is_file($snapshot)){unlink($snapshot);}flock($lock,LOCK_UN);fclose($lock);}
}

function importBackup(string $source,string $recoveryFile): void {
    if(!is_file($source)||!is_file($recoveryFile)){throw new RuntimeException('Both the backup file and recovery key file are required.');}
    $key=base64_decode(trim(file_get_contents($recoveryFile)),true);
    $raw=file_get_contents($source);
    if($key===false||strlen($key)!==32||strlen($raw)<36||substr($raw,0,7)!=='FACTBK1'){throw new RuntimeException('Invalid backup or recovery key.');}
    $payload=openssl_decrypt(substr($raw,35),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,7,12),substr($raw,19,16),'FACT-STUDIO-BACKUP-1');
    if($payload===false){throw new RuntimeException('The recovery key does not match this backup, or the backup is damaged.');}
    $json=gzdecode($payload);if($json===false){throw new RuntimeException('Backup compression is damaged.');}
    $data=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    if(($data['version']??0)!==1||!isset($data['files']['studio.sqlite'],$data['files']['secret.key'])){throw new RuntimeException('Required connection files are missing from the backup.');}
    $files=[];
    foreach($data['files']as$path=>$encoded){
        if(!in_array($path,['studio.sqlite','secret.key','media.key'],true)&&!preg_match('~^jobs/[0-9]+/[a-zA-Z0-9_.-]+$~',$path)){throw new RuntimeException('Backup contains an unexpected file path.');}
        $content=base64_decode($encoded,true);if($content===false){throw new RuntimeException('Backup contains damaged file content.');}$files[$path]=$content;
    }
    if(strlen($files['secret.key'])!==32){throw new RuntimeException('Backup contains an invalid credential key.');}
    $lock=fopen(storage('worker.lock'),'c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){throw new RuntimeException('Stop the local production worker before restoring.');}
    try{
        if(is_file(storage('studio.sqlite'))){
            $existing=new PDO('sqlite:'.storage('studio.sqlite'));
            $configured=(int)$existing->query('SELECT COUNT(*) FROM settings')->fetchColumn();
            $jobs=(int)$existing->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
            if($configured>0||$jobs>0){throw new RuntimeException('This studio already contains settings or videos. Restore into a fresh clone so existing data is preserved.');}
            $existing->exec('PRAGMA wal_checkpoint(TRUNCATE)');$existing=null;
            foreach(['studio.sqlite-wal','studio.sqlite-shm','studio.sqlite']as$name){if(is_file(storage($name))&&!unlink(storage($name))){throw new RuntimeException('Close the local server before restoring.');}}
        }
        foreach($files as$path=>$content){$target=storage($path);if(!is_dir(dirname($target))){mkdir(dirname($target),0700,true);}if(file_put_contents($target,$content,LOCK_EX)===false){throw new RuntimeException('Cannot restore '.$path);}@chmod($target,0600);}
        // Restore connections while leaving activation of this machine explicit.
        settings()->set('schedule_enabled','0');settings()->set('auto_publish','0');settings()->set('instagram_enabled','0');
        settings()->set('ffmpeg_path','');settings()->set('ffprobe_path','');
        db()->query("UPDATE jobs SET status='blocked',error='Transferred from another PC. Retry to resume saved progress.' WHERE status IN ('queued','running','publishing')");
        foreach(Fact\Settings::SECRETS as$name){settings()->get($name);}
        echo "Restored connections and videos. Credentials decrypt successfully.\nInstall local tools, open http://localhost:8085/, and re-enable scheduling/publishing when ready.\nProvider tokens still expire or can be revoked. Instagram remains disabled.\n";
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

try{
    $command=$argv[1]??'';
    if($command==='export'&&!empty($argv[2])){exportBackup($argv[2]);}
    elseif($command==='import'&&!empty($argv[2])&&!empty($argv[3])){importBackup($argv[2],$argv[3]);}
    else{echo "php bin/transfer.php export C:/private/fact-studio.factbackup\nphp bin/transfer.php import C:/private/fact-studio.factbackup C:/private/fact-studio.factbackup.recovery-key.txt\n";exit(1);}
}catch(Throwable $e){fwrite(STDERR,'Transfer failed: '.$e->getMessage()."\n");exit(1);}
