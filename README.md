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

`tests/Parallel` is different from the rest: it starts several PHP processes
against one on-disk SQLite file and releases them at the same instant, to prove
that double bookings, double scans, over-full groups, duplicate checkouts and
chat-cooldown bypasses can't happen. It takes ~20 s.

### How concurrent requests are kept safe

- SQLite runs with `transaction_mode = IMMEDIATE` (`config/database.php`): a
  transaction takes the write lock at `BEGIN`, so a check followed by a write
  inside `DB::transaction` can't be interleaved with another request's. Never
  call Stripe inside a transaction — the lock is held until it ends.
- Two paid reservations can't overlap: checked in a transaction at booking and
  at payment, and enforced by the `reservations_no_active_overlap_*` triggers.
- A scan (state check, visit spent, event written) is one transaction.
- One live subscription per customer: a per-user lock and reuse of the open
  Stripe session at checkout, a check in the `customer.subscription.created`
  webhook (a duplicate is cancelled at Stripe and logged as `critical` — its
  first payment must then be refunded by hand), and the partial unique index
  `subscriptions_one_live_per_user_type`.
- Posting in the global chat takes a per-user lock; both chats are rate-limited
  (`chat-send`, 20 messages/minute/user).

### Participants and group chat

- Adding someone to a reservation sends an **invitation** (`reservation_user.status`:
  `invited` → `accepted` / `declined`). Only the owner of a paid, not yet ended
  reservation can invite, and only a customer with a verified email. Only
  **accepted** participants count towards the paid group size, enter with the
  group, and see the group chat; the seat is checked again, with the write lock
  held, when the invitation is accepted. A declined invitation stays on record, so
  the owner can't keep re-inviting the same person.
- A reservation's group chat exists while the reservation is paid for (not
  `pending`, not `cancelled`). It can be written to until the reservation ends,
  stays **read-only for 7 days** after that, and is closed after. The websocket
  channel authorisation (`routes/channels.php`) uses the same rule as the page
  (`Reservation::chatIsReadable()` / `chatIsWritable()`).
- The customer search used to invite people matches display names only, treats
  `%` and `_` literally, is throttled (`user-search`) and returns just id and name.
- `App\Rules\NoInappropriateContent` is a short word list plus one pattern. It
  stops casual, obvious abuse and nothing more: it is not content safety, and
  anything it misses is for the mute/delete tools and admin review of requested
  names to catch.

### Database: SQLite only

The application is written for SQLite and is **not** portable to MySQL or
Postgres as it stands. This is a decision, not an accident: the integrity rules
below live in the database itself, and the check-in statistics and the payments
ledger use SQLite's dialect (`strftime`, `||`). Migrating the migrations would be
the smaller part; the real cost is re-proving the concurrency guarantees, which
rely on SQLite's single-writer locking (`transaction_mode = IMMEDIATE`, WAL).
The migrations that add triggers or partial indexes abort on any other driver
rather than silently skipping them.

What the database enforces, whatever the application does:

- **Foreign keys never cascade.** Everything pointing at a user, reservation or
  plan is `ON DELETE RESTRICT`, including Cashier's `subscriptions` and
  `subscription_items`. History can't disappear because a parent row went.
- **Status columns only hold known values** (`users.role`, `purchases.status`,
  `reservations.status`, `reservation_user.status`, both `payment_status`
  columns, `subscription_types.billing_interval`), by triggers that abort the write
  with `invalid_<table>_<column>`. A new status therefore needs a migration that
  updates the trigger (see `2026_10_09_090000_harden_data_integrity.php`).
  Stripe's own `subscriptions.stripe_status` is left open on purpose.
- **`users.name` and `users.pending_name` are unique**, closed accounts
  included.
- No two paid reservations overlap, and no customer has two live subscriptions
  (see above).

### Closing an account

"Delete" on a user in the admin panel _closes_ the account (`App\Support\Accounts\AccountClosure`):
their Stripe subscription is cancelled first (if Stripe refuses, nothing is
changed), then the row is soft-deleted and its name, email, password, QR code secret and
card details are replaced with placeholders, so they can't sign in, can't be
found, and their email can be registered again. Purchases, reservations,
check-ins and payments are kept and show as "Deleted user N". Their chat
messages and participant links are removed.

An account can't be closed while it has an upcoming paid reservation, and an
admin can't close their own account or the last admin. There is no bulk delete.

### Plans and Stripe

