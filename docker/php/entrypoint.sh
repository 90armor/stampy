#!/bin/bash
set -e

if [ ! -f composer.json ]; then
    echo "entrypoint: /var/www/html doesn't look like the app (no composer.json), aborting." >&2
    exit 1
fi

if [ ! -d vendor ] || [ -z "$(ls -A vendor 2>/dev/null)" ]; then
    composer install
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64:" .env; then
    php artisan key:generate --ansi
fi

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

exec "$@"
