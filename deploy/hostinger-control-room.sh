#!/usr/bin/env bash
set -euo pipefail
app="$HOME/domains/fulodental.com/fact-studio"
public="$HOME/domains/fulodental.com/app/public/studio"
archive="$HOME/domains/fulodental.com/fact-control-room.tar"
test -f "$app/.fact-studio-control-room"
test -f "$app/.env"
tar -xf "$archive" -C "$app"
mkdir -p "$app/bootstrap/cache" "$app/storage/framework/cache/data" "$app/storage/framework/sessions" "$app/storage/framework/views" "$app/storage/logs" "$app/storage/app/private"
chmod 700 "$app/storage" "$app/bootstrap/cache"
chmod 600 "$app/.env"
touch "$app/database/studio.sqlite"
chmod 600 "$app/database/studio.sqlite"
cd "$app"
php artisan package:discover --no-interaction
php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
php artisan view:cache --no-interaction
chmod 711 "$app"
if [ -L "$public" ]; then
    test "$(readlink "$public")" = "$app/public"
elif [ -e "$public" ]; then
    echo 'The public studio path is already occupied. Deployment stopped.' >&2
    exit 1
else
    ln -s "$app/public" "$public"
fi
printf '%s\n' 'FACT Studio deployed at https://fulodental.com/studio/'
