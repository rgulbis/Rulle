# rullē.lv — Skatepark Management System

A web app for running a skatepark: customer accounts, passes and subscriptions
(Stripe), paid group reservations, QR-code entry/exit with a live headcount, a
public livestream, chat, and an admin panel for staff and management.

| Role         | What they can do                                                                                       |
| ------------ | ------------------------------------------------------------------------------------------------------ |
| **Guest**    | Browse the home page and watch the livestream.                                                         |
| **Customer** | Buy passes/subscriptions, book and pay for reservations, invite friends, chat, show an entry QR code.  |
| **Employee** | Scan customers' QR codes at the gate (`/staff/scan`), moderate chat.                                   |
| **Admin**    | Everything an employee can, plus the Filament admin panel at `/admin` (plans, users, payments, stats). |

**Stack:** Laravel 13 · Inertia + React + TypeScript (Vite) · Filament (admin) ·
Laravel Cashier / Stripe · Laravel Reverb (WebSockets) · SQLite · MediaMTX (HLS
livestream) · Docker Compose behind a Cloudflare Tunnel.

> **SQLite only.** The app is written for SQLite and is not portable to MySQL
> or Postgres. Triggers and partial indexes enforce integrity in the database,
> and the statistics and payments ledger use SQLite's dialect (`strftime`,
> `||`). The concurrency guarantees rely on SQLite's single-writer locking
> (`transaction_mode = IMMEDIATE`). The migrations that add triggers abort on
> other drivers instead of silently skipping them.

## Contents

