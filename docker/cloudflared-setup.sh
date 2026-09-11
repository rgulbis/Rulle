#!/bin/sh
# Run once on the server (no sudo needed — everything runs via Docker under
# the user's existing docker group membership). Sets up a locally-managed
# Cloudflare Tunnel (credentials-file based, not token-based) so all config
# lives in a plain YAML file here instead of the Cloudflare dashboard.

set -e

DOMAIN="xn--rull-eva.lv"   # rullē.lv in punycode
TUNNEL_NAME="skatepark"
CF_DIR="$HOME/.cloudflared"

mkdir -p "$CF_DIR"

echo "== Step 1: authenticate with Cloudflare =="
echo "This prints a URL — open it in a browser and authorize the domain."
docker run --rm -it -v "$CF_DIR:/root/.cloudflared" cloudflare/cloudflared:latest tunnel login

echo "== Step 2: create the tunnel =="
docker run --rm -v "$CF_DIR:/root/.cloudflared" cloudflare/cloudflared:latest tunnel create "$TUNNEL_NAME"

TUNNEL_ID=$(docker run --rm -v "$CF_DIR:/root/.cloudflared" cloudflare/cloudflared:latest tunnel list --output json \
  | grep -o "\"id\":\"[a-f0-9-]*\",\"name\":\"$TUNNEL_NAME\"" | sed -E 's/"id":"([a-f0-9-]*)".*/\1/')

echo "Tunnel ID: $TUNNEL_ID"

echo "== Step 3: point the domain's DNS at the tunnel (CLI only, no dashboard) =="
docker run --rm -v "$CF_DIR:/root/.cloudflared" cloudflare/cloudflared:latest tunnel route dns "$TUNNEL_NAME" "$DOMAIN"

echo "== Step 4: write config.yml =="
# The site is actually served from www, not the bare domain (see APP_URL) —
# ingress hostname matching is an exact string match, so every rule below
# needs to say "www.$DOMAIN", not "$DOMAIN", or it silently never matches.
cat > "$CF_DIR/config.yml" <<EOF
tunnel: $TUNNEL_ID
credentials-file: /root/.cloudflared/$TUNNEL_ID.json

ingress:
  # Reverb's WebSocket endpoint (Pusher protocol default path). Must come
  # before the catch-all rule below since ingress rules match top-to-bottom.
  - hostname: www.$DOMAIN
    path: ^/app/.*
    service: http://host.docker.internal:8081
  - hostname: www.$DOMAIN
    service: http://host.docker.internal:8080
  - service: http_status:404
EOF

echo "== Step 5: run the tunnel as a persistent container =="
docker rm -f cloudflared 2>/dev/null || true
docker run -d --name cloudflared --restart unless-stopped \
  --add-host=host.docker.internal:host-gateway \
  -v "$CF_DIR:/root/.cloudflared" \
  cloudflare/cloudflared:latest tunnel --config /root/.cloudflared/config.yml run

echo "Done. Check: docker logs cloudflared"
echo "Site should be live at: https://$DOMAIN"
