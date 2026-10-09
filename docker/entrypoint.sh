#!/bin/sh
set -e

# Same path as DB_DATABASE in .env.example and docker-compose.yml; it lives
# inside the app_storage volume, so it survives redeploys.
DB_FILE="${DB_DATABASE:-storage/app/database.sqlite}"

mkdir -p "$(dirname "$DB_FILE")"
touch "$DB_FILE"

# Snapshots first, runs pending migrations, and puts the snapshot back if one
# fails - `set -e` then stops the container, so the deploy's health check
# fails and the pipeline rolls back to the previous image.
php artisan db:migrate-safe
php artisan storage:link || true
php artisan config:cache
php artisan route:cache

# `php artisan serve` defaults to exactly one worker process - every
# request on the entire site, regardless of what it does, queues behind
# whichever single request happens to be running at that moment. That's
# likely the single biggest cause of "the whole site is unresponsive" under
# concurrent load, independent of anything else being slow. Forking
# multiple workers needs pcntl (already installed in this image) and the
# `--no-reload` flag - without it Laravel silently ignores
# PHP_CLI_SERVER_WORKERS and falls back to one worker with just a warning.
# The reload-on-file-change behaviour that flag disables is a local-dev
# convenience only; a deployed container's code never changes at runtime.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
