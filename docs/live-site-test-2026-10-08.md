# Live site test report — 2026-10-08

Target: `https://www.xn--rull-eva.lv` (Stripe in **test mode**).
Accounts used: customer `Roberts` (id 3), `Employee` (id 2), `Admin` (id 1). Logins were done by the owner; no passwords or card numbers were typed by the tester. Test card entered by the owner on Stripe's hosted page.

## Summary

The core flows work end to end on the live site: registration email delivery, email-verification gating, QR entry/exit with visit spending, one-time pass purchase, recurring subscription purchase/cancel, reservation payment and admin cancel-with-refund, chat with realtime, admin name-approval queue, role gating. Authorization was solid everywhere it was probed (no IDOR, no privilege escalation found).

The problems are almost all **missing bounds and abuse limits** (things that accept values or volumes nobody intended), plus a handful of small leaks and polish items. Nothing found lets a user take money, bypass payment, or read another user's data.

| #   | Severity            | Finding                                                                                          |
| --- | ------------------- | ------------------------------------------------------------------------------------------------ |
| 1   | Medium              | One account can hold unlimited pending reservations and block the whole calendar                 |
| 2   | ~~Medium~~          | ~~Chat slow-mode bypassed by parallel requests~~ — **retracted, false positive** (see below)     |
| 3   | Medium              | Reservations can be booked arbitrarily far in the future (year 9999 accepted)                    |
| 4   | Medium              | Names accept control/RTL/HTML characters; case variants and homoglyphs pass the uniqueness check |
| 5   | Medium (admin-only) | Reservation Pricing accepts closing time before opening time (saved live)                        |
| 6   | Medium (admin-only) | Subscription types have no max price (a €1,000,000,000/month plan went live)                     |
| 7   | Low                 | 500 error when `name` is sent as an array                                                        |
| 8   | Low                 | No plans exist in the live DB (nothing purchasable)                                              |
| 9   | Low                 | Over-exposed Inertia props (Stripe ids, full purchase row)                                       |
| 10  | Low                 | Internal class names leak in JSON 404s; `X-Powered-By` leaks PHP version                         |
| 11  | Low                 | Admin dashboard "Subscriptions" revenue is account-wide from Stripe, cached, ignores refunds     |
| 12  | UX                  | Admin panel has no way back to the regular site (owner-reported)                                 |
| 13  | UX                  | Exit scan must wait for the next QR refresh (owner-reported)                                     |
| 14  | UX                  | Smaller items, see "Polish"                                                                      |

---

## Findings in detail

### 1. Unlimited pending reservations per user — Medium

`Reservation::scopeOverlapping` (`app/Models/Reservation.php`) treats a `pending` reservation younger than 30 minutes as holding its slot. `ReservationController::store` has no per-user cap on pending rows (route throttle is 30/min).
**Observed:** in a few seconds one verified account created six full-park (50 people, 4 h, €1,000) pending reservations on six different days, each also creating a Stripe Checkout session. Re-creating them every 30 minutes keeps the calendar blocked indefinitely, and the admin Payments list fills with unpaid rows.
**Fix:** cap pending reservations per user (1–2), and/or release the hold when the Stripe session expires rather than on a fixed 30 min; consider also limiting to one pending hold per time range.

### 2. Chat slow-mode "race" — RETRACTED (false positive)

During live testing 8 parallel `POST /chat` all landed and I reported a slow-mode bypass. That was a misreading: slow mode in this app is **automatic** — it only turns on when the room gets busy (10 messages in 30 s, `ChatSlowMode`), and only then enforces the 10 s cooldown. The room was quiet, so slow mode was off and the messages were allowed by design; my own burst then switched it on, which is why a later "sequential" send looked blocked. A per-user `Cache::lock` already serialises posting, and a 20/min per-user send throttle applies. No code change needed.

### 3. No upper bound on reservation date — Medium

`starts_at` is validated as `required|date|after:now`. `9999-12-31T10:00` was accepted, creating a pending row and a Stripe session.
**Fix:** add a `before:` bound (e.g. now + 6–12 months, ideally a setting on Reservation Pricing).

### 4. Name validation gaps — Medium

`ProfileController::update` / `NoInappropriateContent`:

