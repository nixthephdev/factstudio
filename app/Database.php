<?php
declare(strict_types=1);
namespace Fact;

final class Database {
    public \PDO $pdo;
    public function __construct(string $file) {
        $this->pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON;');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, secret INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, mode TEXT NOT NULL, topic TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'queued', stage TEXT NOT NULL DEFAULT 'research',
                title TEXT NOT NULL DEFAULT 'Finding the next story', error TEXT, data TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, schedule_key TEXT UNIQUE);
            CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER, message TEXT NOT NULL, created_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS speech_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, characters INTEGER NOT NULL, day TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS publications (job_id INTEGER NOT NULL, platform TEXT NOT NULL, state TEXT NOT NULL, remote_id TEXT, data TEXT NOT NULL DEFAULT '{}', updated_at TEXT NOT NULL, PRIMARY KEY(job_id, platform));");
    }
    public function query(string $sql, array $params = []): \PDOStatement {
        $q = $this->pdo->prepare($sql); $q->execute($params); return $q;
    }
    public function createJob(string $mode, string $topic = '', ?string $scheduleKey = null): int {
        if (!in_array($mode, ['demo', 'preview', 'live'], true)) { throw new \InvalidArgumentException('Unknown production mode.'); }
        $this->query('INSERT INTO jobs(mode,topic,title,created_at,updated_at,schedule_key) VALUES(?,?,?,?,?,?)', [$mode, $topic, $mode === 'demo' ? 'A little closer to the stars' : ($topic ?: 'Finding the next story'), now(), now(), $scheduleKey]);
        $id = (int) $this->pdo->lastInsertId();
        $this->event($id, $mode === 'demo' ? 'Demo queued. Uses sample artwork and a Windows voice. Never publishes.' : ($mode === 'preview' ? 'AI preview queued. Original script and images, free Windows narration. Never publishes.' : 'Production queued. Research and original generation will run locally.'));
        return $id;
    }
    public function job(int $id): array {
        $job = $this->query('SELECT * FROM jobs WHERE id=?', [$id])->fetch();
        if (!$job) { throw new \RuntimeException('Video not found.'); }
        $job['data'] = json_decode($job['data'], true, 512, JSON_THROW_ON_ERROR);
        return $job;
    }
    public function jobs(): array {
        $rows = $this->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 60')->fetchAll();
        foreach ($rows as &$row) { $data = json_decode($row['data'], true) ?: []; $row['data'] = array_intersect_key($data,array_flip(['video','images','publications'])); unset($row['schedule_key']); }
        return $rows;
    }
    public function update(int $id, array $values): void {
        $sets = []; $params = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, ['status','stage','title','error','data'], true)) { throw new \InvalidArgumentException('Invalid job update.'); }
            $sets[] = $key . '=?'; $params[] = $key === 'data' ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
        }
        $sets[] = 'updated_at=?'; $params[] = now(); $params[] = $id;
        $this->query('UPDATE jobs SET ' . implode(',', $sets) . ' WHERE id=?', $params);
    }
    public function event(?int $id, string $message): void {
        $this->query('INSERT INTO events(job_id,message,created_at) VALUES(?,?,?)', [$id, $message, now()]);
    }
    public function claim(): ?array {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $id = $this->query("SELECT id FROM jobs WHERE status='queued' ORDER BY id LIMIT 1")->fetchColumn();
            if ($id === false) { $this->pdo->exec('COMMIT'); return null; }
            $this->update((int) $id, ['status'=>'running','error'=>null]);
            $this->pdo->exec('COMMIT'); return $this->job((int) $id);
        } catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
    public function reserveSpeech(int $id, int $characters, int $limit): void {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $spent = (int) $this->query('SELECT COALESCE(SUM(characters),0) FROM speech_usage WHERE day=?', [date('Y-m-d')])->fetchColumn();
            if ($spent + $characters > $limit) { throw new \RuntimeException('Daily ElevenLabs character limit reached. Raise the limit or retry tomorrow.'); }
            $this->query('INSERT INTO speech_usage(job_id,characters,day) VALUES(?,?,?)', [$id, $characters, date('Y-m-d')]);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
}
