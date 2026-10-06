#!/bin/sh
# Run once on the server (no sudo needed — everything runs via Docker under
# the user's existing docker group membership). Sets up a locally-managed
# Cloudflare Tunnel (credentials-file based, not token-based) so all config
# lives in a plain YAML file here instead of the Cloudflare dashboard.

set -e

DOMAIN="xn--rull-eva.lv"   # rullē.lv in punycode
# The site is served from www (see APP_URL in .github/workflows/deploy.yml).
# DNS and every ingress rule below must use this same host, or the DNS record
# and the tunnel's rules point at different names.
SITE_HOST="www.$DOMAIN"
# Compose project network (COMPOSE_PROJECT_NAME=skatepark in the deploy
# workflow). The tunnel joins it so it can reach the services by name — none
# of them publish a port on the host.
NETWORK="skatepark_default"
TUNNEL_NAME="skatepark"
CF_DIR="$HOME/.cloudflared"

mkdir -p "$CF_DIR"

# `./cloudflared-setup.sh --reconfigure` skips creating the tunnel and its DNS
# record and only rewrites config.yml and restarts the container — use it for an
# already-provisioned tunnel (e.g. after the ingress rules change here).
RECONFIGURE=false
[ "$1" = "--reconfigure" ] && RECONFIGURE=true

if [ "$RECONFIGURE" = false ]; then
echo "== Step 1: authenticate with Cloudflare =="
echo "This prints a URL — open it in a browser and authorize the domain."
docker run --rm -it -v "$CF_DIR:/root/.cloudflared" cloudflare/cloudflared:latest tunnel login

echo "== Step 2: create the tunnel =="
docker run --rm -v "$CF_DIR:/root/.cloudflared" cloudflare/cloudflared:latest tunnel create "$TUNNEL_NAME"

fi

TUNNEL_ID=$(docker run --rm -v "$CF_DIR:/root/.cloudflared" cloudflare/cloudflared:latest tunnel list --output json \
  | grep -o "\"id\":\"[a-f0-9-]*\",\"name\":\"$TUNNEL_NAME\"" | sed -E 's/"id":"([a-f0-9-]*)".*/\1/')

echo "Tunnel ID: $TUNNEL_ID"

if [ "$RECONFIGURE" = false ]; then
echo "== Step 3: point the site's DNS at the tunnel (CLI only, no dashboard) =="
docker run --rm -v "$CF_DIR:/root/.cloudflared" cloudflare/cloudflared:latest tunnel route dns "$TUNNEL_NAME" "$SITE_HOST"
fi

echo "== Step 4: write config.yml =="
# Ingress hostname matching is an exact string match, so every rule below
# uses $SITE_HOST — the same name the DNS route above was created for.
cat > "$CF_DIR/config.yml" <<EOF
tunnel: $TUNNEL_ID
credentials-file: /root/.cloudflared/$TUNNEL_ID.json

ingress:
  # Reverb's WebSocket endpoint (Pusher protocol default path). Must come
  # before the catch-all rule below since ingress rules match top-to-bottom.
  - hostname: $SITE_HOST
    path: ^/app/.*
    service: http://reverb:8080
  # MediaMTX's HLS output for the livestream page (see the mediamtx
  # service in docker-compose.yml) — same reasoning, must precede the catch-all.
  - hostname: $SITE_HOST
    path: ^/live-cam/.*
    service: http://mediamtx:8888
  - hostname: $SITE_HOST
    service: http://app:8000
  - service: http_status:404
EOF

echo "== Step 5: run the tunnel as a persistent container =="
docker rm -f cloudflared 2>/dev/null || true
docker network inspect "$NETWORK" >/dev/null 2>&1 || {
  echo "Network $NETWORK doesn't exist yet — deploy the app once first (docker compose up)." >&2
  exit 1
}
docker run -d --name cloudflared --restart unless-stopped \
  --network "$NETWORK" \
  -v "$CF_DIR:/root/.cloudflared" \
  cloudflare/cloudflared:latest tunnel --config /root/.cloudflared/config.yml run

echo "Done. Check: docker logs cloudflared"
echo "Site should be live at: https://$SITE_HOST"