1. [Local development](#1-local-development)
2. [Configuration reference](#2-configuration-reference)
3. [Third-party services](#3-third-party-services) — Stripe, Reverb, Resend, camera, Cloudflare
4. [Production deployment](#4-production-deployment)
5. [Backups and rollback](#5-backups-and-rollback)
6. [Scheduled jobs and artisan commands](#6-scheduled-jobs-and-artisan-commands)
7. [How it works](#7-how-it-works) — payments, concurrency, accounts, check-in, security headers
8. [Testing](#8-testing)
9. [Frontend structure](#9-frontend-structure)

---

## 1. Local development

**Requirements:** PHP 8.3+ (CI and Docker use 8.5) with the `intl`, `pdo_sqlite`,
`bcmath`, `pcntl` and `zip` extensions, Composer 2, Node 22+, npm.

```bash
composer setup   # composer install, .env from .env.example, app key, migrate, npm install + build
composer dev     # app server, queue listener, Reverb and Vite together
```

The app is at <http://localhost:8000>. The default `.env` uses SQLite at
`storage/app/database.sqlite` (created by `composer setup`), the `log` mailer
(mail is written to `storage/logs`) and `BROADCAST_CONNECTION=log`.

For chat and the live headcount to update in real time locally, set
`BROADCAST_CONNECTION=reverb` and fill `REVERB_APP_ID`, `REVERB_APP_KEY` and
`REVERB_APP_SECRET` with any values (see [Reverb](#reverb-websockets)).

### Accounts

```bash
php artisan migrate:fresh --seed     # wipes the local DB, creates the two staff accounts and the starter plans
```

| Role     | Email                      | Password                |
| -------- | -------------------------- | ----------------------- |
| Admin    | `admin@xn--rull-eva.lv`    | `password` (local only) |
| Employee | `employee@xn--rull-eva.lv` | `password` (local only) |

`rullē.lv` is `xn--rull-eva.lv` in punycode; email addresses are stored in the
punycode form. The seeder also creates three starter plans (day pass €8, monthly
€35, yearly €300) if they don't exist yet — it never overwrites a plan you have
edited — and, when Stripe is configured, syncs them to Stripe so they can be
bought (otherwise run `php artisan plans:sync-stripe` later). There is no seeded
customer: register at `/register`. Outside
production, new accounts are auto-verified, so there is no email step to work
around.

`migrate:fresh` and similar destructive commands are blocked in production
(`DB::prohibitDestructiveCommands()`). In production the seeder refuses to run
without `SEED_PASSWORD`.

Promote or demote any existing account:

```bash
php artisan user:set-role someone@example.com admin     # admin | employee | user
```

### Checks

```bash
composer ci:check    # everything CI runs: Pint, Larastan/PHPStan, frontend checks, tsc, Pest
composer lint        # fix formatting (Pint)
npm run check:fix    # fix frontend formatting/lint
```

See [Testing](#8-testing) for what the suites cover.

### Testing things that need time or hardware

- **Time-dependent features** (reservations, opening hours, the busy-times chart):
  `php artisan time:fake "2026-09-17 16:15:00"` fakes the clock for every web and
  artisan call (also `"+3 hours"`); `php artisan time:fake --clear` resets it. It
  does nothing in production.
- **QR scanner:** `/staff/scan` needs camera access, so use a real browser. Open
  the customer dashboard on a phone and the scanner on a laptop webcam. Pick the
  correct Entry/Exit mode before scanning, or the scan is rejected.
- **Stripe:** see [Stripe](#stripe) for test cards and `stripe listen`.

---

## 2. Configuration reference

Everything is configured through `.env` (copy `.env.example`). In production the
deploy workflow writes `.env` from GitHub secrets — see
[Production deployment](#4-production-deployment).

| Variable                                                        | Purpose                                                                                                                                       |
| --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_KEY`                                                       | Laravel key (`php artisan key:generate`). Also the root of the QR-token signing key — rotating it invalidates tokens.                         |
| `APP_URL`                                                       | Public URL; used in links and Stripe return URLs.                                                                                             |
| `DB_DATABASE`                                                   | The one SQLite path, relative to the project root: `storage/app/database.sqlite`. Must match `docker-compose.yml` and `docker/entrypoint.sh`. |
| `SESSION_DRIVER` / `CACHE_STORE`                                | `file` on purpose: keeps session and cache writes out of the SQLite write lock.                                                               |
| `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`          | Stripe keys and webhook signing secret. Not in `.env.example`; add them yourself. `CASHIER_CURRENCY=eur`.                                     |
| `BROADCAST_CONNECTION`                                          | `log` locally without Reverb, `reverb` otherwise.                                                                                             |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`          | Reverb credentials (any values locally).                                                                                                      |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`                   | Where the **server** reaches Reverb (in Docker: `reverb`, `8080`, `http`).                                                                    |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | Where the **browser** connects (in production `443` / `https`). Baked into the JS at build time.                                              |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `RESEND_API_KEY`            | Outgoing mail. `log` locally, `resend` in production.                                                                                         |
| `CAMERA_RTSP_URL`                                               | Camera RTSP URL including credentials. Blank locally; a secret in production.                                                                 |
| `CHECKIN_TOKEN_TTL_SECONDS`                                     | Lifetime of the entry QR token (default `60`).                                                                                                |
| `CHECKIN_MIN_STAY_SECONDS`                                      | An exit scan this soon after the entry scan is refused as a double scan (default `10`, `0` turns it off).                                     |
| `CHECKIN_MAX_VISIT_MINUTES`                                     | Longest plausible visit before a rider counts as gone (default `720`).                                                                        |
| `SEED_PASSWORD`                                                 | Password for the seeded admin/employee accounts. **Required** to seed in production.                                                          |
| `PHP_CLI_SERVER_WORKERS`                                        | Worker processes for `php artisan serve` in the container (default `4`).                                                                      |

`VITE_*` values are compiled into the frontend, so changing them needs a rebuild
(`npm run build`, or a new Docker build).

---

## 3. Third-party services

### Stripe

Uses Stripe Checkout through Laravel Cashier. Local `.env` should hold **test
mode** keys (`pk_test_…`, `sk_test_…`); nothing then can charge a real card.

**The webhook is what fulfils a payment.** The success page the customer lands on
only displays the result, so a customer who pays and closes the tab still gets
their pass or reservation. Create a webhook endpoint in the Stripe dashboard
pointing at `https://<your-host>/stripe/webhook` and subscribe it to:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.expired`
- the `customer.subscription.*` events Cashier uses (`created`, `updated`, `deleted`)
  and `customer.updated` / `customer.deleted`

Copy the endpoint's signing secret (`whsec_…`) into `STRIPE_WEBHOOK_SECRET`.

**Locally**, Stripe can't reach `localhost`, so forward events:

```bash
brew install stripe/stripe-cli/stripe
stripe login
stripe listen --forward-to localhost:8000/stripe/webhook
```

`stripe listen` prints a `whsec_…` secret: put it in `.env` as
`STRIPE_WEBHOOK_SECRET` and restart `composer dev`. Keep `stripe listen` running
while you test.

Test cards: `4242 4242 4242 4242` succeeds, `4000 0000 0000 0002` is declined
(any future expiry, any CVC, any postcode). Log in as a customer and check out at
`/subscriptions`.

**Plans.** Admins manage plans under `/admin` → Subscription Types. Saving a plan
never calls Stripe; `PlanStripeSync` creates the Stripe Product and Price from
the "Sync to Stripe" action and every 15 minutes (`plans:sync-stripe`). A plan
that isn't synced can't be bought. Renaming a plan only renames the Product; a new
Price is created only when the amount or billing interval changes. A plan with
purchases or subscribers can't be deleted (deactivate it) and its billing type is
locked. Prices must be at least €0.50, and a one-time plan needs a visit limit of
at least 1 unless it is unlimited.

**Refunds** are recorded before Stripe is called. Anything Stripe doesn't
confirm is retried every 15 minutes (`payments:retry-refunds`) with idempotency
keys, and shows as `refund_failed` until it succeeds.

### Reverb (WebSockets)

Reverb powers the live chat and headcount. Locally it runs as part of
`composer dev`. In production it is its own container (`reverb`), and the browser
reaches it on `wss://<host>/app/…` through the Cloudflare Tunnel.

1. Pick any `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (random strings).
2. Server-side settings (`REVERB_HOST=reverb`, `REVERB_PORT=8080`,
   `REVERB_SCHEME=http`) point at the container over the Docker network.
3. Browser-side settings (`VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`) are
   compiled into the JS. The deploy workflow sets both pairs.

### Resend (email)

Registration verification and password reset mail goes through
[Resend](https://resend.com).

1. Create a Resend account, add and verify your sending domain, and create an API key.
2. In production set `MAIL_MAILER=resend`, `RESEND_API_KEY=<key>` and
   `MAIL_FROM_ADDRESS` to an address on the verified domain.
3. Locally keep `MAIL_MAILER=log`; mails are written to `storage/logs/laravel.log`.

Customers must verify their email before they can buy, book or chat. In
production verification is required; outside it new accounts are auto-verified.

### Camera and MediaMTX (livestream)

`/livestream` is public and plays an HLS feed from a Reolink camera on the same
network as the production server:

```
Reolink camera --RTSP--> MediaMTX (compose service) --HLS--> Cloudflare Tunnel --> browser (hls.js)
```

MediaMTX does the RTSP→HLS conversion; the app never handles the video. Its
config is inline in `docker-compose.yml` (`configs.content`) and the camera URL is
substituted from `CAMERA_RTSP_URL` at runtime, so the camera's credentials are
**never baked into an image**.

One-time setup:

1. **Find the RTSP URL.** In the Reolink app: Settings → Network → IP address. Use
   `rtsp://<user>:<pass>@<camera-ip>:554/h264Preview_01_sub` (the lighter
   substream is plenty for a web embed). Test it in VLC first (Media → Open
   Network Stream); if VLC can't play it, nothing downstream will.
2. **Store it** as the GitHub Actions secret `CAMERA_RTSP_URL`.
3. **Deploy.** The workflow writes it into the server's `.env` and starts `mediamtx`.
4. **Check** `https://www.xn--rull-eva.lv/livestream`. If it says "Camera feed isn't
   available right now", read `docker logs skatepark-mediamtx-1` on the server —
   it is almost always a wrong URL/credentials, or the camera and server aren't on
   the same network.

**The stream is public by design.** Anyone with `https://<site>/live-cam/index.m3u8`
can watch it, exactly like a guest on `/livestream`. `/live-cam/*` goes from
Cloudflare straight to MediaMTX and never touches Laravel, so app middleware
doesn't apply. The feed is not recorded; only the last few seconds of segments
exist. Hiding the right-click menu in the player is cosmetic, not protection.

### Cloudflare Tunnel

The server publishes **no ports**. All traffic enters through a Cloudflare Tunnel
container (`cloudflared`) that joins the compose network (`skatepark_default`)
and talks to services by name:

| Path            | Service         |
| --------------- | --------------- |
| `/app/*`        | `reverb:8080`   |
| `/live-cam/*`   | `mediamtx:8888` |
| everything else | `app:8000`      |

The site is served from `www.xn--rull-eva.lv`; the DNS record and every ingress
rule use that host.

On the server, after the stack has been deployed once (the compose network must exist):

```bash
./docker/cloudflared-setup.sh                 # first time: log in, create tunnel, DNS record, run container
./docker/cloudflared-setup.sh --reconfigure   # keep tunnel and DNS; rewrite config.yml, recreate the container
```

The container runs as root (`--user 0:0`); the current cloudflared image otherwise
can't read the credentials directory and Cloudflare shows error 1033.

---

## 4. Production deployment

The app runs in Docker on a self-hosted server with a self-hosted GitHub Actions
runner. Compose services: `app` (PHP server), `reverb`, `scheduler`
(`schedule:work`), `mediamtx`. Requires **Docker Compose ≥ 2.23.1** (inline
config).

### What a deploy does

1. Push to `main`. The `tests` workflow runs.
2. **Only if `tests` succeeded**, `.github/workflows/deploy.yml` runs
   (`workflow_run` trigger — the tests workflow must stay named `tests`). It
   deploys the exact commit that was tested and skips itself if `main` has moved on.
3. It writes `.env` from GitHub secrets, then runs `docker/deploy.sh`:
   tag the live image as `skatepark-app:rollback` → build → snapshot the database
   (`pre-deploy`) → `docker compose up --wait` (waits for health checks: `/up` for the app).
4. On container start the entrypoint runs `php artisan db:migrate-safe`: pending
   migrations are preceded by a `pre-migrate` snapshot, and a failing migration
   puts the snapshot back and stops the container.
5. If the stack isn't healthy in time, the previous image is started again and the
   workflow fails.

### GitHub configuration

**Secrets:** `APP_KEY`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`,
`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `RESEND_API_KEY`,
`CAMERA_RTSP_URL`, `SEED_PASSWORD`.
**Variable (optional):** `FRESH_DB_ON_DEPLOY` — see below.

Everything else (`APP_URL`, `APP_ENV=production`, Reverb/Vite ports, mailer, …) is
set by the workflow itself.

### First-time server setup

1. Install Docker (Compose ≥ 2.23.1) and register the self-hosted runner.
2. Add the secrets above, push to `main`, and let the first deploy finish.
3. Run `./docker/cloudflared-setup.sh` on the server.
4. Create the Stripe webhook endpoint and put its secret in `STRIPE_WEBHOOK_SECRET`
   ([Stripe](#stripe)).
5. Check the result (read-only; exits non-zero on any FAIL):

```bash
./docker/server-check.sh
```

It verifies that all services are healthy, no host ports are published, the tunnel
is on the compose network and uses `www`, the database is at the pinned path, a
backup exists and the site answers.

When deploying this version over an existing database, also run the
[legacy payments reconciliation](#one-off-reconcile-payments-from-before-payment-tracking).

### The admin account in production

The database starts empty. The seeder creates `admin@xn--rull-eva.lv` and
`employee@xn--rull-eva.lv` with the password from the `SEED_PASSWORD` secret. To
seed a database that already has the schema:

```bash
docker compose exec app php artisan db:seed --force
```

To give an existing customer a role instead:
`docker compose exec app php artisan user:set-role someone@example.com admin`.

Change the seeded password after the first login.

### Pre-launch: wipe the database on every deploy

While the project is still being built, a repository **variable**
`FRESH_DB_ON_DEPLOY=true` makes every deploy snapshot the database (`pre-fresh`),
stop the app, run `migrate:fresh --seed` and start the new version (the snapshot
is restored if the wipe fails). It needs the `SEED_PASSWORD` secret and runs once
per deploy, not on container restarts. Stripe-side data isn't touched.

> **Delete the variable before launch.** While it exists, every push to `main`
> deletes all users, bookings and passes.

### Starting over on the same server

Run on the server itself (not in the runner):

```bash
./docker/server-cleanup.sh              # stops containers, archives data volumes to ~/skatepark-backups, removes old containers/images
./docker/server-cleanup.sh --wipe-data  # also deletes the database and uploads (typed confirmation; archives stay)
```

Then re-run the Deploy workflow, `./docker/cloudflared-setup.sh --reconfigure`, and
`./docker/server-check.sh`.

---

## 5. Backups and rollback

The database is `storage/app/database.sqlite` inside the `app_storage` volume.
Snapshots go to `storage/app/backups`, a separate `app_backups` volume.

| When                                | Label         | Kept      |
| ----------------------------------- | ------------- | --------- |
| Every 6 hours (`scheduler` service) | none          | newest 28 |
| Before each deploy                  | `pre-deploy`  | newest 10 |
| Before pending migrations           | `pre-migrate` | newest 10 |
| Before a fresh-database deploy      | `pre-fresh`   | newest 10 |
| Before any restore                  | `pre-restore` | newest 14 |

```bash
docker compose exec app php artisan db:backup      # manual snapshot
docker compose exec app php artisan db:restore     # list snapshots
```

**Restore** replaces the file on disk, so stop everything that has it open first:

```bash
docker compose stop app reverb scheduler
docker compose run --rm --no-deps --entrypoint php app artisan db:restore latest   # or a file name from the list
docker compose up -d
```

**Automatic rollback** covers a failed migration (snapshot restored) and an
unhealthy new version (previous image restarted). If the migrations succeeded and
the app broke afterwards, the old image is running on the _new_ schema: restore the
`pre-deploy` snapshot the workflow printed, as above.

**Off-site copies.** The backups live on the same machine as the database. Copy them
off the server regularly (run on the server):

```bash
docker run --rm -v skatepark_app_backups:/b -v "$PWD":/out alpine tar czf /out/skatepark-backups.tgz -C /b .
```

---

## 6. Scheduled jobs and artisan commands

The `scheduler` container runs `schedule:work`; locally run `php artisan schedule:work`
to get the same jobs. Every job is safe to repeat.

| Command                         | Schedule         | What it does                                                                                            |
| ------------------------------- | ---------------- | ------------------------------------------------------------------------------------------------------- |
| `db:backup --keep=28`           | every 6 hours    | Snapshots the SQLite database.                                                                          |
| `payments:retry-refunds`        | every 15 minutes | Retries refunds Stripe didn't confirm.                                                                  |
| `plans:sync-stripe`             | every 15 minutes | Syncs plans whose Stripe Product/Price is out of date.                                                  |
| `checkins:close-stale`          | every 5 minutes  | Writes the missing check-out for anyone still inside after closing time or `CHECKIN_MAX_VISIT_MINUTES`. |
| `model:prune` (spent QR tokens) | daily            | Deletes expired single-use token records.                                                               |

Manual commands: `db:backup`, `db:restore`, `db:migrate-safe` (used by the entrypoint),
`db:fresh-deploy` (used by `deploy.sh`), `user:set-role`, `time:fake`, and the one-off
`payments:reconcile-legacy` below.

### One-off: reconcile payments from before payment tracking

Reservations and passes created before the `payment_status` columns existed
(`2026_10_07_090000_add_payment_state_…`) were backfilled conservatively: anything
confirmed paid became `paid`, but a **cancelled** reservation became `unpaid`, because
the old schema couldn't say whether its money had been refunded or kept. Passes whose
customer paid and never came back to the site were left `pending` or `abandoned`.
`payments:reconcile-legacy` asks Stripe what really happened to each such row's
Checkout session and fixes the state.

```bash
docker compose exec app php artisan db:backup                              # always snapshot first
docker compose exec app php artisan payments:reconcile-legacy              # dry run: a table of what it found
docker compose exec app php artisan payments:reconcile-legacy --apply      # write the changes
docker compose exec app php artisan payments:reconcile-legacy --apply --refund-owed
```

What it does, per row:

| Row                                        | Stripe says                                 | Result                                                                                 |
| ------------------------------------------ | ------------------------------------------- | -------------------------------------------------------------------------------------- |
| Pass `pending`/`abandoned`                 | paid                                        | Activated (the same code the webhook uses).                                            |
| Reservation `pending`                      | paid                                        | Activated, or refunded if its slot has since been taken.                               |
| Reservation `cancelled`, recorded `unpaid` | fully / partly refunded                     | `refunded` / `partially_refunded`, with the amount.                                    |
| Reservation `cancelled`, recorded `unpaid` | paid, nothing refunded, cancelled **late**  | `paid` — the money was kept, so revenue now counts it.                                 |
| Reservation `cancelled`, recorded `unpaid` | paid, nothing refunded, cancelled **early** | Left alone and flagged: a refund was owed. `--apply --refund-owed` refunds it in full. |
| Any of the above                           | never paid / unknown session                | Left as it is.                                                                         |

Notes:

- Without `--apply` nothing is written. It is safe to run repeatedly: a settled row no
  longer matches. Rows touched in the last 30 minutes are skipped (a checkout may still
  be open). A Stripe error on one row is reported and the rest carry on; the command
  then exits non-zero.
- "Early" or "late" is judged with the current `cancellation_cutoff_hours` against the
  row's `updated_at`, the nearest thing to a cancellation time the old schema kept. That
  is why owed refunds need an explicit `--refund-owed` rather than happening on `--apply`.
- It is not scheduled. Run it once after the deploy that introduced payment tracking,
  and again only if old rows turn up.

---

## 7. How it works

### Payments

- **The webhook is the source of truth** for fulfilling purchases and reservations
  (`App\Support\Payments\CheckoutFulfillment`). The success URLs verify that the
  Checkout Session belongs to the logged-in user and show the outcome; they do not
  decide it. `checkout.session.expired` clears abandoned checkouts.
- Payment state is separate from lifecycle state: `payment_status` (unpaid, paid,
  refunded, partially refunded, `refund_failed`) and `refunded_cents` live next to
  the reservation/purchase `status`. Revenue statistics count what was paid minus
  what was refunded, so a late cancellation without refund still counts as revenue.
- Every state-changing action is POST/PUT/PATCH/DELETE. The GET routes Stripe
  redirects to (`*.checkout-cancelled`, `*.success`) change nothing.
- A subscription records the amount actually billed (`subscriptions.price_cents`),
  so the Payments ledger doesn't change when a plan's price does.

### Concurrency

- SQLite runs with `transaction_mode = IMMEDIATE`, so a check followed by a write
  inside `DB::transaction` can't be interleaved with another request. **Never call
  Stripe inside a transaction** — the write lock is held until it ends.
- Two paid reservations can't overlap: checked in a transaction at booking and at
  payment, and enforced by `reservations_no_active_overlap_*` triggers.
- A scan (state check, visit spent, event written) is one transaction.
- One live subscription per customer: a per-user lock and reuse of the open
  Stripe session at checkout, a check in the `customer.subscription.created`
  webhook (a duplicate is cancelled at Stripe and logged `critical`; its first
  payment then needs a manual refund), and the partial unique index
  `subscriptions_one_live_per_user_type`.
- Posting in the global chat takes a per-user lock; both chats are rate limited
  (`chat-send`, 20 messages/minute/user).

### Database integrity

- **Foreign keys never cascade.** Everything pointing at a user, reservation or plan
  is `ON DELETE RESTRICT`, including Cashier's tables.
- **Status columns only hold known values**, via triggers that abort the write with
  `invalid_<table>_<column>`. A new status needs a migration that updates the
  trigger (see `2026_10_09_090000_harden_data_integrity.php`).
- `users.name` and `users.pending_name` are unique, closed accounts included.

### Closing an account

"Delete" on a user in the admin panel _closes_ the account
(`App\Support\Accounts\AccountClosure`): the Stripe subscription is cancelled first
(if Stripe refuses, nothing changes), then the row is soft-deleted and its name,
email, password, QR secret and card details are replaced with placeholders. Purchases,
reservations, check-ins and payments are kept and show as "Deleted user N"; chat
messages and participant links are removed. An account with an upcoming paid
reservation can't be closed, an admin can't close themselves or the last admin,
and there is no bulk delete.

### Reservations, participants and group chat

- Adding someone sends an **invitation** (`invited` → `accepted` / `declined`). Only
  the owner of a paid, not-yet-ended reservation can invite, and only verified
  customers. Only accepted participants count toward the paid group size, can enter
  with the group and see the group chat; capacity is rechecked under the write lock
  when the invitation is accepted. A declined invitation stays on record so the owner
  can't re-invite.
- The group chat exists while the reservation is paid for: writable until it ends,
  read-only for 7 days after, then closed. The WebSocket channel authorisation
  (`routes/channels.php`) uses the same rule as the page.
- Customer search for invitations matches display names only, treats `%` and `_`
  literally, is throttled and returns just id and name.
- `App\Rules\NoInappropriateContent` is a short word list. It stops casual abuse and
  nothing more; it is not content safety. Mute/delete tools and admin review of
  requested names are the real moderation.

### Entry QR and check-in

The QR on a customer's dashboard is not a fixed ID but a **signed, single-use token**
(`App\Support\CheckIn\QrToken`) that expires after 60 s (`CHECKIN_TOKEN_TTL_SECONDS`).
The dashboard fetches a new one from `/dashboard/qr-token` before the old one runs
out, so a screenshot is useless. A scan that is refused (wrong mode, no pass) doesn't
spend the token. The per-user `qr_code` column is only the secret the tokens are signed
with and is never sent to a page; closing an account rotates it.

Someone who leaves without scanning out would otherwise count as inside forever.
`checkins:close-stale` writes the missing check-out dated to the moment they should
have left, and the live headcount ignores check-ins older than `CHECKIN_MAX_VISIT_MINUTES`
even before the job has run. After a deploy, run it once by hand to clear old check-ins.

### Email addresses

Emails are normalised (Unicode domains → punycode) at registration, login and
password reset, so the same address is always stored and looked up in one form. This
needs the PHP `intl` extension (required in `composer.json`).

### Security headers

`App\Http\Middleware\SetSecurityHeaders` runs on every web response, including the
Filament panel:

| Header                      | Value                                                                              |
| --------------------------- | ---------------------------------------------------------------------------------- |
| `Content-Security-Policy`   | strict for the public site, looser for `/admin` (below)                            |
| `X-Content-Type-Options`    | `nosniff`                                                                          |
| `Referrer-Policy`           | `strict-origin-when-cross-origin`                                                  |
| `Permissions-Policy`        | camera for this site only (the scanner); microphone, geolocation, payment, USB off |
| `X-Frame-Options`           | `SAMEORIGIN`                                                                       |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` — production over HTTPS only                 |

The public CSP is `default-src 'self'` with scripts only from this origin or carrying
a per-request nonce, and `object-src 'none'`. Two features need exceptions, and
changing either means revisiting the policy:

- **Reverb** — `connect-src` allows the WebSocket to this host, built from
  `VITE_REVERB_PORT`/`VITE_REVERB_SCHEME` (`config/security.php`). If `echo.ts` ever
  points at another host, add it to `reverbSources()`.
- **Livestream (HLS)** — playlists and segments load same-origin from `/live-cam/`;
  hls.js needs `blob:` for `media-src` and `worker-src`.

`style-src` keeps `'unsafe-inline'` (the `@fonts` directive writes an inline style
without nonce support). The `/admin` CSP adds `unsafe-inline`/`unsafe-eval` for scripts
because Filament's Alpine build and Livewire need them, and allows `ui-avatars.com`
images. With `npm run dev` running, the Vite dev server is allowed too (derived from
`public/hot`, never in production). When something is blocked, the browser console
names the directive and URL — start there.

---

## 8. Testing

```bash
./vendor/bin/pest                       # unit + feature tests
./vendor/bin/pest --testsuite=Parallel  # real multi-process race tests (~20 s)
composer ci:check                       # the full CI run
```

- `tests/Feature`, `tests/Unit` — behaviour tests, including payment lifecycles
  (webhook-only fulfilment, foreign session IDs, refunds), account closure, and
  chat access over a reservation's lifetime, and the legacy reconciliation command.
  Stripe is replaced by `tests/Support/FakeStripeGateway` (`fakeStripe()` in tests).
- `tests/Parallel` — starts several PHP processes against one on-disk SQLite file and
  releases them at the same instant to prove that double bookings, double scans,
  over-full groups, duplicate checkouts and chat-cooldown bypasses can't happen.
- CI (`.github/workflows/tests.yml`) runs `composer setup` and `composer ci:check`.
  A red run blocks the deploy.

---

## 9. Frontend structure

Inertia pages live in `resources/js/pages/`; they stay thin and compose components.
Anything big is split by responsibility, with the behaviour in hooks and the markup in
small components:

- **Chat** — `components/chat-thread.tsx` is the one chat room (global chat and each
  reservation's group chat) and only wires pieces together. Its parts are in
  `components/chat/`:

    | File                                                                                | Responsibility                                                                                                  |
    | ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
    | `hooks.ts`                                                                          | `useChatChannel` (websocket events), `useSlowMode`, `useStickyScroll`, `useMessageJump`, `useModerationActions` |
    | `header.tsx`, `pinned-panel.tsx`, `muted-users-panel.tsx`, `custom-mute-dialog.tsx` | Header and the moderation panels/dialog                                                                         |
    | `message-list.tsx`, `message-row.tsx`                                               | Day/author grouping and one message with its actions                                                            |
    | `composer.tsx`                                                                      | Message box, slow-mode banner, muted/read-only states                                                           |
    | `types.ts`, `avatar.tsx`, `icons.tsx`                                               | Shared types, avatar, icons                                                                                     |

- **Reservations** — `pages/reservations/index.tsx` composes `components/reservations/`:
  `booking-section.tsx` (form + calendar of taken slots), `invitations-section.tsx`,
  `my-reservations-section.tsx` (one card per reservation), `add-participant.tsx`.
  The day timeline (`components/reservation-timeline.tsx`) keeps the selection logic and
  delegates to `timeline-chart.tsx` (SVG), `timeline-selects.tsx` (start/duration lists)
  and `time.ts` (minute/time helpers).

Checks: `npm run check` (format + lint), `npm run types:check` (tsc), `npm run build`.
