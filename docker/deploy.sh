#!/bin/sh
# Build, start and health-check the stack; roll back to the previous image if
# the new one doesn't come up healthy. Called by .github/workflows/deploy.yml
# on the self-hosted runner, with .env already written.
#
#   1. Tag the image that is live now as :rollback.
#   2. Build the new image.
#   3. Snapshot the database (with the new image's `db:backup`, against the
#      live data) - the entrypoint snapshots again right before migrating.
#   4. Only while FRESH_DB_ON_DEPLOY=true (the GitHub repository variable of
#      that name; pre-launch only): stop the app and wipe + reseed the
#      database with `db:fresh-deploy`. Unset or anything else = skipped.
#   5. `up --wait`: succeeds only when every service with a healthcheck is
#      healthy and the rest are running.
#   6. If that fails, put the :rollback image back and start it.

set -eu

APP_IMAGE="skatepark-app"
WAIT_TIMEOUT="${DEPLOY_WAIT_TIMEOUT:-240}"

log() { printf '== %s\n' "$*"; }
fail() { printf '::error::%s\n' "$*" >&2; }

# configs.content (the MediaMTX config) needs Compose 2.23.1 or newer.
compose_version="$(docker compose version --short 2>/dev/null | sed 's/^v//')"
if [ -z "$compose_version" ] || \
   [ "$(printf '%s\n2.23.1\n' "$compose_version" | sort -V | head -n1)" != "2.23.1" ]; then
    fail "Docker Compose >= 2.23.1 is required (found: ${compose_version:-none})."
    exit 1
fi

had_previous=""
if docker image inspect "$APP_IMAGE:latest" >/dev/null 2>&1; then
    docker tag "$APP_IMAGE:latest" "$APP_IMAGE:rollback"
    had_previous="yes"
fi

log "Building"
docker compose build

predeploy_snapshot=""
if [ -n "$had_previous" ]; then
    log "Snapshotting the database"
    # A one-off container of the new image, bypassing its entrypoint (which
    # would migrate and start a server), on the same volumes as the live app.
    # Deliberately fatal: no snapshot, no deploy.
    # (Captured, not piped, so a failing backup still stops the script.)
    snapshot_output="$(docker compose run --rm --no-deps -T --entrypoint php app \
        artisan db:backup --label=pre-deploy --keep=10)"
    predeploy_snapshot="$(printf '%s\n' "$snapshot_output" | tail -n1)"
    printf 'Snapshot: %s\n' "$predeploy_snapshot"
else
    log "First deploy: no database to snapshot"
fi

fresh_failed=""
if [ "${FRESH_DB_ON_DEPLOY:-}" = "true" ]; then
    log "FRESH_DB_ON_DEPLOY is on: the database will be WIPED and reseeded"
    # Checked before anything is stopped, so a missing secret never costs the
    # running site any downtime.
    if [ -z "${SEED_PASSWORD:-}" ]; then
        fail "FRESH_DB_ON_DEPLOY is on but the SEED_PASSWORD secret is empty; add it in GitHub, or turn the switch off."
        exit 1
    fi
    # Nothing may write to the database while it is being replaced. A one-off
    # container does the wipe so it runs once per deploy; putting it in the
    # entrypoint would wipe on every container restart and server reboot.
    # The flag and seed password are passed in explicitly, so the running app
    # never carries the flag. db:fresh-deploy snapshots first and restores the
    # snapshot itself if the wipe fails.
    docker compose stop app reverb scheduler
    if ! docker compose run --rm --no-deps -T \
        -e FRESH_DB_ON_DEPLOY=true -e SEED_PASSWORD \
        --entrypoint php app artisan db:fresh-deploy; then
        fresh_failed="yes"
    fi
fi

log "Starting"
if [ -n "$fresh_failed" ]; then
    fail "Wiping the database failed (its snapshot was restored); not starting the new version."
elif docker compose up -d --force-recreate --wait --wait-timeout "$WAIT_TIMEOUT"; then
    log "Healthy"
    docker compose ps
    exit 0
else
    fail "The new containers did not become healthy."
    docker compose ps || true
    docker compose logs --no-color --tail=60 app || true
fi

if [ -z "$had_previous" ]; then
    fail "No previous image to roll back to."
    exit 1
fi

log "Rolling back to the previous image"
docker tag "$APP_IMAGE:rollback" "$APP_IMAGE:latest"

if docker compose up -d --no-build --force-recreate --wait --wait-timeout "$WAIT_TIMEOUT"; then
    fail "Deploy failed; the previous version is running again."
else
    fail "Deploy failed AND the rollback did not come up healthy - the site is probably down."
fi

# The entrypoint restores its own snapshot when a migration fails. If the
# migrations succeeded and the app broke afterwards, the old image is running
# on the new schema; the pre-deploy snapshot is the way back to the old one.
printf 'Pre-deploy snapshot, if the schema needs reverting too: %s\n' "$predeploy_snapshot"
printf 'See "Backups and rollback" in the README for how to restore it.\n'
exit 1
