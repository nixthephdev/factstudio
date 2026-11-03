<?php
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~^/assets/[a-zA-Z0-9._-]+\.(css|js|svg)$~', $path) && is_file(__DIR__ . $path)) {
    return false;
}
if ($path !== '/' && $path !== '/index.php') {
    http_response_code(404);
    exit('Not found');
}
require __DIR__ . '/index.php';
