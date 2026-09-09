#!/bin/sh
set -e

mkdir -p storage/app
touch "$(php -r "echo getenv('DB_DATABASE') ?: 'storage/app/database.sqlite';")"

php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache

exec php artisan serve --host=0.0.0.0 --port=8000