Saving a plan never calls Stripe. `App\Support\Payments\PlanStripeSync` creates
or updates the Stripe Product and Price: right after an admin saves, from the
"Sync to Stripe" action, and every 15 minutes for anything out of step
(`php artisan plans:sync-stripe`, run by the scheduler). Renaming a plan only
renames its Product; a new Price is made only when the amount or billing
interval changes. A plan that is not synced can't be bought.

A plan can't be deleted once it has purchases or subscribers (deactivate it
instead), and its billing type is locked once it has sales. Prices are at least
€0.50, a one-time plan needs a visit limit of at least 1 unless it is unlimited,
and reservation pricing must keep even the smallest booking above €0.50.

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

The webhook is what actually fulfils a payment (the success page only shows
the result), so the Stripe endpoint — in production too — must be subscribed to
`checkout.session.completed`, `checkout.session.async_payment_succeeded` and
`checkout.session.expired`, plus the `customer.subscription.*` events Cashier
uses. Refunds are recorded before Stripe is called; anything Stripe doesn't
confirm is retried every 15 minutes by `php artisan payments:retry-refunds`
(run by the scheduler).

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

The code on a customer's dashboard is not a fixed ID: it's a signed token
(`App\Support\CheckIn\QrToken`) that expires after 60 seconds
(`CHECKIN_TOKEN_TTL_SECONDS`) and is spent by one successful scan, so a
screenshot is useless. The dashboard fetches a new one from
`/dashboard/qr-token` before the old one runs out. A scan that is refused (wrong
mode, no pass) doesn't spend it. The per-user `qr_code` column is only the secret
the tokens are signed with and is never sent to a page; closing an account rotates
it, which invalidates that account's tokens. To test on one screen, the dashboard
on a phone and `/staff/scan` on a laptop webcam is the easiest setup.

### Check-outs nobody scanned

Someone who leaves without scanning out would count as inside forever. Two
things stop that:

- `php artisan checkins:close-stale` (scheduled every five minutes, see
  `routes/console.php`) writes the missing check-out for anyone still inside
  after the park's closing time (Reservation settings) or after
  `CHECKIN_MAX_VISIT_MINUTES` (default 720), whichever came first. It dates
  the check-out to that moment, not to when the job ran, so the occupancy chart
  is the same as if it had run exactly at closing, and it catches up if the
  scheduler was down. Run it once by hand after deploying to clear old
  check-ins that never got a check-out.
- The live headcount and a rider's own "checked in" status ignore a check-in
  older than the maximum visit length, even before the job has run.

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
Cloudflare Tunnel — `docker-compose.yml` publishes no ports, and the
`cloudflared` container joins the compose network (`skatepark_default`) and
reaches `app:8000`, `reverb:8080` and `mediamtx:8888` by name. See
`docker/cloudflared-setup.sh`.

```bash
ssh -p 2222 <name>@<serverIp>
```

### How a deploy works

1. Push to `main`. The `tests` workflow runs.
2. Only if `tests` passed, `.github/workflows/deploy.yml` runs on the
   self-hosted runner (`workflow_run` trigger — the tests workflow must keep
   the name `tests`). It deploys the exact commit that was tested and skips
   itself if `main` has already moved on.
3. `docker/deploy.sh` builds the image, snapshots the database
   (`pre-deploy`), starts the stack with `docker compose up --wait` and waits
   for the health checks (`/up` for the app).
4. On startup the entrypoint runs `php artisan db:migrate-safe`: if there are
   pending migrations it snapshots first (`pre-migrate`), and if one fails it
   puts the snapshot back and the container stops.
5. If the stack isn't healthy in time, the previous image (kept as
   `skatepark-app:rollback`) is started again and the workflow fails.

Needs Docker Compose ≥ 2.23.1 on the server (inline MediaMTX config).

**One-time switch when first deploying this setup:** the old tunnel config
points at host ports (`host.docker.internal:8080` etc.) that no longer exist.
_Before_ pushing, run `./docker/cloudflared-setup.sh --reconfigure` on the
server — it points the tunnel at the service names, which already resolve to
the running containers, so there is no gap.

### Backups and rollback

The SQLite file is `storage/app/database.sqlite` (inside the `app_storage`
volume — the same path in `.env.example`, `docker-compose.yml` and
`docker/entrypoint.sh`). Snapshots go to `storage/app/backups`, a separate
`app_backups` volume.

