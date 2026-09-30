#!/bin/sh
set -e

mkdir -p storage/app
touch "$(php -r "echo getenv('DB_DATABASE') ?: 'storage/app/database.sqlite';")"

php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache

# `php artisan serve` defaults to exactly one worker process — every
# request on the entire site, regardless of what it does, queues behind
# whichever single request happens to be running at that moment. That's
# likely the single biggest cause of "the whole site is unresponsive" under
# concurrent load, independent of anything else being slow. Forking
# multiple workers needs pcntl (already installed in this image) and the
# `--no-reload` flag — without it Laravel silently ignores
# PHP_CLI_SERVER_WORKERS and falls back to one worker with just a warning.
# The reload-on-file-change behaviour that flag disables is a local-dev
# convenience only; a deployed container's code never changes at runtime.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