- Accepted as pending names: control char `U+0007`, zero-width `U+200B`, RTL override `U+202E`, raw HTML (`<img src=x onerror=…>`).
- The uniqueness check only compares exact strings: `EMPLOYEE` (case variant), `Εmployee` (Greek capital Epsilon) and a name with a zero-width character _inside_ it (`Emp\u200Bloyee`) are all treated as different from `Employee`. Combined with chat, this allows a user to impersonate staff; the admin approval queue is the only mitigation. (During live testing a trailing `U+200B` looked "rejected", but that was a false negative: Laravel's `TrimStrings` middleware strips it before validation.)
- Output is escaped correctly (HTML in chat renders as text, no XSS found).
  **Fix:** reject `\p{C}` (control/format) characters; compare uniqueness on a normalised key (NFKC + case-fold, ideally a confusable skeleton); apply the same rule to registration and the admin user form. Let the admin queue show the requested name with invisible characters made visible.

### 5. Reservation Pricing accepts inverted opening hours — Medium (admin-only)

`opening_time 23:00 / closing_time 08:00` saved successfully. Min/max group size and duration _are_ cross-validated; hours are not. Restored to 08:00–23:00 during the test.
**Fix:** `closing_time` must be after `opening_time`; add sane maxima for price per person and refund cutoff hours.

### 6. No maximum price on subscription types — Medium (admin-only)

`SubscriptionTypeForm` has `minValue(0.5)` and nothing else. A €1,000,000,000/month plan was created, active, "Synced to Stripe: Yes", and public. It was deactivated immediately (plan id 1 "QA bad plan").
**Fix:** `maxValue(...)` on `price_cents`; consider a confirmation step for prices above a threshold.

### 7. 500 on array `name` — Low

`PATCH /settings/profile` with `name` as an array → "Array to string conversion". Cause: `$request->merge(['name' => trim((string) $request->input('name'))])` runs before validation. The same cast exists in `RegisteredUserController` for `name` and `email` (so the register form had the same 500).
**Fix:** validate first (`'name' => ['required','string',…]`), then trim; or guard with `is_string`.

### 8. No subscription types seeded in live — Low

The public page showed "Pašlaik nav pieejamu plānu" at the start of testing; nothing could be bought. (Test plans 2 and 3 were created later for the purchase tests — see Cleanup.)

### 9. Over-exposed Inertia props — Low

- `/subscriptions`: every plan includes `stripe_product_id`, `stripe_price_id`, `stripe_synced_*`, timestamps.
- `activePurchase` includes the full purchase row (`user_id`, `stripe_checkout_session_id`, nested `subscription_type` with Stripe ids).
  Not secrets, but unnecessary. Return only the fields the page renders (API resource / `only()`).

### 10. Information leaks — Low

- JSON 404s (`Accept: application/json`) say `No query results for model [App\Models\ChatMessage] abc`.
- `X-Powered-By: PHP/8.5.11` (set `expose_php=Off`).
- Some JSON errors are hard-coded English in a Latvian UI: "You cannot mute yourself.", "You already have an active subscription."

### 11. Admin dashboard "Subscriptions" revenue — Low

`StripeRevenue` queries Stripe account-wide (cached). It showed **€20.00** with zero subscription types and no local subscribers (old sandbox invoices) and does not net out refunds. Passes/reservations/refunds figures are local and were correct (after refunding the €30 reservation: passes €5, reservations €0, refunded €30).

### 12. Admin panel is a dead end — UX (owner-reported)

`AdminPanelProvider` has no `userMenuItems`, `homeUrl` or navigation item pointing at `/`; the brand logo links to `/admin`. Add e.g. `->userMenuItems([Action::make('site')->label('Back to site')->url('/')])`.

### 13. Exit scan blocked until QR refresh — UX (owner-reported)

A successful scan consumes the token (`QrToken::consume`) so a screenshot can't be reused; the rider has to wait for the next 60 s refresh before an exit scan works. Deliberate, but awkward at the door. Options: push a fresh token to the rider's page immediately after a scan (the `UserCheckInStatusUpdated` event already exists), shorten the TTL, or show a "refreshing…" indicator.

### Polish

- Staff scan page throws `Uncaught Cannot stop, scanner is not running or paused` when the camera is denied (guard the `stop()` call).
- `POST /staff/scan` has no length cap on `code` and no throttle (staff-only; low risk).
- Admin "Cancel reservation" and name Approve/Reject use a generic "Are you sure…" modal; cancel doesn't say a refund will happen or its amount; approve/reject show no success toast.
- `POST /subscriptions/subscription/swap` with no price change still reports `price-updated`.
- No way to undo a subscription cancellation. (Re-subscribing during the grace period is allowed by design — it's how a customer switches plan; an earlier note here calling it "silently refused" was a misreading of an Inertia redirect.)
- A customer holding an active one-time pass can still start another one-time-pass checkout (confirm if stacking is intended).
- Admin Payments list includes unpaid, cancelled reservations.
- "Verification email sent" confirmation appears only after a delay.
- Filament admin is English while the public site is Latvian (fine if intended).

---

## Verified working (no action needed)

- **Public:** home, livestream (HLS 200, occupancy counter), login/register/forgot-password, 404 page, http→https and apex→www redirects, no horizontal overflow at 375 px.
- **Security headers:** CSP with nonces, HSTS, `nosniff`, `X-Frame-Options`, Permissions-Policy, secure/httpOnly/SameSite cookies, CORS closed. `.env`, `.git`, `composer.json`, `artisan`, logs, `vendor`, telescope/horizon/debugbar all 404. CSRF enforced (419), unsigned Stripe webhook 403.
- **Auth/gating:** guests redirected to login; unverified users to `/verify-email`; customer → 403 on `/admin` and `/staff/scan`; employee → 403 on `/admin`, customer pages redirect to the scan page. Verification email delivered via Resend. Resend throttled (429 after 6).
- **QR:** token rotates (60 s TTL), `no-store`, throttled 20/min; forged, expired, array/object, SQL-style and 200 KB tokens rejected cleanly; entry spent one visit (3→2); exit checked out; state and counter consistent.
- **Chat:** validation (empty/array/object/whitespace/500+ chars), XSS payload rendered as text, WebSocket connects, sequential slow mode, pin/mute/delete denied to customers, employee moderation works, admin (id 1) cannot be muted by an employee, self-mute blocked, mute hours validated.
- **Reservations:** past, too small/large groups, float/negative/scientific numbers, bad duration, outside-hours, garbage and array inputs all rejected; same-user overlap rejected; timezone offset in `starts_at` is ignored (not an hours bypass); cancel works; 404 for unknown ids; user search rate-limited and treats `%`/`_` literally.
- **Payments:** reservation paid in sandbox → `active/Paid`, revenue updated; admin cancel → "Cancelled and refunded". Pass purchase granted 3 visits; replaying the success URL ×3, fake/array/oversized/SQL-style/foreign (reservation) `session_id` caused no double grant. Monthly subscription active with renew date; second subscription refused; cancel idempotent under 3 parallel requests, grace period correct.
- **Admin:** name approval queue (approve → name live), user create form server-side validation (name taken, bad email, password required), account closure has blockers and a clear irreversible warning (not executed), subscription-type validation (negative, < €0.50, missing interval), min>max group/duration validation.

## Not tested (and why)

- Wrong-password login attempts, login throttling, password change, forgot-password flow, registration — would require typing passwords or creating accounts.
- Live camera scanning in the browser pane (camera blocked); it was tested on the owner's phone only for entry and exit.
- Scan concurrency (two staff scanning one QR at once) and subscription-vs-pass access precedence on scan — needs two simultaneous employee sessions / a second phone scan.
- Reservation participants, invitations, per-reservation chat and user search results for _other_ users — only one customer account existed.
- Closing an account, deleting plans, admin Chat/One-Time Passes resources beyond list views, statistics charts with real data volume.
- Authenticated pages at phone width beyond the dashboard/register views.

## Cleanup still needed (owner)

In Filament → Subscription Types:

- **Delete** plan 1 "QA bad plan" (€1,000,000,000, inactive).
- **Deactivate or delete** plan 2 "QA test pass" and plan 3 "QA monthly" — both are still active and **public on the home page**.

Test data left behind (harmless, sandbox): customer id 3 holds the QA pass (2 visits left) and a cancelled QA monthly subscription (grace until 2026-11-08); cancelled/refunded test reservations (ids 1–8) remain in the Reservations and Payments lists.
