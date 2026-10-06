#!/bin/sh
# Run on the server (no sudo needed — everything runs via Docker under the
# user's existing docker group membership). Sets up a locally-managed
# Cloudflare Tunnel (credentials-file based, not token-based) so all config
# lives in a plain YAML file here instead of the Cloudflare dashboard.
#
#   ./docker/cloudflared-setup.sh                 first-time setup
#   ./docker/cloudflared-setup.sh --reconfigure   keep the existing tunnel and
#                                                 DNS; rewrite config.yml and
#                                                 recreate the container
#
# The cloudflared container joins the compose project's network and reaches
# the app by service name — docker-compose.yml publishes no ports. That
# network exists once the stack has been deployed at least once.

set -e

DOMAIN="xn--rull-eva.lv"   # rullē.lv in punycode
# The site is served from www, not the bare domain (see APP_URL). The DNS
# record, every ingress rule and the final message all use this one host.
SITE_HOST="www.$DOMAIN"
TUNNEL_NAME="skatepark"
NETWORK="skatepark_default"   # pinned in docker-compose.yml
CF_DIR="$HOME/.cloudflared"
CF_IMAGE="cloudflare/cloudflared:latest"

RECONFIGURE=0
case "${1:-}" in
  "") ;;
  --reconfigure) RECONFIGURE=1 ;;
  *) echo "Usage: $0 [--reconfigure]" >&2; exit 2 ;;
esac

cloudflared() {
  docker run --rm -v "$CF_DIR:/root/.cloudflared" "$CF_IMAGE" "$@"
}

if ! docker network inspect "$NETWORK" >/dev/null 2>&1; then
  echo "Docker network '$NETWORK' doesn't exist yet. Deploy the stack first" >&2
  echo "(push to main, or: docker compose up -d), then re-run this script." >&2
  exit 1
fi

mkdir -p "$CF_DIR"

if [ "$RECONFIGURE" -eq 1 ]; then
  TUNNEL_ID=$(sed -n 's/^tunnel: *//p' "$CF_DIR/config.yml" 2>/dev/null | head -n1)
  if [ -z "$TUNNEL_ID" ]; then
    echo "No existing $CF_DIR/config.yml to reconfigure; run without --reconfigure." >&2
    exit 1
  fi
  echo "Reusing tunnel $TUNNEL_ID"
else
  echo "== Step 1: authenticate with Cloudflare =="
  echo "This prints a URL — open it in a browser and authorize the domain."
  docker run --rm -it -v "$CF_DIR:/root/.cloudflared" "$CF_IMAGE" tunnel login

  echo "== Step 2: create the tunnel =="
  cloudflared tunnel create "$TUNNEL_NAME"

  TUNNEL_ID=$(cloudflared tunnel list --output json \
    | grep -o "\"id\":\"[a-f0-9-]*\",\"name\":\"$TUNNEL_NAME\"" | sed -E 's/"id":"([a-f0-9-]*)".*/\1/')

  if [ -z "$TUNNEL_ID" ]; then
    echo "Could not find the ID of tunnel '$TUNNEL_NAME'." >&2
    exit 1
  fi

  echo "== Step 3: point the site's DNS at the tunnel (CLI only, no dashboard) =="
  cloudflared tunnel route dns "$TUNNEL_NAME" "$SITE_HOST"
fi

echo "Tunnel ID: $TUNNEL_ID"

echo "== Step 4: write config.yml =="
# Ingress hostname matching is an exact string match, so every rule below
# says "$SITE_HOST" — a rule for the bare domain would silently never match.
# The services are the compose service names, reachable on the shared network.
cat > "$CF_DIR/config.yml" <<EOF
tunnel: $TUNNEL_ID
credentials-file: /root/.cloudflared/$TUNNEL_ID.json

ingress:
  # Reverb's WebSocket endpoint (Pusher protocol default path). Must come
  # before the catch-all rule below since ingress rules match top-to-bottom.
  - hostname: $SITE_HOST
    path: ^/app/.*
    service: http://reverb:8080
  # MediaMTX's HLS output for the livestream page (see docker-compose.yml)
  # — same reasoning, must precede the catch-all.
  - hostname: $SITE_HOST
    path: ^/live-cam/.*
    service: http://mediamtx:8888
  - hostname: $SITE_HOST
    service: http://app:8000
  - service: http_status:404
EOF

echo "== Step 5: run the tunnel as a persistent container on the compose network =="
docker rm -f cloudflared 2>/dev/null || true
docker run -d --name cloudflared --restart unless-stopped \
  --network "$NETWORK" \
  -v "$CF_DIR:/root/.cloudflared" \
  "$CF_IMAGE" tunnel --config /root/.cloudflared/config.yml run

echo "Done. Check: docker logs cloudflared"
echo "Site should be live at: https://$SITE_HOST"
