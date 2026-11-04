<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

$url=$argv[1]??'';
if (!preg_match('~^https://[a-z0-9-]+\.trycloudflare\.com$~', $url)) {
    fwrite(STDERR, "Expected a Cloudflare Quick Tunnel HTTPS address.\n");
    exit(1);
}
Fact\settings()->set('instagram_video_base', $url);
if (is_file(Fact\storage('health.json'))) { unlink(Fact\storage('health.json')); }
echo "Instagram video address saved.\n";
