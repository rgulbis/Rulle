# rullē.lv — Skatepark Management System

A web app for running a skatepark: customer registration, subscription/pass
sales (Stripe), QR-code entry/exit tracking, and an admin panel for staff
and management.

**Roles:**

- **User** — manages their own subscription and uses a personal QR code to
  enter/exit.
- **Employee** — scans QR codes at the entrance to check users in/out.
- **Admin** — manages subscription plans and users, views live/historical
  stats, in the Filament admin panel at `/admin`.

## Local development

Requirements: PHP 8.3+, Composer, Node 22+, npm.

```bash
composer setup   # composer install, .env, app key, migrate, npm install + build
composer dev     # runs the app server, queue listener, Reverb, and Vite together
```

The app is then at `http://localhost:8000`.

### Resetting the local database

```bash
php artisan migrate:fresh --seed
```

This wipes the local SQLite database and reseeds it with the two test
accounts below. Safe to run as often as you like locally — **this specific
command is actually blocked outright in production** (see
`DB::prohibitDestructiveCommands()` in `AppServiceProvider`), so there's no
way to run this against real data by mistake.

### Test accounts (from the seeder)

| Role     | Email                      | Password   |
| -------- | -------------------------- | ---------- |
| Admin    | `admin@xn--rull-eva.lv`    | `password` |
| Employee | `employee@xn--rull-eva.lv` | `password` |

Note - rullē = xn--rull-eva

No seeded account for the **user** role — just register a normal account at
`/register`. Outside production, new registrations are auto-verified, so
there's no email step to work around while testing locally.

### Running tests / checks

```bash
./vendor/bin/pest       # tests only
composer lint:check     # Pint (formatting)
composer types:check    # Larastan/PHPStan
composer ci:check       # everything CI runs: lint, types, tests
```

### Testing Stripe locally

The local `.env` already has Stripe **test-mode** keys (`pk_test_...` /
`sk_test_...`) — nothing here can ever charge a real card. Webhooks need a
bit of extra setup since Stripe can't reach `localhost` directly:

```bash
brew install stripe/stripe-cli/stripe
stripe login
stripe listen --forward-to localhost:8000/stripe/webhook
```

`stripe listen` prints a `whsec_...` signing secret — put it in the local
`.env` as `STRIPE_WEBHOOK_SECRET`, then restart `composer dev` so it picks
up the change. Leave `stripe listen` running in its own terminal while you
test.

Then, logged in as a customer, go to `/subscriptions` and check out with
Stripe's test card `4242 4242 4242 4242` (any future expiry, any CVC, any
postal code) — `4000 0000 0000 0002` simulates a declined card. Watch the
`stripe listen` terminal for incoming webhook events as you go.

There's a recurring ("Monthly Pass") plan seeded already; create a
non-recurring one in `/admin` → Subscription Types to test the one-time
purchase path too (tracked in the `purchases` table, separate from Cashier's
own subscription handling).

### Testing the QR scanner locally

`/staff/scan` needs camera access (`html5-qrcode`), so it has to be tested
in a real browser, not a headless/sandboxed one. Log in as the employee
account, and note the **Entry/Exit toggle** above the camera view — pick the
matching mode before scanning, or the scan is rejected (this exists to stop
one account's QR code being used to check in two people at once).

### Testing time-dependent features without waiting

Reservations, operating hours, and the "typically busy" chart all depend on
the current time. Rather than waiting around for a reservation window to
actually start, fake it:

```bash
php artisan time:fake "2026-09-17 16:15:00"   # or a relative string like "+3 hours"
php artisan time:fake                          # shows what's currently faked, if anything
php artisan time:fake --clear                  # back to the real time
```

This affects every request (web and artisan) until cleared, and is a no-op
in production. For example, to check that a reservation participant with no
active subscription can still enter through `/staff/scan`: book a
reservation a few minutes out, fake the time to land inside that window,
then scan their QR code.

## Production server

The app runs in Docker on a self-hosted server, reachable only through a
Cloudflare Tunnel (no public inbound ports) — see `docker-compose.yml` and
`docker/cloudflared-setup.sh`. Deploys happen automatically via GitHub
Actions (`.github/workflows/deploy.yml`) on every push to `main`, using a
self-hosted runner that also lives on that same server.

```bash
ssh -p 2222 <name>@<serverIp>
```

### Refresh db on prod

```bash
docker exec skatepark-app-1 rm -f storage/app/database.sqlite
docker exec skatepark-app-1 touch storage/app/database.sqlite
docker exec skatepark-app-1 php artisan migrate --force
docker exec skatepark-app-1 php artisan db:seed --force
```

### Livestream (Reolink camera)

`/livestream` is public — no login required, per the spec's guest access —
and plays an HLS feed pulled from a Reolink camera on the same network as
the production server. The pipeline:

```
Reolink camera --RTSP--> MediaMTX (docker-compose service) --HLS--> Cloudflare Tunnel --> browser (hls.js)
```

MediaMTX (`docker/mediamtx.yml`) does the actual RTSP→HLS conversion; the
app never touches the video itself. The camera's RTSP URL is
`CAMERA_RTSP_URL` in `.env` (a `GitHub Actions` secret in prod, same pattern
as `STRIPE_SECRET` etc.) — never commit it, since it embeds the camera's
credentials.

**One-time setup, in this order:**

1. **Find the camera's RTSP URL.** In the Reolink app: Settings → Network →
   IP address. Build the URL as
   `rtsp://<user>:<pass>@<camera-ip>:554/h264Preview_01_sub` (the `_sub`
   substream — lower resolution, much lighter than `_main`, which is plenty
   for a web embed). Test it in VLC (Media → Open Network Stream) before
   wiring anything else up — if VLC can't play it, nothing downstream will
   either.
2. **Add the GitHub secret.** Repo → Settings → Secrets and variables →
   Actions → `CAMERA_RTSP_URL`, value = the URL from step 1.
3. **Update the live Cloudflare Tunnel config.** `docker/cloudflared-setup.sh`
   is a run-once bootstrap script — it won't re-run on its own, so the
   already-provisioned tunnel needs its `ingress` rules updated by hand to
   match what's now in the script. SSH into the server and edit
   `~/.cloudflared/config.yml` to add the `path: ^/live-cam/.*` rule (see the
   script for the exact block and where it goes — order matters, it must
   come before the catch-all rule), then:
   ```bash
   docker restart cloudflared
   ```
4. **Deploy** (push to `main`, or re-run the workflow) so the new
   `CAMERA_RTSP_URL` reaches the server's `.env` and the `mediamtx` service
   starts.
5. **Check it.** `https://www.xn--rull-eva.lv/livestream` should show the
   feed within a few seconds. If it shows "Camera feed isn't available right
   now", check `docker logs skatepark-mediamtx-1` on the server — almost
   always either the RTSP URL/credentials are wrong, or the camera and
   server aren't actually on the same network.
