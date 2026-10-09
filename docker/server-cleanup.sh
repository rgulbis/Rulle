#!/bin/sh
# Clears the old stack off the production server so the next deploy starts
# from a clean, known structure. Run it ON the server, as the user that is in
# the docker group - not inside the GitHub runner (the backup is written to a
# host path).
#
#   ./docker/server-cleanup.sh               keep the data volumes (default)
#   ./docker/server-cleanup.sh --wipe-data   also delete the database/uploads
#   ./docker/server-cleanup.sh --yes         skip the first confirmation
#
# What it does, in order:
#   1. lists what exists, asks to continue
#   2. stops the project's containers (30s, so SQLite closes cleanly)
#   3. archives the data volumes to $BACKUP_DIR (verified before going on)
#   4. removes the project's containers and old images, prunes dangling
#      images and build cache
#   5. with --wipe-data only: deletes the data volumes (typed confirmation)
#
# It does not touch: the self-hosted runner, ~/.cloudflared, the cloudflared
# container, the skatepark_default network, or any other project's volumes.
# After it, deploy (re-run the workflow) and then run
# ./docker/cloudflared-setup.sh --reconfigure, then ./docker/server-check.sh.

set -eu

PROJECT="skatepark"
BACKUP_DIR="${BACKUP_DIR:-$HOME/skatepark-backups}"
VOLUMES="${PROJECT}_app_storage ${PROJECT}_app_backups"
LABEL="com.docker.compose.project=$PROJECT"

WIPE=0
YES=0
for arg in "$@"; do
  case "$arg" in
    --wipe-data) WIPE=1 ;;
    --yes) YES=1 ;;
    *) echo "Usage: $0 [--wipe-data] [--yes]" >&2; exit 2 ;;
  esac
done

say() { printf '\n== %s\n' "$*"; }

images() {
  docker image ls --format '{{.Repository}}:{{.Tag}}' \
    | grep -E "^${PROJECT}-(app|mediamtx):" || true
}

say "What is on this server now"
echo "Containers of project '$PROJECT':"
docker ps -a --filter "label=$LABEL" --format '  {{.Names}}  {{.Image}}  {{.Status}}'
echo "Images:"
images | sed 's/^/  /'
echo "Volumes:"
for v in $VOLUMES; do
  if docker volume inspect "$v" >/dev/null 2>&1; then echo "  $v"; else echo "  $v (missing)"; fi
done
echo "Other (unattached) volumes - listed only, never removed here:"
docker volume ls -q --filter dangling=true | sed 's/^/  /'

if [ "$WIPE" -eq 1 ]; then
  echo
  echo "--wipe-data: the volumes above will be DELETED after being archived."
fi

if [ "$YES" -ne 1 ]; then
  printf '\nStop and remove the containers and old images? Data volumes %s. [y/N] ' \
    "$([ "$WIPE" -eq 1 ] && echo 'will be deleted' || echo 'are kept')"
  read -r answer
  [ "$answer" = "y" ] || { echo "Aborted."; exit 1; }
fi

say "Stopping containers"
ids="$(docker ps -q --filter "label=$LABEL")"
[ -z "$ids" ] || docker stop -t 30 $ids

say "Archiving data volumes to $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%d-%H%M%S)"
archived=""
for v in $VOLUMES; do
  if ! docker volume inspect "$v" >/dev/null 2>&1; then
    echo "$v: not present, skipping"
    continue
  fi
  file="$v-$stamp.tgz"
  docker run --rm -v "$v:/v:ro" -v "$BACKUP_DIR:/out" alpine tar czf "/out/$file" -C /v .
  # Don't go on unless the archive reads back.
  tar tzf "$BACKUP_DIR/$file" >/dev/null
  echo "$v -> $BACKUP_DIR/$file ($(du -h "$BACKUP_DIR/$file" | cut -f1))"
  archived="$archived $file"
done

say "Removing containers"
ids="$(docker ps -aq --filter "label=$LABEL")"
[ -z "$ids" ] || docker rm -f $ids

say "Removing old images and build cache"
old_images="$(images)"
[ -z "$old_images" ] || docker image rm -f $old_images
docker image prune -f
docker builder prune -f

if [ "$WIPE" -eq 1 ]; then
  say "Deleting data volumes"
  printf "Type '%s' to delete the volumes (archives stay in %s): " "$PROJECT" "$BACKUP_DIR"
  read -r confirm
  if [ "$confirm" = "$PROJECT" ]; then
    for v in $VOLUMES; do
      docker volume inspect "$v" >/dev/null 2>&1 && docker volume rm "$v"
    done
  else
    echo "Not confirmed; volumes kept."
  fi
fi

say "Done"
echo "Archives:${archived:- none}"
echo
echo "Next:"
echo "  1. Re-run the 'Deploy' workflow on GitHub (or push). It rebuilds and starts the stack."
echo "  2. ./docker/cloudflared-setup.sh --reconfigure"
echo "  3. ./docker/server-check.sh"
echo
echo "To put an archive back into a volume (stack stopped):"
echo "  docker run --rm -v ${PROJECT}_app_storage:/v -v $BACKUP_DIR:/out alpine tar xzf /out/<file> -C /v"
