#!/bin/sh
# Read-only audit of the production server: does what is running match the
# structure this repo describes? Run it ON the server after a deploy and
# after docker/cloudflared-setup.sh. Changes nothing; exits with the number
# of failed checks.

PROJECT="skatepark"
NETWORK="skatepark_default"
SITE_HOST="www.xn--rull-eva.lv"
CF_CONFIG="$HOME/.cloudflared/config.yml"
LABEL="com.docker.compose.project=$PROJECT"

fails=0
ok() { printf '  ok    %s\n' "$*"; }
bad() { printf '  FAIL  %s\n' "$*"; fails=$((fails + 1)); }
note() { printf '  note  %s\n' "$*"; }
check() { # check "description" command...
  desc="$1"; shift
  if "$@" >/dev/null 2>&1; then ok "$desc"; else bad "$desc"; fi
}

container() { docker ps -q --filter "label=$LABEL" --filter "label=com.docker.compose.service=$1" | head -n1; }

echo "Services"
for svc in app reverb scheduler mediamtx; do
  id="$(container "$svc")"
  if [ -z "$id" ]; then bad "$svc is not running"; continue; fi
  health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' "$id")"
  case "$health" in
    healthy|no-healthcheck) ok "$svc running ($health)" ;;
    *) bad "$svc is $health" ;;
  esac
done

echo "Network and ports"
check "network $NETWORK exists" docker network inspect "$NETWORK"
published="$(docker ps --filter "label=$LABEL" --format '{{.Names}} {{.Ports}}' | grep -- '->' || true)"
if [ -z "$published" ]; then ok "no container publishes a host port"; else bad "published ports: $published"; fi

echo "Tunnel"
if docker inspect -f '{{json .NetworkSettings.Networks}}' cloudflared 2>/dev/null | grep -q "\"$NETWORK\""; then
  ok "cloudflared is on $NETWORK"
else
  bad "cloudflared is not running on $NETWORK (run docker/cloudflared-setup.sh --reconfigure)"
fi
if [ -r "$CF_CONFIG" ]; then
  if grep -q 'host.docker.internal' "$CF_CONFIG"; then bad "$CF_CONFIG still uses host.docker.internal"; else ok "config targets service names"; fi
  other_hosts="$(grep 'hostname:' "$CF_CONFIG" | grep -v "hostname: $SITE_HOST\$" || true)"
  if [ -z "$other_hosts" ]; then ok "every ingress rule uses $SITE_HOST"; else bad "ingress rules for another host: $other_hosts"; fi
else
  bad "cannot read $CF_CONFIG"
fi

echo "Data"
for v in app_storage app_backups; do
  check "volume ${PROJECT}_$v exists" docker volume inspect "${PROJECT}_$v"
done
app="$(container app)"
if [ -n "$app" ]; then
  db="$(docker exec "$app" printenv DB_DATABASE 2>/dev/null)"
  if [ "$db" = "storage/app/database.sqlite" ]; then ok "DB_DATABASE=$db"; else bad "DB_DATABASE is '$db', expected storage/app/database.sqlite"; fi
  check "database file exists and is not empty" docker exec "$app" test -s storage/app/database.sqlite
  check "no leftover database in database/" sh -c "! docker exec $app test -s database/database.sqlite"
  if docker exec "$app" sh -c 'ls storage/app/backups/database-*.sqlite' >/dev/null 2>&1; then
    ok "at least one backup exists"
  else
    bad "no backups yet (docker compose exec app php artisan db:backup)"
  fi
  check "no pending migrations" sh -c "docker exec $app php artisan migrate:status --pending 2>&1 | grep -q 'No pending migrations'"
fi

echo "Images"
if docker image inspect skatepark-app:latest >/dev/null 2>&1; then ok "skatepark-app:latest present"; else bad "skatepark-app:latest missing"; fi
if docker image inspect skatepark-app:rollback >/dev/null 2>&1; then ok "skatepark-app:rollback present"; else note "no :rollback image (normal until the second deploy)"; fi
old="$(docker image ls --format '{{.Repository}}' | grep -c '^skatepark-mediamtx$' || true)"
if [ "$old" = "0" ]; then ok "no old custom mediamtx image"; else bad "old skatepark-mediamtx image still present"; fi

echo "Site"
check "https://$SITE_HOST/up answers" curl -fsS -m 10 -o /dev/null "https://$SITE_HOST/up"

echo
if [ "$fails" -eq 0 ]; then echo "All checks passed."; else echo "$fails check(s) failed."; fi
exit "$fails"
