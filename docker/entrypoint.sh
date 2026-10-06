#!/bin/sh
set -e

# One database location for every way of starting this image. docker-compose
# sets the same value explicitly; this is the fallback for a plain `docker run`.
# It lives under storage/, which docker-compose mounts as a persistent volume —
# the SQLite file must never end up outside a volume.
export DB_DATABASE="${DB_DATABASE:-/var/www/html/storage/app/database.sqlite}"

mkdir -p "$(dirname "$DB_DATABASE")"
touch "$DB_DATABASE"

# Snapshot before touching the schema (prints nothing for a brand-new DB). If
# the migration fails, put the snapshot back and refuse to start, so the
# deploy healthcheck fails and the pipeline rolls the image back instead of
# serving against a half-migrated database.
BACKUP="$(php artisan db:backup --prefix=pre-migrate --keep=20)"
if ! php artisan migrate --force; then
    echo "Migration failed." >&2
    if [ -n "$BACKUP" ]; then
        php artisan db:restore "$BACKUP" --force
    fi
    exit 1
fi

php artisan storage:link || true
php artisan config:cache
php artisan route:cache

# Runs the scheduler (refund retries, daily DB backup) — the container has no cron.
php artisan schedule:work >/dev/null 2>&1 &

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
