# rullē.lv — Skatepark Management System

A web app for running a skatepark: customers register, buy passes or a
subscription, book the whole park for a group, enter with a QR code, and chat;
staff scan people in and out; admins run everything from a panel. The public
can watch a live camera feed and see how busy the park is.

Qualification project. Live at **https://www.xn--rull-eva.lv** (rullē.lv).

- [What it does](#what-it-does)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How the important parts work](#how-the-important-parts-work)
- [Testing](#testing)
- [Production](#production)
- [Operations runbook](#operations-runbook)
- [Project layout](#project-layout)
- [Design decisions and known limits](#design-decisions-and-known-limits)

## What it does

| Role         | Can do                                                                                                                                                                                         |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Guest**    | Watch the livestream, see the live headcount and opening hours, browse passes.                                                                                                                 |
| **Customer** | Buy a one-time pass or subscription, book a private reservation, invite friends, show a QR code at the door, use the global and group chats.                                                   |
| **Employee** | Scan QR codes at the entrance (entry/exit), moderate the global chat.                                                                                                                          |
| **Admin**    | Everything in the Filament panel at `/admin`: users, plans, purchases, reservations, payments ledger, revenue and occupancy stats, chat moderation, name approvals, pricing and opening hours. |

**Stack:** Laravel 13 · Inertia + React 19 · Filament 5 (admin) · Tailwind 4 ·
Stripe via Laravel Cashier · Laravel Reverb (WebSockets) · SQLite · MediaMTX
(camera → HLS) · Docker + Cloudflare Tunnel in production. The UI is in Latvian
and English.

## Quick start

Requirements: PHP 8.3+ (extensions: `intl`, `pdo_sqlite`, `bcmath`, `pcntl`),
Composer, Node 22+, npm.

```bash
composer setup   # install deps, create .env, app key, migrate, npm install + build
composer dev     # app server, queue listener, Reverb and Vite together
```

The app is at `http://localhost:8000`. Seed two accounts (admin and employee):

```bash
php artisan db:seed
```

| Role     | Email                      | Password   |
| -------- | -------------------------- | ---------- |
| Admin    | `admin@xn--rull-eva.lv`    | `password` |
| Employee | `employee@xn--rull-eva.lv` | `password` |

(`rullē.lv` is `xn--rull-eva.lv` in punycode; the app accepts either spelling
when you type an email.) There is no seeded customer — register at `/register`.
Outside production new accounts are verified automatically, so there is no email
step locally. These seeded accounts are for local use only; in production,
register normally and promote with `php artisan user:set-role <email> admin`.

Reset the local database any time with `php artisan migrate:fresh --seed`. That
command is blocked outright in production.

## Configuration

Everything is configured through `.env` (`composer setup` copies
`.env.example`). The settings that matter beyond Laravel's defaults:

| Service        | Variables                                                                                   | Notes                                                                                                     |
| -------------- | ------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Database       | `DB_CONNECTION=sqlite`, `DB_DATABASE` (optional)                                            | **SQLite only.** The payments view, occupancy analytics and overlap triggers use SQLite SQL.              |
| Stripe         | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `CASHIER_CURRENCY`                  | Test-mode keys locally. Webhook endpoint is `/stripe/webhook` — events listed [below](#stripe-webhook).   |
| Reverb (chat)  | `REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME`, `VITE_REVERB_*`, `BROADCAST_CONNECTION=reverb` | `REVERB_*` is where the app reaches Reverb; `VITE_*` is what the browser connects to (baked in at build). |
| Email (Resend) | `MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS`                                 | Locally `MAIL_MAILER=log` is fine.                                                                        |
| Camera         | `CAMERA_RTSP_URL`                                                                           | `rtsp://user:pass@camera-ip:554/h264Preview_01_sub`. Contains credentials — never commit it.              |
| Browser tests  | `E2E_MODE`                                                                                  | Only set by the Playwright run. **Must never be set in production** (the app refuses to boot if it is).   |

Park opening hours, reservation prices, group-size limits and the refund cutoff
are edited in the admin panel (Reservation pricing), not in `.env`.

## How the important parts work

### Passes, subscriptions and payments

- **Plans** (`subscription_types`) are one-time passes (a number of visits, or
  unlimited entries on the day of purchase) or recurring subscriptions. Each is
  mirrored to a Stripe Product/Price when saved. A plan that has been sold can
  only be deactivated, never deleted, and its billing type is locked.
- **Stripe's webhook is the source of truth** for "this checkout was paid". The
  customer's browser returning to the success page only displays the result; both
  paths run the same idempotent code
  (`App\Support\Payments\CheckoutFulfillment`), so paying and closing the tab
  still activates the pass or reservation.
- A booking's lifecycle `status` (`pending → active → cancelled`) is separate
  from its **`payment_status`** (`unpaid → paid → refund_pending → refunded`, or
  `refund_failed`). Revenue is computed from `payment_status`, so a reservation
  cancelled too late for a refund still counts as earned money.
- **Refunds** are recorded (`refund_pending`) _before_ Stripe is called. If
  Stripe is unreachable the row becomes `refund_failed` and
  `payments:retry-refunds` retries it every 10 minutes. The admin dashboard shows
  "Refunds still owed".
- All Stripe API calls go through `App\Support\Payments\StripeGateway`, which
  tests replace with a fake.
- Subscriptions: Stripe bills them; Cashier mirrors status. The amount actually
  billed is stored per subscription (`subscriptions.price_cents`), so the
  payments ledger doesn't change when a plan is repriced.

### Reservations

A customer books the whole park for a time range and group size; the price is
per person per hour. The slot is held while the reservation is `pending` (30
minutes, matching the Stripe session expiry) and is exclusive once paid.

- Overlap is checked inside a transaction, and a **database trigger** also
  refuses two overlapping `active` reservations, whatever the code does.
- The owner invites friends (verified customers only, found by name). An
  invitation **holds a seat** but grants nothing until the invitee accepts; only
  then do they join the group chat and may enter during the reservation.
- Group chat exists for paid reservations only: readable until a week after the
  reservation, writable until two hours after it ends. This is enforced on the
  routes and on the WebSocket channel.
- A paid reservation can be cancelled before it starts. Cancelling at least
  `cancellation_cutoff_hours` ahead refunds it in full; later, the slot is freed
  but the payment is kept.

### QR entry and live occupancy

- The pass shows a **signed, single-use code that expires after 60 seconds** and
  is refreshed every 15 seconds (`App\Support\EntryToken`). A screenshot is
  useless almost immediately, and the permanent user id never reaches the
  browser.
- Staff pick **Entry** or **Exit** and scan. The whole decision (state check,
  access check, visit decrement, check-in row) is one database transaction, so
  two simultaneous scans can't spend two visits.
- During a private reservation only its owner and accepted participants (and
  staff) may enter; otherwise a valid pass is needed.
- The live headcount is derived from each person's latest check-in event. Anyone
  left "inside" when the park closes is checked out at closing time by
  `checkins:close-stale` (every 15 minutes).

### Accounts

Deleting a user **closes** the account: any Stripe subscription is ended first
(if that fails nothing changes), then the person is anonymised and soft-deleted.
Payments, reservations and check-ins are kept — the database refuses a real
`DELETE` of a user that has history. The last admin, your own account, and users
with upcoming paid reservations can't be closed.

### Chat

A global room plus a private chat per paid reservation, live over Reverb.
Employees moderate the global room inline; admins from the panel; a
reservation's owner moderates their group. Display names are unique, run through
a profanity filter, and name changes are approved by an admin. Posting is
rate-limited, with automatic slow mode when the room gets busy. The word filter
is a deterrent, not a content-safety system.

### Livestream

`/livestream` is public. A Reolink camera's RTSP feed is converted to HLS by
MediaMTX (a compose service) and played with hls.js. See
[Livestream setup](#livestream-setup).

## Testing

```bash
vendor/bin/pest          # PHP tests (~300), in-memory SQLite, Stripe faked
composer lint:check      # Pint formatting
composer types:check     # Larastan / PHPStan
npm run check            # frontend format + lint
npm run types:check      # TypeScript
npm run e2e              # browser tests (Playwright) — builds assets first
composer ci:check        # lint + types + tests, as CI runs them
```

What the suites cover:

- **Feature tests** — the payment lifecycle (webhook-only fulfilment, ownership,
  refund ordering and retry), reservations and invitations, scans, account
  closure, plan rules, security headers, auth including Unicode email domains.
- **Parallel-request tests** (`tests/Feature/ParallelRequestsTest.php`) — real
  PHP processes hitting one SQLite file at the same instant: six simultaneous
  bookings of one slot, two overlapping payments, four scans of a single-visit
  pass, five invitations for the last seat. Each asserts the invariant (one
  winner, no double spend, no overbooking).
- **Browser tests** (`tests/e2e`) — real Chromium against a real server with a
  throwaway database and a fake Stripe payment page (`E2E_MODE`): finish a
  pending reservation and pay, cancel with refund, pay and close the tab (webhook
  path), two customers racing for a slot, QR entry and replay.

First time running the browser tests: `npx playwright install chromium`.

CI (`.github/workflows/tests.yml`) runs all of the above on every push and pull
request. **Deploys only happen after it passes** — see [Deploys](#deploys).

### Testing Stripe locally

The local `.env` should use Stripe **test-mode** keys — nothing can charge a real
card. Webhooks need the Stripe CLI because Stripe can't reach `localhost`:

```bash
brew install stripe/stripe-cli/stripe
stripe login
stripe listen --forward-to localhost:8000/stripe/webhook
```

Put the `whsec_…` it prints into `.env` as `STRIPE_WEBHOOK_SECRET` and restart
`composer dev`. Then, as a customer, check out with `4242 4242 4242 4242` (any
future expiry/CVC); `4000 0000 0000 0002` is a declined card. Create plans
in `/admin` → Subscription Types (a recurring one for subscriptions, a
non-recurring one for one-time passes) — nothing is seeded.

### Testing the QR scanner and time-dependent features

`/staff/scan` needs a camera (`html5-qrcode`), so use a real browser, logged in
as the employee. Pick Entry or Exit before scanning.

Reservations, opening hours and the "typically busy" chart depend on the clock.
Fake it instead of waiting:

```bash
php artisan time:fake "2026-09-17 16:15:00"   # or "+3 hours"
php artisan time:fake                          # show what's faked
php artisan time:fake --clear
```

It affects web and artisan until cleared and is a no-op in production.

## Production

### Architecture

Everything runs in Docker on a self-hosted server, reachable **only** through a
Cloudflare Tunnel — no service publishes a port on the host, so the server's IP
is not a second way in.

```
browser ──► Cloudflare ──► cloudflared ──┬─► app:8000       Laravel (php artisan serve, 4 workers)
                                         ├─► reverb:8080    WebSockets, path /app
                                         └─► mediamtx:8888  HLS, path /live-cam
camera ──RTSP──► mediamtx                (cloudflared joins the compose network `skatepark_default`)
```

SQLite lives at `/var/www/html/storage/app/database.sqlite` inside the
`app_storage` volume (pinned in `docker-compose.yml`, so no configuration can
move it outside the volume). The app container also runs the scheduler
(`schedule:work`): refund retries, stale check-in cleanup, daily DB backup.

To run the stack locally with Docker, publish ports on loopback only:

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml up --build
```

### Deploys

`.github/workflows/deploy.yml` runs on a self-hosted runner on the server and is
triggered by the **`tests` workflow finishing successfully** on `main` — a red
commit never deploys, and an older commit never overwrites a newer one. It:

1. writes `.env` from GitHub secrets,
2. builds the image (the running one is kept as `skatepark-app:previous`),
3. recreates the containers and waits for the app's health check (`GET /up`,
   which only answers once migrations have run),
4. if the new version isn't healthy within 3 minutes, switches back to the
   previous image and fails the run.

Every container start takes a database snapshot **before** migrating; if a
migration fails the snapshot is restored and the container refuses to start, so
the deploy fails its health check and rolls back.

**GitHub Actions secrets** the pipeline needs: `APP_KEY`, `REVERB_APP_ID`,
`REVERB_APP_KEY`, `REVERB_APP_SECRET`, `STRIPE_KEY`, `STRIPE_SECRET`,
`STRIPE_WEBHOOK_SECRET`, `RESEND_API_KEY`, `CAMERA_RTSP_URL`. The server needs
Docker Compose ≥ 2.23.1 (the MediaMTX config is passed as an inline `configs`
entry, so the camera URL exists only in the running container, never in an
image).

### One-time server setup

1. **Stripe webhook** (Developers → Webhooks → endpoint
   `https://www.xn--rull-eva.lv/stripe/webhook`). <a id="stripe-webhook"></a>
   Enable: `checkout.session.completed`,
   `checkout.session.async_payment_succeeded`, `checkout.session.expired`,
   `customer.subscription.created`, `customer.subscription.updated`,
   `customer.subscription.deleted`, `customer.updated`, `customer.deleted`,
   `invoice.payment_action_required`, `invoice.payment_succeeded`. Put its
   signing secret in `STRIPE_WEBHOOK_SECRET`.
2. **Cloudflare Tunnel.** On the server, once the stack has been deployed (the
   compose network must exist):

    ```bash
    ./docker/cloudflared-setup.sh                 # new tunnel: login, create, DNS, config, run
    ./docker/cloudflared-setup.sh --reconfigure   # existing tunnel: rewrite config.yml and restart
    ```

    The site's hostname is `www.xn--rull-eva.lv`; the DNS route and every ingress
    rule use that same name. The tunnel container joins `skatepark_default` and
    reaches services by name.

3. **First admin:** register on the site, then
   `docker compose exec app php artisan user:set-role you@example.com admin`.

### Livestream setup

1. **Find the camera's RTSP URL** (Reolink app → Settings → Network → IP). Use
   the `_sub` substream:
   `rtsp://<user>:<pass>@<camera-ip>:554/h264Preview_01_sub`. Test it in VLC
   (Media → Open Network Stream) first.
2. Add it as the GitHub secret `CAMERA_RTSP_URL`.
3. Make sure the tunnel routes `/live-cam/*` to `mediamtx`
   (`./docker/cloudflared-setup.sh --reconfigure`).
4. Deploy. `https://www.xn--rull-eva.lv/livestream` should show the feed within
   seconds; if it says "Camera feed isn't available right now", check
   `docker logs skatepark-mediamtx-1` — almost always a wrong URL/credentials, or
   the camera and server aren't on the same network.

The stream is public by design: anyone who can open `/live-cam/index.m3u8` can
watch it. The player's disabled context menu is not access control.

## Operations runbook

All commands run in the app container on the server:
`docker compose exec app php artisan …` (project name `skatepark`).

| Situation                                           | What to do                                                                                                                   |
| --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| A refund failed / "Refunds still owed" on dashboard | Automatic retry runs every 10 min. Force it: `payments:retry-refunds`. Persistent failures are in `storage/logs`.            |
| Old cancelled reservations show as `unpaid`         | The migration couldn't tell refunded from kept. `payments:reconcile-legacy` reports what Stripe says; add `--apply` to save. |
| Someone paid but has no pass                        | Check the Stripe webhook deliveries first. `payments:reconcile-legacy --apply` also activates abandoned-but-paid passes.     |
| Promote or demote a user                            | `user:set-role <email> admin\|employee\|user`                                                                                |
| Close a customer's account                          | Admin panel → Users → _Close account_ (ends their subscription, anonymises, keeps history).                                  |
| Someone stuck "already checked in"                  | Cleared automatically after closing time; or `checkins:close-stale`.                                                         |
| Take a manual backup                                | `db:backup --prefix=manual` → `storage/app/backups/`                                                                         |
| Restore a backup                                    | See below.                                                                                                                   |
| A deploy failed                                     | The pipeline already rolled the image back. Read `docker compose logs app`; fix and push.                                    |

### Backups and restore

- A snapshot is taken at every container start before migrating (`pre-migrate-*`,
  last 20 kept) and daily at 04:00 (`daily-*`, last 14 kept), using SQLite's
  online backup (safe in WAL mode).
- **Backups live on the same volume as the database** and don't survive losing
  the disk. Copy `storage/app/backups` off the server regularly.
- Restore (stop writers first):

    ```bash
    docker compose stop app reverb
    docker compose run --rm --no-deps app php artisan db:restore storage/app/backups/<file>.sqlite --force
    docker compose up -d
    ```

    `db:restore` first writes a `before-restore-*` snapshot of the current
    database.

- Rolling back _code_ never reverses migrations. If a migration needs undoing,
  restore the matching `pre-migrate-*` snapshot.

## Project layout

```
app/
  Console/Commands/        db:backup, db:restore, payments:*, checkins:close-stale, user:set-role, time:fake, e2e:seed
  Filament/                Admin panel: resources (users, plans, purchases, reservations, payments, chat), widgets
  Http/Controllers/        Subscriptions, Reservations (+chat), Staff/Scan, Chat, StripeWebhook, Auth, Settings
  Http/Middleware/         SetSecurityHeaders (CSP etc.), SetLocale, role gates
  Models/                  User (account closure), Reservation, Purchase, SubscriptionType, CheckInEvent, …
  Support/
    Payments/              StripeGateway, CheckoutFulfillment, Refunds, ReservationBooking (+ E2E fake)
    EntryToken.php         Signed, expiring, single-use QR codes
    CheckInOccupancy.php   Live headcount and history
database/migrations/       Includes triggers and the `payments` ledger view (SQLite)
docker/                    entrypoint (backup → migrate → serve), cloudflared setup
resources/js/              Inertia pages, components (components/chat/*), i18n (lv/en)
routes/                    web.php, auth.php, channels.php, console.php (schedule), e2e.php (tests only)
tests/                     Feature/, Unit/, e2e/ (Playwright), Support/ (parallel test worker)
```

## Design decisions and known limits

- **SQLite only.** Chosen for operational simplicity on a single server; WAL mode
  and `BEGIN IMMEDIATE` transactions serialise writers, which is what makes the
  concurrency guarantees hold. Several features use SQLite-specific SQL, so
  switching databases is not a config change.
- **Statuses are enforced in the database** with triggers (SQLite can't add CHECK
  constraints to existing tables). Rebuilding a table (a migration that changes a
  foreign key) drops its triggers — recreate them in that migration, as
  `2026_10_06_110000_*` does.
- **The camera stream is public** on purpose (guests may watch).
- **Content filtering is a denylist** and easy to evade; moderation is by people.
- **Cashier's `subscriptions` table** has no unique `(user_id, type)` — it would
  break resubscribing. Double checkout is prevented by a per-user lock and by
  reusing an open session; a duplicate that still slips through is logged as
  `critical` rather than cancelled automatically.
- **Security headers:** a strict CSP for the public site (same-origin scripts
  only); the admin panel gets a looser script policy because Livewire/Alpine need
  it. HSTS is sent in production over HTTPS.
- **No staff photo-ID check** at the scanner: it shows the customer's name only.
