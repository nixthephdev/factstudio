<?php
declare(strict_types=1);

namespace Fact;

const ROOT = __DIR__ . '/..';
date_default_timezone_set('Asia/Manila');
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'Fact\\') === 0) {
        $file = __DIR__ . '/' . substr($class, 5) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function storage(string $path = ''): string {
    $base = PHP_SAPI === 'cli' ? (getenv('FACT_STORAGE') ?: ROOT . '/storage') : ROOT . '/storage';
    return rtrim($base,'/\\') . '/' . $path;
}
function escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function now(): string { return date('c'); }

foreach (['', 'jobs', 'logs', 'sessions'] as $folder) {
    if (!is_dir(storage($folder))) { mkdir(storage($folder), 0700, true); }
}

function db(): Database {
    static $db;
    return $db ?? ($db = new Database(storage('studio.sqlite')));
}
function settings(): Settings {
    static $settings;
    return $settings ?? ($settings = new Settings(db()));
}
