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
