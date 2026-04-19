#!/usr/bin/env bash
set -e

PORT="${PORT:-10000}"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s#\\*:80>#\\*:${PORT}>#" /etc/apache2/sites-available/000-default.conf

mkdir -p \
    storage/framework/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

php artisan storage:link || true

attempt=1
until php artisan migrate --force; do
    if [ "$attempt" -ge 10 ]; then
        echo "Database was not ready after ${attempt} attempts."
        exit 1
    fi

    echo "Waiting for database... attempt ${attempt}/10"
    attempt=$((attempt + 1))
    sleep 5
done

php artisan db:seed --force

exec apache2-foreground