| When                                | Label         | Kept      |
| ----------------------------------- | ------------- | --------- |
| Every 6 hours (`scheduler` service) | none          | newest 28 |
| Before each deploy                  | `pre-deploy`  | newest 10 |
| Before pending migrations           | `pre-migrate` | newest 10 |
| Before any restore                  | `pre-restore` | newest 14 |

```bash
docker compose exec app php artisan db:backup                # manual snapshot
docker compose exec app php artisan db:restore               # list snapshots
```

Restoring replaces the file on disk, so stop everything that has it open
first:

```bash
docker compose stop app reverb scheduler
docker compose run --rm --no-deps --entrypoint php app artisan db:restore latest   # or a file name from the list
docker compose up -d
```

If a deploy rolled back after the migrations had already succeeded, the old
image is running on the new schema — restore the `pre-deploy` snapshot the
workflow printed as above.

The backups volume is on the same machine as the database, so **copy it off
the server now and then** (run on the server itself, not in the runner):

```bash
docker run --rm -v skatepark_app_backups:/b -v "$PWD":/out alpine tar czf /out/skatepark-backups.tgz -C /b .
```

### Starting over on the same server

To clear the old containers, images and build cache and rebuild the stack
cleanly (run on the server itself, not in the runner):

```bash
./docker/server-cleanup.sh              # keeps the data volumes
```

It lists what exists, stops the containers, archives the data volumes to
`~/skatepark-backups/` (and verifies the archives) and only then removes
containers and old images. Add `--wipe-data` to also delete the database and
uploads (typed confirmation; the archives stay). It leaves the runner,
`~/.cloudflared` and the network alone.

Then: re-run the **Deploy** workflow on GitHub, run
`./docker/cloudflared-setup.sh --reconfigure`, and finish with

```bash
./docker/server-check.sh                # read-only audit, exits non-zero on any FAIL
```

which checks that all four services are healthy, no host ports are
published, the tunnel is on the compose network and uses `www`, the database
is at the pinned path, a backup exists, and the site answers.

### Pre-launch: wipe the database on every deploy

While the project is still being built you can have **every deploy start from
an empty database**, and turn that off once real customers exist.

- **On:** GitHub → repo → Settings → Secrets and variables → Actions →
  **Variables** → new variable `FRESH_DB_ON_DEPLOY` = `true`.
- **Off:** delete the variable (or set it to anything but `true`). Deploys go
  back to the normal `db:migrate-safe`, which keeps your data.

It takes effect on the next deploy; no commit needed. It also needs the
Actions **secret** `SEED_PASSWORD` — the password the seeded
`admin@xn--rull-eva.lv` and `employee@xn--rull-eva.lv` accounts get on the
server (the local `password` is never used in production, and the wipe refuses
to run without it).

What a deploy does while it is on (`docker/deploy.sh` → `php artisan
db:fresh-deploy`): snapshot the database (`pre-fresh`, last 10 kept), stop the
app, run `migrate:fresh --seed`, start the new version. If the wipe fails the
snapshot is put back and the old version keeps running. It runs **once per
deploy**, not on container restarts or reboots. Uploaded files and Stripe data
are not touched, so Stripe-side customers/subscriptions outlive a wipe.

> **Turn it off before launch.** While it is on, every push to `main` deletes
> all users, bookings and passes (the snapshots are the only way back).

### Refresh db on prod

Wipes all data. Take a snapshot first (`db:backup` above).

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

MediaMTX (config inline in `docker-compose.yml`, injected at runtime) does the
actual RTSP→HLS conversion; the app never touches the video itself. The camera's RTSP URL is
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
   writes the tunnel's `ingress` rules (including `^/live-cam/.*`). For an
   already-provisioned tunnel, SSH into the server and re-run it in
   reconfigure mode — it keeps the tunnel and DNS, rewrites
   `~/.cloudflared/config.yml` and recreates the container on the compose
   network:
    ```bash
    ./docker/cloudflared-setup.sh --reconfigure
    ```
4. **Deploy** (push to `main`, or re-run the workflow) so the new
   `CAMERA_RTSP_URL` reaches the server's `.env` and the `mediamtx` service
   starts.
5. **Check it.** `https://www.xn--rull-eva.lv/livestream` should show the
   feed within a few seconds. If it shows "Camera feed isn't available right
   now", check `docker logs skatepark-mediamtx-1` on the server — almost
   always either the RTSP URL/credentials are wrong, or the camera and
   server aren't actually on the same network.
