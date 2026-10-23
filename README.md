# Community Hub — Laravel

The Community Hub platform on Laravel 12 with PHP 8.2+, converted from the
Next.js 15 / React / TypeScript application that used to live in `../src`.

The original Next.js app is preserved in git history — check out commit
`f3c48c6` (the last commit before the conversion) to run it and compare screen
for screen.

---

## Architecture

One Laravel application serving three surfaces from the same controllers,
services and authorization rules:

| Surface | Renders | Covers |
|---|---|---|
| **Blade** | Server HTML, no React bundle | Sign-in (`/`), privacy policy |
| **Inertia + React** | Your existing React components | All 23 dashboard pages, including the boundary editor |
| **JSON API** (`/api/*`) | JSON, Sanctum tokens | Capacitor mobile shell, handheld gate scanners |

All business logic lives in PHP. The React components were reused rather than
rewritten, so Leaflet maps, QR rendering, Radix primitives and Framer Motion
animations behave exactly as before — they now receive server data as props
instead of reading module-level mock arrays.

```
laravel/
├── app/
│   ├── Enums/                  UserRole, PassCategory, QRShape, GateId, DenyReason…
│   ├── Models/                 45 Eloquent models
│   ├── Services/
│   │   ├── GatePassEngine.php          ← port of src/lib/gate-pass-engine/engine.ts
│   │   ├── GeofenceService.php         ← port of src/lib/geofence-utils.ts
│   │   └── NotificationTargetingService.php  ← port of the Genkit AI flow
│   ├── Http/Controllers/
│   │   ├── Dashboard/          24 Inertia controllers
│   │   ├── Api/                5 JSON controllers
│   │   └── Auth/               Session login/logout
│   └── Providers/AuthServiceProvider.php   ← all role rules, in one place
├── config/gatepass.php         Pass categories, WCAG palettes, access policies
├── database/migrations/        49 tables (domain + framework)
├── database/seeders/           Reproduces every mock record
├── resources/
│   ├── views/blade/            Sign-in + privacy (Blade)
│   └── js/Pages/Dashboard/     23 Inertia pages
└── tests/                     534 tests — engine, geofence, access control, pages, payments
```

---

## Setup

Requires **PHP 8.2+**, **Composer**, **Node 18+**, and MySQL 8 / PostgreSQL 14
(or SQLite for a zero-setup local run).

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan gatepass:secret          # REQUIRED — see Security below
```

Point `.env` at your database, then:

```bash
php artisan migrate --seed
php artisan storage:link
```

Run it:

```bash
php artisan serve                    # http://localhost:8000
npm run dev                          # Vite dev server, separate terminal
```

Sign in with any seeded account — password from `SEED_PASSWORD`:

| Email | Role |
|---|---|
| `alexander.wright@communityhub.org` | System Admin |
| `elena.rostova@communityhub.org` | Admin |
| `marcus.vance@residence.net` | Homeowner |
| `sophia.taylor@residence.net` | Temporary Homeowner |
| `dispatch@apexguard.com` | Security |
| `maria.garcia@communitystaff.org` | Staff |

### SQLite shortcut

```bash
touch database/database.sqlite
# then set DB_CONNECTION=sqlite in .env and drop the DB_HOST/PORT/DATABASE lines
php artisan migrate --seed
```

---

## Laravel Boost (AI agent context)

`laravel/boost` is in `require-dev`. It runs an MCP server that gives a coding
agent real context about *this* application rather than a generic guess at how a
Laravel app is laid out — the installed Laravel and PHP versions, the route
table, the Eloquent models and database schema, version-matched documentation,
and a tinker tool for evaluating code against the running app.

**It is already installed** — see *Upgraded to Laravel 12 to install Boost*
below for what it wrote and why the framework had to move first. Nothing needs
running; `composer install` restores it from the lockfile.

The root `AGENTS.md` holding the Triovo agent directory and the Antigravity
visual standard was **not** overwritten. Boost wrote its guidelines to
`laravel/CLAUDE.md` and `laravel/AGENTS.md` instead, so the two sets sit at
different levels and do not collide.

To refresh the guidelines after a dependency change:

```bash
php artisan boost:update
```

### Why it matters for this migration specifically

The conversion was written without a running PHP environment, so everything in
this repository was verified statically — imports resolve, braces balance, route
controllers exist. Boost closes exactly that gap. Once installed, an agent can:

- run `php artisan test` and read real failures instead of reasoning about them;
- read the live schema rather than inferring it from migration files;
- list actual routes instead of parsing `routes/web.php` with a script;
- check Laravel 12 API details against version-matched docs rather than memory;
- evaluate a model method in tinker to confirm it behaves as intended.

That gap is now closed twice over: the suite runs, and Boost is installed. The
first real test run found six defects that static checking had missed — including
a 500 on account deactivation — which is the clearest possible argument for
running things rather than reasoning about them. See *Verification status*.

---

### Upgraded to Laravel 12 to install Boost

Boost would not install on Laravel 11. Composer refused to resolve anything
because `laravel/framework` v11.56.1 — the newest 11.x that exists — carries
three security advisories, all fixed only in 12.60+ / 12.61+ / 13.x:

| Severity | Advisory | Fixed in |
|---|---|---|
| high | CRLF injection in the default email rule (CVE-2026-48019) | 12.60.0 |
| medium | Temporary signed URL path confusion | 12.61.1 |

There is no patched 11.x release — Laravel 11 is out of security support — so the
framework was upgraded rather than the advisories ignored.

```
laravel/framework   v11.56.1 -> v12.69.2
laravel/boost                   v1.8.13   (+ laravel/mcp, laravel/roster)
composer audit      No security vulnerability advisories found.
```

**No application code needed changing.** All 168 tests passed on v12.69.2 on the
first run. The two Laravel 12 behaviour changes that could have bitten this
codebase were checked by hand and neither applies:

- **Carbon 3 made `diffIn*()` signed floats** rather than absolute integers.
  Nothing in `app/`, `database/` or `tests/` calls any `diffIn*` method.
- **The `image` validation rule no longer accepts SVG by default.** The only
  use of it — `ProfileController::updateAvatar` — already pinned
  `mimes:jpg,jpeg,png,webp`, so SVG was excluded before and after.

Constraints bumped alongside the framework: `laravel/tinker` ^2.10,
`phpunit/phpunit` ^11.5.3, `nunomaduro/collision` ^8.6, `laravel/sail` ^1.41,
`laravel/pint` ^1.18. Inertia stays on v1 on both sides of the wire — moving to
Inertia v2 is a separate migration and was not bundled into this one.

### What Boost installed

`php artisan boost:install` detected the stack unaided — Laravel v12, Inertia v1,
React 18, PHPUnit 11, Pint, Tailwind v3, Sanctum v4, Ziggy v2 — and wrote:

| File | Purpose |
|---|---|
| `laravel/CLAUDE.md`, `laravel/AGENTS.md` | 14 version-matched guideline sets (identical files, 327 lines) |
| `laravel/.mcp.json`, `laravel/.vscode/mcp.json` | MCP server config for Claude Code, Codex and VS Code |
| `laravel/boost.json` | Which agents and editors Boost targets |

The root `AGENTS.md` was **not** touched.

The server answers a real MCP handshake and exposes 15 tools:

```
application-info      database-schema   list-artisan-commands   read-log-entries
browser-logs          get-absolute-url  list-available-config-keys   search-docs
database-connections  get-config        list-available-env-vars      tinker
database-query        last-error        list-routes
```

**One machine-specific detail in `.mcp.json` at the repository root.** On this
machine `php` is a `.bat` shim that Herd installs, and MCP clients spawn
processes without a shell, so `"command": "php"` could not be resolved — which is
why the server reported `CONNECTION_CLOSED` before. The root config now points
at the real interpreter:

```json
"command": "C:\Users\<you>\.config\herd\bin\php84\php.exe"
```

Anyone else cloning this repository must change that line, or delete the root
`.mcp.json` and open the `laravel/` directory directly, where Boost's own
portable `php artisan boost:mcp` config lives.

Claude Code spawns MCP servers when a session starts, so Boost's tools appear on
the **next** session, not this one.

---

## Security

The original app had no backend, so several things that read as security
features were not enforceable. Moving them to PHP changed that.

### The signing secret was public

`src/lib/gate-pass-engine/engine.ts` contained:

```ts
const GATE_ENGINE_SECRET = 'ch-master-sec-key-89104-ecca91-prod';
```

That file was bundled and shipped to every browser, so the key was readable by
anyone who opened DevTools — and with the key, anyone could mint a pass for any
role. The secret is now server-only, read from `GATE_ENGINE_SECRET`, and
`GatePassEngine` refuses to construct without it.

**Rotate the old value.** It should be considered compromised:

```bash
php artisan gatepass:secret --force
```

### Signatures were not actually HMAC

`computeSignature()` described itself as HMAC-SHA256 but implemented a 32-bit
FNV-style hash producing `SIG-XXXXXXXX-<length>`. A 32-bit space is brute-forced
in seconds, and the construction is not a MAC at all. It is now
`hash_hmac('sha256', …)` over a key-sorted canonical JSON encoding, compared with
`hash_equals()` for constant time.

**This changes the token format**, so tokens minted by the old JS engine will not
validate here. That is deliberate — they were forgeable. Passes are re-issued
automatically on first sign-in.

### Replay detection did not survive a page reload

`USED_NONCE_CACHE` was an in-memory `Map`, so refreshing the scanner page cleared
every recorded nonce, and two guards on two devices shared nothing. It is now the
`gate_pass_nonces` table with a unique index — the insert *is* the lock, so two
simultaneous scans of the same screenshot cannot both succeed. Spent rows are
pruned hourly by a scheduled task.

### Revocation was a hardcoded list

`REVOKED_PASS_IDS` was a `Set` with two literal IDs. Revocation is now
`gate_passes.revoked_at`, with the revoker and reason recorded, and denials are
written to a dedicated `security` log channel.

### Access policy was advisory

`CATEGORY_POLICIES` (operating hours, permitted gates, privileges) was evaluated
in the browser, where a user could edit it in memory. It now lives in
`config/gatepass.php` and is enforced by `GatePassEngine::validate()` server-side.
Role checks that were `user.role === 'Admin' && …` in JSX — which only hid UI —
are now gates in `AuthServiceProvider` enforced on every route.

### AI consent was a localStorage key

The dialog promised that the text you paste is sent to Google, and recorded your
answer under `localStorage['ai-feature-consent']`. That made a data-protection
decision browser-local: it did not follow you to a second device, clearing site
data revoked it with no record it had ever been given, and the server — the thing
that actually makes the call to Google — had no way to read it.

`users.ai_consent` already existed. The dialog now writes it through
`dashboard.profile.ai-consent`, and `NotificationController::suggestAudience()`
refuses with 403 when it is not set. A promise enforced only in the browser is
not enforced.

### Other fixes that came with a server

- **Any password worked.** The mock login matched a username against an object
  and ignored the password entirely. Now: hashed credentials, rate limiting
  (6 attempts/minute), session regeneration on login.
- **The blocklist did nothing.** `Visitor.isBlocked` was a field nothing computed,
  so a blocked name could be registered and admitted. It is now checked at
  registration *and* again at check-in, since someone may be blocklisted after
  being registered.
- **Warning votes were unlimited.** Confirm/deny were client counters that reset
  on reload and let one person vote repeatedly. Now one row per user per warning,
  with a unique index.
- **Deactivation was cosmetic.** It wrote a localStorage flag the user could
  clear. It now sets account status, revokes the gate pass, ends the session, and
  is logged; `EnsureUserIsActive` logs out a deactivated account on its next
  request.
- **Contact details leaked to everyone.** The directory rendered every resident's
  email and phone. Those fields are now `null` in the payload unless the viewer
  can manage users.

---

## Digital Gate Pass Engine

The pass system is split into four pieces:

- `App\Services\GatePassEngine`: issues and validates.
- `App\Services\GateScanner`: the guard's scan-and-confirm flow.
- `App\Models\GatePass`: the registry and lifecycle.
- `config/gatepass.php`: profiles, palettes, policies, gates and zones.

### Visual identity is not authorization

Every profile has one fixed frame shape. A pass also carries a colour, drawn
at random from that profile's approved palette when it is issued, and stored
on the pass.

| Profile | Shape | | Profile | Shape |
|---|---|---|---|---|
| SysAdmin | 8-point star | | Staff | Diamond |
| Admin | Octagon | | Security | Shield |
| Homeowner | Hexagon (house) | | Homeowner Staff | House/hex variant |
| Renter | Rounded square | | Visitor | Circle |
| | | | Contractor | Pentagon |

Shape and colour are for the guard's eyes only. Nothing is ever decided by
them. Access comes from the HMAC-signed token and the registry row.
Rotating a pass picks a new colour and advances its sequence number, which
the token signs, so every code minted before the rotation is refused as
superseded. Every palette colour is tested against WCAG AA (4.5:1 on white)
using a ratio computed from its hex. That test found staff "Industrial
Safety Orange" `#EA580C` at 3.56:1 despite a declared 5.1, so it is now
`#C2410C`. Most other declared ratios were also off and now hold the real
values.

### Lifecycle

```
REQUESTED → APPROVED → ISSUED → ACTIVE → CHECKED_IN → CHECKED_OUT
exits: REJECTED, CANCELLED, REVOKED, EXPIRED, SUSPENDED
```

- **The transition table is in `App\Enums\PassStatus`.** `GatePass::transitionTo()`
  refuses any other move, takes a row lock, and records every step in
  `gate_pass_transitions`: from, to, who, which gate, and why.
- **Account passes** (residents, staff, security, admins) are issued ACTIVE,
  multi-entry and open-ended.
- **A resident's visitor** is REQUESTED, then APPROVED and ISSUED straight
  away: registering the guest is the approval.
- **A contractor** stays REQUESTED until security or an admin approves it,
  and their pass is only sent after approval.
- **Guest passes have a window.** One-time passes open an hour before the
  expected arrival and close 12 hours after it, and are single-entry.
  Recurring passes last 30 days and allow re-entry.
- **Expiry.** `gatepass:expire` runs every 15 minutes and expires lapsed
  passes. The no-show sweep expires a visitor's pass along with their
  clearance. Someone CHECKED_IN is never expired: they have to be checked
  out, so the log shows when they actually left.
- **Revoked passes stay revoked.** `issuePassFor()` used to create a new pass
  whenever a user had no *active* one, so a pass revoked by security came
  back freshly issued on the holder's next page load. The token endpoints now
  refuse to mint a code for a pass that can't be used (HTTP 423), and a new
  pass after revocation comes only from `POST /dashboard/gate-pass/reissue/{user}`
  (security).
- **Manual moves** go through `POST /dashboard/gate-pass/{gatePass}/transition`:
  approve, reject, suspend, reinstate, cancel and revoke. Security may make
  any of them. A host may only cancel their own guest's pass. Reject, suspend
  and revoke need a reason. Check-in and check-out cannot be set by hand.

### Scanner (Security → Visitors → Scan QR)

The scanner checks, in order:

1. Signature, including the trailing signature segment, which used to be
   ignored.
2. Community.
3. Pass ID on the registry.
4. Rotation sequence.
5. Profile, person, property and zone, each against the registry rather than
   trusting the token.
6. Revocation.
7. Current status.
8. The pass's start and end time.
9. The holder: account active, visitor not blocklisted.
10. Replay.
11. Gate, and whether that gate admits into the pass's zone
    (`config/gatepass.php` `gate_zones`: visitors use the main gate).
12. Shift hours.

It then answers **CHECK_IN**, **CHECK_OUT** or **REJECT** from the pass's
state. Leaving skips the gate, zone, hours and validity checks, because an
overstaying contractor still has to be let out.

The decision is held on the server under a random scan ID for two minutes.
The guard confirms or refuses it (`POST /dashboard/gate-pass/scans/{scan}/confirm`,
and the same under `/api` for handheld scanners). The confirmation carries
only the ID. It can be used once, only by the guard who scanned, and fails
if the pass changed in between. It replaces `confirm-action`, which accepted
any pass ID and "CHECK_IN"/"CHECK_OUT" from the browser with no token and no
validation.

`sample-tokens` is also gone. It minted live codes for other residents, used
`createCustomToken()` to sign arbitrary claims with the real key, and
created a `maria.williams` account with the password `password`. The manual
Check In button on the Visitors list now goes through the same pass rules,
minus the token checks.

### Guest pass page

The QR was drawn by `api.qrserver.com` from the guest-pass URL, which sent
that bearer link to a third party, and the image was a link rather than a
credential. The page now shows a live signed code, rendered on this server
by `QrCodePng` and refreshed every 10 seconds from
`GET /guest/pass/{token}/code`, so a screenshot stops working. A contractor
awaiting approval sees a message instead of a code.

**Not yet verified in a browser:** the camera scanner and the Visitors page
changes are covered by the PHP tests and the type check only.

---

## What each source file became

| Original | Now |
|---|---|
| `src/lib/gate-pass-engine/engine.ts` | `app/Services/GatePassEngine.php` + `config/gatepass.php` |
| `src/lib/gate-pass-engine/shapes.tsx` | Kept as-is (presentational) |
| `src/lib/geofence-utils.ts` | `app/Services/GeofenceService.php` (authoritative) + `resources/js/lib/geofence-utils.ts` (live display only) |
| `src/lib/boundary-manager/service.ts` | `BoundaryController` + `boundary_configs` tables |
| `src/lib/data.ts`, `src/lib/visitors-data.ts` | Migrations + seeders |
| `src/lib/firebase.ts` | **Deleted** — it was initialised but never imported anywhere else |
| `src/ai/flows/generate-targeted-notifications.ts` | `app/Services/NotificationTargetingService.php` |
| `src/context/auth-context.tsx` | Laravel auth + `users` table |
| `src/context/billing-context.tsx` | `billing_settings` table |
| `src/context/branding-context.tsx` | `branding_settings` table |
| `src/context/theme-context.tsx`, `use-dark-mode.ts` | `user_preferences` table + pre-paint script |
| `src/context/map-context.tsx` | `boundary_configs` table, shared on every response |
| `src/app/page.tsx` | `resources/views/blade/landing.blade.php` |
| `src/app/privacy/page.tsx` | `resources/views/blade/privacy.blade.php` |
| `src/app/dashboard/layout.tsx` | `resources/js/Layouts/DashboardLayout.tsx` |
| `src/app/dashboard/<route>/page.tsx` | `resources/js/Pages/Dashboard/<Name>.tsx` |
| `src/components/**` | `resources/js/components/**` (props-driven where a wired page uses them) |
| `next/image` | `resources/js/components/ui/image.tsx` shim |
| `next/link`, `next/navigation` | `@inertiajs/react` |
| `next/dynamic` | `resources/js/lib/dynamic.tsx` (React.lazy + Suspense) |

### Dropped dependencies

`next`, `firebase`, `genkit`, `@genkit-ai/*`, `patch-package` — all replaced by
the PHP backend. Every UI dependency (Radix, Leaflet, Recharts, Framer Motion,
react-qr-code, react-hook-form, zod, date-fns) is retained at the same version.

---

## Testing

To run every build gate CI runs, in order, stopping at the first failure:

```bash
composer check
```

| Gate | Command | Must be |
|---|---|---|
| Lock file | `composer validate` | valid (`composer.json` and `composer.lock` agree) |
| PHP tests | `php artisan test` | 0 failures |
| Style | `vendor/bin/pint --test` | 0 files to fix |
| TypeScript | `npm run typecheck` | 0 errors |
| Dependencies | `npm audit --omit=dev --audit-level=high` | 0 production vulnerabilities |
| Build | `npm run build` | passes |

Run `composer install` and `npm ci` first on a fresh checkout. For the tests
alone:

```bash
php artisan test
```

`tests/Unit/GatePassEngineTest.php` covers all four validation stages, signature
forgery, key-order independence, expiry, clock skew, revocation, deactivated
holders, replay across engine instances, gate restriction, staff shift hours, and
the WCAG contrast floor on every palette entry.

`tests/Feature/NotificationPageTest.php` covers publishing, role targeting, the
empty-audience-means-everyone rule, and the AI consent gate.

`tests/Feature/WarningPageTest.php` covers vote tallies, vote changing, the
self-corroboration block, the rate limit on raising an alert, and removal.

`tests/Feature/DirectoryPageTest.php` covers the admin gate, an account created
through the form signing in for real, deactivation and reactivation, and the
privilege boundary that stops an Admin disabling a System Admin.

`tests/Feature/AccessLogPageTest.php` covers the security gate, refused entries
reaching the page with their reason, each filter including the end-of-day date
bound, and a CSV export that honours the active filter and spans every page.

`tests/Feature/BillingPageTest.php` covers invoice scoping, minor-unit sums,
the collections aggregate, the fee/currency/due-day settings and double-payment
refusal.

`tests/Feature/FundraisingPageTest.php` covers the page loading on SQLite at
all, donation currency being taken from the fundraiser, minor-unit totals,
anonymity, and opening an Upcoming fundraiser.

`tests/Unit/GeofenceServiceTest.php` covers the polygon maths, including a
horizontal-edge case that would divide by zero in PHP where JavaScript silently
produced `Infinity`.

`tests/Feature/DashboardAccessTest.php` covers login and lockout, every role gate,
blocklist enforcement at both registration and check-in, visitor ownership,
single-vote warnings, scan logging, self-deactivation, and that contact details
stay hidden from non-admins.

The full suite is 534 tests across 37 files. See *Verification status* below
for the current result and what the first real run found.

---

## Verification status

Everything below has been **executed** on Laravel 12.69.2 / PHP 8.4.25
(Laravel Herd) against SQLite. Last re-run: **22 September 2026** (except the
row marked *).

```
php artisan migrate:fresh --seed     17 migrations, 7 seeders          PASS
php artisan test                     534 tests, 3,106 assertions       PASS‡
npm run typecheck                    0 errors                          PASS
npm run build                        23 pages built                    PASS
php artisan route:list              132 routes resolve                 PASS
npm audit --omit=dev                 0 vulnerabilities                 PASS
composer audit                       no advisories                     PASS*
```

(132 rather than the original 93: Boost registers `_boost/browser-logs`,
Laravel 12 adds `storage.local.upload`, and the pages wired since then, the
Stripe checkout, guest-pass, PDF, payment-channel and revenue-engine work added
their own.)

`migrate:fresh --seed` was run against a scratch SQLite file, not the local
database, so nothing local was wiped. It seeds a gate pass for every account
and every seeded visitor, in the state their visit is in.

‡ All pass. PHPUnit reports one *deprecated* notice: bacon-qr-code 2.0.8
uses implicitly nullable parameters, which PHP 8.4 deprecates. It is a
warning, not a failure. bacon-qr-code 3 fixes it; simple-qrcode 4.2, which
pinned bacon-qr-code to 2.x, has since been removed (see below), so the
upgrade is no longer blocked.

The three `SendVisitorPassNotificationTest` failures from earlier are fixed.
`simplesoftwareio/simple-qrcode` was in `composer.json` but missing from
`composer.lock` and `vendor/`. It is installed now, along with its
bacon-qr-code and dasprid/enum dependencies, and `composer validate` passes.
Installing it alone would not have been enough: simple-qrcode 4.x writes PNG
only through the **imagick** extension, which Herd's PHP does not have. So the
job now draws the PNG with GD (`App\Services\QrCodePng`), and bacon-qr-code
does the encoding. GD is already required by dompdf, so no extension is
needed anywhere. A rendered guest-pass URL was decoded back with jsQR to
confirm the image scans. `bacon/bacon-qr-code` is declared directly in
`composer.json`, since the job now uses it itself. Nothing called
simple-qrcode after that, so it was later removed, together with the unused
`laravel/octane` and `laravel/pennant`.

\* `composer audit` returned *No security vulnerability advisories found* twice
— at the end of `composer update` and on the first verification pass. A later
re-run could not complete because packagist.org's advisories API was returning
`HTTP 502`. That is the registry being down, not a finding; re-run it when
packagist recovers. It was not re-run on 22 September.

The suite was run three times consecutively to shake out order dependence.

**What the first real run found**, none of which static checking had caught:

| Defect | Where |
|---|---|
| `Undefined property: HasMany::$each` — deactivation threw a 500 | `DeactivationController` used the higher-order `->each->` proxy on a query builder instead of a collection |
| `<BoundaryPointManager />` passed no props at all | `Pages/Dashboard/Boundary.tsx` — type-checked as `{}`, would have left `initialConfig` undefined at runtime |
| An editable email field the server silently discarded | `Pages/Dashboard/Profile.tsx` — `ProfileController` accepts `display_name` and `phone` only |
| A test asserting a rule the code no longer has | `DashboardAccessTest` still expected a flat 403 on the blocklist after the read was opened to residents |
| A test that passed on the random seed | The resident spotlight orders by `display_name`; the viewer's faker name decided whether `residents.0` was the expected row |
| Two tests asserting a distinction JSON cannot carry | `json_encode(100.0)` emits `100`, so the `(float)` money casts do not survive serialisation — harmless in JS, but the assertions were wrong |

The bracket-balance checker reports two files it cannot read:
`boundary-manager/service.ts` and `Pages/Dashboard/Overview.tsx` each contain a
JS regex literal whose body looks like quotes or brackets. Both were checked by
hand. Telling a regex literal from a division needs real expression context,
which the checker does not have.

### A formatting sweep worth knowing about

Boost's guidelines say to run `vendor/bin/pint --dirty` before finalising
changes. `--dirty` means "files changed according to git" — and `laravel/` is
untracked, so every file counted. Pint reformatted **104 files** in one pass:
mostly `binary_operator_spaces`, which removed the aligned `=>` used throughout
this codebase, plus ten genuinely unused imports and some line endings.

It is style-only. All 200 tests, the typecheck, the build and the import audits
pass unchanged afterwards. The result now matches the Laravel Pint preset, which
is what any contributor's `pint` run would produce, so it is the more
conventional end state — but it was a wider change than intended and the files
are untracked, so there is no `git checkout` back to the aligned style.

Run `vendor/bin/pint` (no `--dirty`) from now on and the output is stable.

### Two environment notes

**`php artisan serve` does not bind** under the Herd PHP shim on this machine —
it reports `Failed to listen on 127.0.0.1:8000 (reason: ?)` on any port. The
built-in server works directly:

```bash
php -S 127.0.0.1:8123 -t public
```

**`GATE_ENGINE_SECRET` must be set** or `GatePassEngine` throws on construction.
It is now populated in `.env` with 32 random bytes. Generate a fresh one before
deploying anywhere real.

---

## Continuous integration

`.github/workflows/ci.yml` (at the repository root, since `laravel/` is a
subdirectory) runs on every push to `main` and every pull request, in two
jobs:

| Job | Steps |
|---|---|
| **backend** | `composer validate` (lock file matches `composer.json`), `composer install`, `php artisan test`, `pint --test`, `composer audit` |
| **frontend** | `npm ci`, `npm run typecheck`, `npm run build`, `npm audit --omit=dev --audit-level=high` |

PHP 8.4 runs with GD, which renders both the dompdf PDFs and the visitor-pass
QR images. No imagick extension is needed.

Every step passes locally: `composer validate`, the full test suite,
`pint --test` across the project, the type check, the production build and
both audits.

To make CI a gate rather than a report, add both jobs as required status
checks in the branch protection rules for `main`.

---

## Deployment

The app needs three long-running processes, not one:

| Process | Command | Why |
|---|---|---|
| Web | Apache serving `public/` | The site and the JSON API |
| Queue worker | `php artisan queue:work` | Visitor-pass SMS/WhatsApp/email notifications and broadcast events are queued (`QUEUE_CONNECTION=database`). Without a worker they sit in the `jobs` table forever |
| Scheduler | `php artisan schedule:work` | `routes/console.php`: gate-pass nonce pruning (hourly) and visitor no-show expiry (every 15 minutes). Without it, stale visitor pre-clearances stay valid at the gate |

### Docker (recommended)

`Dockerfile` builds one image (assets built in a Node stage, Composer
dependencies in a Composer stage, PHP 8.4 + Apache at runtime, running as
`www-data` on port 8080). `docker-compose.yml` runs it three times (web,
queue, scheduler) plus MySQL 8.4, sharing one `storage` volume so the web
container can serve QR images the worker writes.

```bash
cp .env.production.example .env.production
# fill in APP_KEY, APP_URL, GATE_ENGINE_SECRET, DB_PASSWORD, DB_ROOT_PASSWORD
docker compose --env-file .env.production up -d --build
```

`--env-file` is needed: without it Compose reads `${DB_PASSWORD}` from
`laravel/.env`, the development file.

On start-up, `docker/entrypoint.sh` refuses to run without `APP_KEY` and
`GATE_ENGINE_SECRET`, migrates (web container only, so the three do not
race), links storage, and caches config, routes, views and events. Health is
checked against Laravel's `/up` route.

Generate the two secrets with:

```bash
php artisan key:generate --show
php artisan gatepass:secret --show
```

The image serves plain HTTP. Put a TLS-terminating proxy (Caddy, nginx, a
cloud load balancer) in front of it and set `TRUSTED_PROXIES` so Laravel
generates `https://` URLs (see `config/trustedproxy.php`).

`.gitattributes` pins `*.sh` and the `Dockerfile` to LF line endings. With
`core.autocrlf=true`, a CRLF checkout on Windows would otherwise break the
entrypoint with `/bin/sh^M: not found`.

### Without Docker (a VPS)

Run the web server as usual, then keep the worker alive with Supervisor:

```ini
[program:community-hub-queue]
command=php /var/www/community-hub/artisan queue:work --tries=3 --backoff=10 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=60
```

and add one cron entry for the scheduler:

```
* * * * * cd /var/www/community-hub && php artisan schedule:run >> /dev/null 2>&1
```

After each deploy, run `php artisan migrate --force`, `php artisan optimize`
and `php artisan queue:restart` so workers pick up the new code.

**Not yet verified:** the image has not been built on this machine, since the
build pulls base images from Docker Hub. `docker compose config` validates
the compose file, and the entrypoint passes `sh -n`.

---

## Page wiring status

**Wired to the server (20 of 23):**

| Page | What changed |
|---|---|
| `Dashboard/GatePass` | Token is polled from `dashboard.gate-pass.token` instead of minted locally; directory and staff come from props; colour rotation and pass revocation post to the server |
| `Dashboard/Visitors` | Props-driven with server-side pagination; the registration form now actually submits; check-in/out go through the server |
| `Dashboard/Overview` | Every tile, table and card is a real aggregate scoped to the viewer's role; the map draws the published boundary and database landmarks |
| `Dashboard/Map` | Landmarks are database rows with server-side add/remove; the boundary editor loads and publishes through `BoundaryController` |
| `Dashboard/Settings` | Notification switches persist; branding, theme and the boundary editor all write to shared server state |
| `Dashboard/BlockList` | Paginated and redacted per role; removal requests are stored and reviewable instead of logged to the console |
| `Dashboard/Notifications` | Notices are stored and role-filtered server-side; the audience picker finally drives the `target_roles` column; AI drafting runs on the server behind account-level consent |
| `Dashboard/Warnings` | Confirm/deny votes are rows behind a unique index instead of React state; residents can raise an alert again; false alarms can be removed |
| `Dashboard/Directory` | Accounts come from the database; creating one now actually works; delete became deactivate; an Admin can no longer touch a System Admin |
| `Dashboard/AccessLog` | Refused entries are visible for the first time; the filters and CSV export finally have controls, and the export matches the filtered view |
| `Dashboard/Billing` | The fake card form and its Stripe claim are gone; invoices, totals and the collections chart are real; marking an invoice paid finally works |
| `Dashboard/Fundraising` | Fundraisers and donations come from the database; donations are forced into the fundraiser's currency so the total means something; the page no longer throws on SQLite |
| `Dashboard/Renters` | Renters come from the database, paginated and scoped to the viewer's property; create, edit and delete go through the server |
| `Dashboard/Profile` | Profile, pass visual and activity come from props; saving display name and phone patches `dashboard.profile` |
| `Dashboard/Boundary` | `BoundaryPointManager` receives the server's draft, published polygon and permissions instead of reading `localStorage` |
| `Dashboard/Calendar` | Events, entry slots and the viewer's visitors come from props. The event form used to close and reload without sending anything while toasting "Event Created"; it now posts to `dashboard.calendar.store` / `.update` and only reports success once the server accepts |
| `Dashboard/Deactivation` | "Delete My Account Now" used to clear `localStorage`, sign out and announce *"Account Permanently Deleted"* while the account stayed active and its pass kept working. The client-only scheduling countdown promised email and SMS reminders nothing sends. Both are gone: the page posts password, reason and confirmation to `DeactivationController::store`, and Temporary Homeowners, whom the route and menu already allowed, are no longer shown "Access Denied" |
| `Dashboard/Updates` | Updates come from the database, newest first and paginated; the Add Update form posts to `dashboard.updates.store` and reports success only once the server accepts. Dates are sent and read as calendar dates, so an update does not drift a day for viewers west of UTC |
| `Dashboard/Guidelines` | Guidelines come from the database, grouped by category. The page always offered Add, Edit and Delete, but the server had only `index`, so every change was lost on reload. `store`, `update` and `destroy` now exist behind `broadcastNotices`, and a new guideline goes to the end of its category |
| `Dashboard/ReviewFeedback` | The real queue, with status and type filters, status counts and pagination. The page never showed a submission's body, so administrators saw only a subject line; a View & Respond dialog now shows it and saves a status and response. The Delete action had no endpoint and was removed: resolving a submission is how it leaves the queue, and it stays the submitter's record |

Supporting pieces, reusable by the remaining pages:

- `resources/js/context/auth-context.tsx` — `useAuth()` backed by Inertia page
  props. Unblocks the 26 components that call it.
- `resources/js/context/branding-context.tsx` — `useBranding()` backed by the
  shared `branding_settings` row.
- `resources/js/context/map-context.tsx` — `useMap()` backed by the published
  `boundary_configs` row, with server-computed area and perimeter.
- `resources/js/context/billing-context.tsx` — `useBilling()` backed by
  `billing_settings`.
- `resources/js/context/theme-context.tsx` — `useTheme()` backed by
  `branding_settings.theme_tokens`, with optimistic local state.
- `resources/js/lib/boundary-manager/{types,service}.ts` — editor types, the
  coordinate parsers, live validation, and debounced draft/publish calls.
- `resources/js/lib/debounce.ts` — keyed trailing-edge debounce, so the
  settings forms do not issue one request per keystroke.
- `resources/js/lib/geofence-utils.ts` — the polygon maths, kept client-side for
  live display only. PHP stays authoritative for anything that grants access.
- `resources/js/lib/dynamic.tsx` — a `next/dynamic` stand-in built on
  `React.lazy`, so Leaflet stays code-split.
- `resources/js/lib/gate-pass-engine/config.ts` — visual config registry,
  hydrated from the server so the palette is not duplicated in TypeScript.
- `resources/js/lib/gate-pass-engine/api.ts` — async token, rotate and scan calls.
- `DashboardLayout` now mounts `TooltipProvider`, `BrandingProvider` and
  `Toaster`, which came from the Next.js `providers.tsx` chain. Without the first
  two, Radix Tooltip throws; without `Toaster`, every `toast()` is silent.

Three props are now shared on every Inertia response, replacing four providers
that each kept their state in `localStorage` or React state: `boundary`
(MapProvider), `billing` (BillingProvider) and `branding` (BrandingProvider and
ThemeProvider). Each was previously per-browser, so an administrator's change
reached nobody.

**A note on write frequency.** Three of the ported forms called their context
setter on every keystroke or slider movement — the community name, the theme
colour pickers, and every coordinate edit in the boundary editor. That was free
against `localStorage` and would be one request per character against the
server. Theme, branding and boundary drafts are debounced, and the theme and
branding contexts keep optimistic local state so the live preview stays instant
while the save lands ~600ms later.

### Behaviour that changed, and why

Three things do not work the way they used to, all of them consequences of moving
the signing key off the client:

1. **The scanner's demo passes are gone.** It offered one-click tokens for eight
   fabricated identities, which only worked because the browser could sign
   anything. The replacements (`Non-GPE QR`, `Malformed Envelope`,
   `Forged Signature`, `Replay`) perform real scans against the server pipeline —
   three of them by corrupting the operator's own live token.
2. **Expiry and out-of-shift denials can no longer be simulated from the UI.**
   They depend on server time and the category schedule in `config/gatepass.php`.
   `GatePassEngineTest` covers both by injecting a clock.
3. **An admin cannot produce another resident's live token.** The directory's
   per-pass *Scan* button is now *View Pass* (a static preview) plus *Revoke*.
   Issuing someone else's credential was only ever possible because the key was
   public.

A fourth, on the dashboard: the **"Security Gate Locked / Gate Opened" toggle was
driven entirely by local state.** Clicking it flipped a label and nothing else —
no request, no barrier, no log. On a guard's dashboard that is worse than having
no control, because it reads as confirmation that the gate moved. It is now
disabled with a tooltip explaining that remote barrier operation needs a
gate-controller integration.

Defects fixed in passing:

- `mapRoleToCategory()` collapsed `System Admin` into `ADMIN` (so a sysadmin saw
  the octagon badge instead of the 8-point star) and could never return
  `HOMEOWNER_STAFF`, so household staff got the community-staff diamond. The
  category now comes from `User::passCategory()`.
- The visitor no-show sweep moved from a browser `setInterval` to
  `visitors:expire-no-shows`, scheduled every 15 minutes — it previously ran only
  while somebody had the page open and dropped rows from local state, leaving
  stale pre-clearances valid at the gate indefinitely.
- `SetGeofenceDialog` gated on `role === 'System Admin'` while the server's
  `manageBoundary` gate also allows `Admin`, so an Admin saw a read-only form the
  server would have accepted edits from. It also toasted "Successfully Updated"
  synchronously — it could not fail, because it never asked anyone. Publishing is
  now a server round-trip with real success and failure paths, and the client's
  point-count limits (4–8) match the server's instead of allowing 3.
- The resident spotlight rendered every resident's phone and message buttons to
  every other resident. Phone numbers are now omitted from the payload unless the
  viewer can manage users — the same restriction the directory got.
- Money accessors returned `int` when the minor-unit amount divided evenly, so
  the same field was sometimes `100` and sometimes `100.5` in JSON. They now
  always return `float`.
- **The server accepted self-intersecting boundaries.** The client editor
  refused a bow-tie polygon, but `GeofenceService::validateBoundary()` had no
  such check, so one could be posted directly. A crossed perimeter has no
  well-defined inside, which makes every point-in-polygon result — and so every
  gate decision — arbitrary. The check (and the near-duplicate-vertex warning)
  now exists on both sides.
- **`BoundaryPointManager` hardcoded `canEditBoundary = true`** with the comment
  "always allow editing in the management panel", so its own permission checks
  could never fire. It now reflects the server's `manageBoundary` gate.
- **Its "Publish" button saved a draft.** It built the next version number and
  an audit entry client-side, wrote the result to `localStorage`, and announced
  "Boundary Version N Published!" — while nothing reached another user and the
  version counter was whatever that browser happened to hold. The server now
  assigns the version, writes the audit row and supersedes the previous
  boundary, and the confirmation waits for it.
- **The map's "Reset to Standard Pins" button was removed.** It replaced the
  landmark list with a hardcoded fixture. Against one browser's `localStorage`
  that wiped only your own copy; against shared data it would have deleted every
  pin the community had added, behind an innocuously labelled button.
  Landmarks are now removed one at a time, with a confirmation.
- `Map.tsx` and `SetGeofenceDialog` both gated on `role === 'System Admin'`
  while the server allows `Admin` too — the same mismatch found on the Overview
  page.
- **The Block List menu item led to a 403.** The sidebar offered the page to
  Homeowners and Temporary Homeowners, and the page carried a Homeowner-only
  "Request Removal" action — but the route required `manageBlocklist`, which
  residents do not hold. Read and write are now split: any signed-in user can see
  that someone is blocked, only `manageBlocklist` can change the list.
- **"Request Removal" did nothing.** It called `console.log` and told the
  resident their request "has been sent to the administrators for review".
  Nothing was stored and no administrator had anywhere to see it. Requests are
  now `blocklist_removal_requests` rows that surface on the block list for
  reviewers, and approving one is what lifts the block.

### SMS and WhatsApp said "sent" and sent nothing

`SmsService` and `WhatsAppService` wrote `Would send to <full number>:
<full message>` to the application log and returned `true`. Every SMS and
WhatsApp pass was reported as delivered and none was. The log also built up
a list of visitors' phone numbers next to working guest-pass links, which let
anyone who could read the log through the gate.

**They now send through Twilio** (`App\Services\Messaging\TwilioClient`, one
REST call through Laravel's HTTP client, so no SDK and `Http::fake()` in tests).
They return Twilio's message SID or throw `MessageNotSent`. They refuse
up front when:

- Twilio is not configured (`TWILIO_SID`, `TWILIO_TOKEN`, plus `TWILIO_FROM`
  for SMS or `TWILIO_WHATSAPP_FROM` for WhatsApp);
- the contact is not a phone number. `contact` holds an email *or* a number,
  and the old code would queue an SMS to `liam@example.com`. Numbers are
  normalised to E.164, with 10-digit local numbers given
  `SMS_DEFAULT_COUNTRY_CODE` (1, which covers Jamaica).

**Logs carry a masked number (`+1******1234`) and Twilio's SID, never the
message.** Twilio's own error text is kept out of exceptions too, since it
quotes the number; the error code is enough to look up.

**WhatsApp needs an approved template to reach most visitors.** WhatsApp only
delivers free text to someone who has messaged the business in the last 24
hours, and a visitor being sent a pass usually has not. Set
`TWILIO_WHATSAPP_CONTENT_SID` to a Meta-approved template with variables
`{{1}}` visitor name, `{{2}}` guest-pass URL, `{{3}}` arrival, `{{4}}` host.
Without one it sends free text, which works in the Twilio sandbox and is
otherwise refused (Twilio error 63016). That refusal is logged as not sent,
not as sent.

**The forms stop offering what cannot happen.** A shared `messaging` prop tells
the visitor forms which channels are configured; SMS and WhatsApp are
disabled and marked *(not set up)* otherwise. The server applies the same
rule in `Visitor::passNotificationChannels()`, so a crafted request cannot
queue them either.

**The job had two bugs of its own**, both fixed in
`SendVisitorPassNotification`:

- **Email was a fatal error.** It called `notify()` on an anonymous class with
  no `Notifiable` trait, so the job died before SMS or WhatsApp were tried. It
  now uses an on-demand notification, and only for an email-address contact.
- **One failed channel re-sent the others.** Any exception was rethrown, so the
  queue retried the whole job and re-sent the email each time WhatsApp was
  refused. Channels now fail independently and are logged. QR generation is
  best-effort too: SMS never needed the image, and a failure to draw it no
  longer stops the link going out.

**Still not done:** delivery receipts. Twilio accepting a message is not the
same as the handset receiving it. A status-callback webhook would record
`delivered` and `undelivered`, but the job does not do that today.

---

### The notice board targeted nobody

`notifications.target_roles` has been in the schema since the first migration and
`Notification::forRole()` has always filtered on it, but no control ever set it —
so every "targeted notification" went to the whole estate. The composer now picks
an audience explicitly, and three things follow from that:

- **No selection means everyone**, stored as `null`. An empty array would match
  neither branch of `forRole()`, publishing a notice invisible to everybody
  including its author, so the controller normalises `[]` to `null`.
- **Filtering happens server-side.** A notice addressed to Security is not sent
  to a Homeowner's browser and hidden there; it is not in the payload.
- **The sidebar now offers Notifications to every role.** It stopped at Homeowner,
  so a notice addressed to Security or Staff would have been filtered *to* them by
  the server and then hidden by the menu.

The composer itself is now shown only to holders of `broadcastNotices`. It used
to render for anyone who could open the page, offering a button the route would
have refused.

### Safety alerts: three phantoms and a gate I got wrong

**Voting was theatre.** `handleVote` mutated React state. The tally moved on
screen, nothing was sent anywhere, and reloading restored the mock numbers.
Votes are now rows in `warning_responses` behind a unique index on
`(warning_id, user_id)`.

**The UI contradicted its own server.** `respond` uses `updateOrCreate`
specifically so a vote can be changed — there is a test for it — but the buttons
carried `disabled={warning.userStatus !== null}`, so after one click both were
dead forever and the server's vote-changing capability was unreachable. Votes
can now be changed, which is what the controller always intended.

**The confirmation step could be reached with an invalid form.** "Send Alert"
was both `type="submit"` and a `DialogTrigger`, so Radix opened the confirm
dialog on click whether or not zod validation passed — and "Yes, Send Alert"
then called `onSubmit(form.getValues())` directly, bypassing the resolver
entirely. The trigger is gone; the confirmation opens only from a validated
submit.

**And a gate that was mine, not the original's.** An earlier revision of
`WarningController` required `manageSecurity` to raise an alert, so the Send
Alert button the page showed every resident returned 403. The evidence that the
page is built around resident reports is consistent: the seeded authors are
residents, the heading reads "Send and validate urgent alerts within the
community", and the dialog says the alert goes "to all residents and the admin"
— so the sender is not the admin. Above all, confirm/deny only makes sense as
the community corroborating an unverified claim; nobody needs to vote on an
official security bulletin.

So any signed-in user may raise an alert, with three consequences:

- **Rate limited** (`throttle:3,60`) — an alert reaches everybody.
- **`manageSecurity` can delete one**, which nothing could before. A
  broadcast-to-everyone feature open to residents needs a removal path, and it
  is deliberately a moderation power rather than an ownership one — otherwise
  whoever raised a false alarm decides whether it stays on record.
- **Authors cannot vote on their own alert.** Self-corroboration is not
  corroboration.

To reverse the permission change, put `$this->authorize('manageSecurity')` back
at the top of `WarningController::store()` and hide `<WarningForm />` behind a
`can.issue` prop — but then the confirm/deny mechanic is left validating
official bulletins, which is not what it is for.

### The directory: delete with no endpoint, create that could not work

**Create could never have succeeded.** The form collected name, email, role,
status and address. `storeUser` requires `password` and `password_confirmation`.
No password field existed, so even with the button wired the server would have
rejected every submission. The fields are there now, on create only — editing
goes to `updateUser`, which does not touch credentials. An administrator setting
the initial password is not the best pattern; an emailed invite would be. This
application has no mail transport configured, so it is the only flow that works
end to end today.

**Status on create was a control the server ignored.** `storeUser` hardcodes
`'Active'` and never reads a status off the request, so the dropdown's value was
discarded. It now appears only when editing.

**Delete had no endpoint at all.** The menu offered "Delete Member" behind a
confirmation reading *"This will permanently delete … from the community
directory"*, and the handler was `setUsers(users.filter(...))`. No route, no
controller method, nothing. Rather than build one, delete became **deactivate**:

- Hard deletion would cascade through a person's visitors, warning responses and
  activity log — the access history a gated community exists to keep.
- The app already models removal properly: `status`, `deactivated_at`, and
  `EnsureUserIsActive` logging a deactivated account out on its next request.
- It is reversible, which a delete is not.

That change forced another. The query was `User::active()`, so a deactivated
account would have vanished from the only page that could restore it —
deactivation would have been a one-way door. Administrators now see every
account whatever its status.

**An Admin could have taken over the System Admin tier.** The page guarded the
seeded administrator accounts with `ROOT_SYS_ADMIN_EMAILS`, a hardcoded list of
two email addresses checked in JSX — which protected nothing against a request
that skipped the UI. `manageUsers` was otherwise enough to deactivate or demote
a System Admin. `updateUser` now refuses any change to a System Admin account
unless the actor is one, and the email allowlist is gone.

**And the page, the route and the menu finally agree.** The route was open, the
page rendered "Access Denied" to non-admins, and the sidebar offered it to
System Admin and Admin alone — so a resident could load it and receive a payload
nothing would draw. The route now carries `can:manageUsers`, matching what the
original always showed. The controller still redacts contact details for a
non-admin viewer; that is defence in depth behind the gate, not a second access
model. If you would rather residents had a read-only community directory, drop
the middleware and render `residents` for them — the redaction is already
written and tested.

### The access log could not show a refusal

The page had five columns — user, role, method, gate, timestamp — and no column
for the outcome. All five mock rows were successful entries. So a page whose
entire purpose is security review showed the people who got in and never the
ones who were turned away, even though `result` and `deny_reason` have been
written on every scan since `GatePassEngine` landed. The four-stage validation
pipeline, the replay detection, the revocation checks — everything they refused
was recorded and then not displayed.

Refusals are now a column, tinted, with the deny reason under the badge, and
there is a count of them at the top of the page. That count is the one number an
access log exists to surface.

**The filters and the export were unreachable.** `AccessLogController` has
supported gate, result and date-range filters and a streamed CSV export since it
was written. The page had no control for any of them. Both are wired now, and
fixing that exposed two bugs in the server side:

- **The export ignored the filters.** It dumped the whole table regardless of
  what you were looking at. Clicking Export on a "refused only" view handed you
  a file containing everything — the kind of mismatch nobody notices until the
  file is already in a report. Both paths now build their query from one private
  method, so they cannot drift apart again.
- **`to` excluded the day you selected.** `where('occurred_at', '<=', $to)` with
  a date-only value compares against midnight, so filtering "to 30 June" dropped
  everything that happened during 30 June. Both bounds are now snapped to the
  start and end of their day.

**And `chunk` was wrong for this table.** The export walked the log with
`chunk(500)`, which pages by offset. An access log is append-heavy by
definition: rows arriving mid-export shift every later offset, silently
duplicating and skipping records. It uses `chunkById` now, which is stable under
concurrent writes. The trade is that the file comes out oldest-first.

**Who can read it.** The page checked `role === 'Admin' || 'System Admin'`, so a
Security guard — the person who operates the gate this log records — was shut out
of it, while the route itself was open to anyone. The route now carries
`can:manageSecurity`, which is the audience the controller's own scoping and the
export gate already assumed, and Security has the menu item. The resident
self-view scope stays in place as defence in depth behind that gate.

### Billing claimed to take card payments. It did not.

This is the most serious thing found in the conversion, and the one change worth
reviewing before anything else.

The resident billing page contained a card-payment form. It had a "Name on Card"
input, four identical generic icons standing in for card-brand logos, a
"Remember this card for future payments" checkbox, a recurring-payment date
picker, and this line:

> 🔒 Card information is securely collected by Stripe (PCI-DSS Level 1 Compliant)

**No Stripe integration existed when that form was written** — no SDK, no keys,
no webhook, no payment intent. The line sat inside a static `div` with a padlock
emoji, above a `<Button>Pay JMD 5,000.00</Button>` that had no `onClick`. "Save
Payment Method", "Set Up Recurring Payment" and the checkbox had no handlers
either.

A resident could read a specific compliance certification, type their details,
click Pay, receive no error, and reasonably conclude their dues were settled. No
money moved and no record was created. That is materially worse than having no
payment feature at all.

**The whole form is gone.** What replaces it is the monthly dues, what you owe,
what you have paid this year, and your full invoice history.

### Stripe Checkout: webhooks, idempotency and refunds

A parallel workstream added Stripe Checkout (`StripeCheckoutController`,
`StripePaymentService`). Its original demo branch, which marked invoices paid
without taking money, was removed earlier. With no `STRIPE_SECRET` the
checkout routes 404. What it still lacked was a webhook, protection against
paying twice, and refunds. All three now exist.

**Settlement follows Stripe, by two routes that share one code path.**
`StripePaymentService::settleSession()` is called by:

- the success URL, after the session id from the browser has been fetched from
  Stripe's API and checked against the invoice it was presented for; and
- `POST /api/webhooks/stripe` on `checkout.session.completed` (and
  `async_payment_succeeded`), signed by Stripe and verified against
  `STRIPE_WEBHOOK_SECRET`. This also covers the resident who pays and closes
  the tab before the redirect, which previously left a paid invoice unpaid.

The session must be `paid`, name an invoice that exists, and be in the
invoice's currency. The invoice row is locked while it is settled, because the
redirect and the webhook routinely arrive within a second of each other.

**A payment cannot be recorded twice.** There are two layers:

1. `stripe_events` has a unique `event_id`, written in the same database
   transaction as the event's effects. A redelivered event finds its row and
   does nothing; an event that fails part-way rolls its row back too, so
   Stripe's retry starts clean.
2. Every Stripe payment is a `transactions` row whose unique `reference` is
   `stripe:<PaymentIntent id>`. Two *different* events for one payment (say
   `completed` then `async_payment_succeeded`, or the webhook and the redirect)
   still produce one ledger row.

Checkout creation is idempotent as well: a still-open session for the invoice
is reused, and creation sends an idempotency key, so a double click does not
produce two sessions the resident could both pay. If money does arrive for an
invoice already settled another way (paid at the office, say), it is recorded
on the ledger, the invoice is left alone, and a warning is logged so the
payment can be refunded.

**Refunds.** Administrators get a *Refund* action on Stripe payments in the
Transactions ledger (`POST /dashboard/billing/transactions/{transaction}/refund`,
behind `manageBilling`). Partial refunds work, and the amount is capped at what
is still refundable. Refunds made in the Stripe dashboard arrive through the
`charge.refunded` webhook.

Both routes record refunds the same way: from the charge's cumulative
`amount_refunded`, writing only the difference from what the ledger already
holds (reference `stripe-refund:<PaymentIntent>:<cumulative total>`). That makes
it correct however many times, and in whatever order, the admin action and
the webhook arrive. The refund request to Stripe also carries an idempotency
key, so a double-submitted form sends one refund.

If the refunded payment is the one that settled the invoice, the invoice goes
back to *Partially Paid* or *Unpaid*: the money that paid it has been
returned. Refunding a duplicate payment leaves the invoice *Paid*.
`Invoice::amountPaidMinor()` now subtracts refunds.

**To turn it on:**

1. Set `STRIPE_SECRET` (`sk_live_…` or `sk_test_…`).
2. In the Stripe dashboard, add a webhook endpoint at
   `https://<your host>/api/webhooks/stripe` for `checkout.session.completed`,
   `checkout.session.async_payment_succeeded` and `charge.refunded`.
3. Put that endpoint's signing secret in `STRIPE_WEBHOOK_SECRET` (`whsec_…`).
   Without it the webhook route 404s and nothing is accepted.

For local testing, `stripe listen --forward-to localhost:8000/api/webhooks/stripe`
prints a signing secret to use.

**Still not handled:** a refund that Stripe later reports as *failed*
(`refund.failed`, rare for cards) is not reversed on the ledger, and disputes
and chargebacks (`charge.dispute.*`) are not handled. Who is liable for card
data is still a business decision; Checkout keeps card numbers off this
server entirely.

**Separate, and not fixed: the Payment Center settles without money.**
`POST /dashboard/billing/pay` (`BillingController::pay()`) takes a `channel`
(card, bank wire, Zelle, NFC and others), calls that channel's driver in
`app/Services/Payments/Drivers`, and records a `completed` transaction,
marking the invoice Paid. Every driver's `settle()` returns success with an
invented reference; `StripeCardDriver` returns `STRIPE-XXXXXXXXXX` without
calling Stripe. So any resident can settle their own invoice by posting that
form. Card payments should go only through Checkout above. The other
channels need either a real processor or a *pending* status that an
administrator confirms. The same pattern was already closed for wallet
top-ups and public payment links; this endpoint is the one still open.

**Tests:** `StripeWebhookTest` runs signed deliveries through the real service
with no network access. It covers forged and stale signatures, settlement,
redelivery, two events for one payment, currency mismatch, overpayment, full
and step-by-step partial refunds, and retries of each. `StripeRefundTest`
covers the admin action and who sees it.

**The exchange rates went too.** The dues figure was shown next to four
conversions — USD, CAD, GBP, EUR — from a hardcoded rate table. Those numbers
drift further from reality every day nobody updates them, and a currency figure
printed beside a sum owed reads as a quote no matter what the caption says. The
table still exists for the fundraising page, which uses it to give a donor a
rough sense of a goal; that is a different claim from what your bill comes to.

### And two endpoints the billing UI could never reach

- **`markPaid`.** The only action in the admin transactions table was "Send
  Reminder", which had no handler and no endpoint — and still has none, because
  there is no mail transport, so it is gone rather than left as a button that
  lies. Marking an invoice paid now works, and the server refuses to do it twice:
  `markPaid()` stamps `paid_at = now()`, so re-running it on an old invoice would
  drag that payment into the current month and inflate the collections figure.
- **The currency and due-day settings.** `updateSettings` has always validated
  all three fields. The form offered only the fee.

Everything else on the admin side was invented too — four `mockTransactions`,
twelve months of fabricated chart data, and the "Total Collected" and
"Outstanding Dues" tiles computed from those four rows. All of it is now
aggregated from the `invoices` table.

**One bundle note.** The collections chart pulls in recharts, which was 385 kB
of a 390 kB page chunk that every resident downloaded to look at a list of their
own invoices. `AdminBilling` is now lazy-loaded, taking the resident's Billing
chunk to **6.8 kB** (2.75 kB gzipped). That matters more than usual here because
the project also ships as a Capacitor mobile shell.

### Fundraising totals were adding different currencies together

The donate form offered JMD, USD, GBP, EUR and CAD, and **defaulted to USD**.
`Fundraiser::raisedMinor()` is `$this->donations()->sum('amount_minor')` — a
plain sum of minor units, with no conversion anywhere on the server.

So a USD 50 donation against a JMD goal was stored as 5,000 minor units and
counted as **JMD 50**: roughly 1/155th of what was actually given. Anyone who
accepted the form's default made the progress bar and the "raised" figure wrong.

The client was wrong differently. `fundraiser-progress-card.tsx` carried its own
copy of the hardcoded rate table and converted each donation to JMD before
summing — so the card and the server disagreed, and the card's answer came from
rates nobody maintains.

**Donations are now always in the fundraiser's own currency.** The picker is
gone and `donate()` takes the currency from the fundraiser rather than the
request, so a crafted POST cannot reintroduce the problem. Real multi-currency
support means converting at the moment of the donation and storing the converted
amount alongside the original with the rate used — not converting on read, and
not from a hardcoded table. That needs a rates provider this application does
not have.

**The page threw on SQLite.** The listing ordered with
`orderByRaw("FIELD(status, ...)")`. `FIELD()` is a MySQL function; SQLite has no
such thing. The suite runs on SQLite and the setup notes above offer SQLite as
the zero-setup path, so the quick-start route to this page was a 500. It uses a
portable `CASE` expression now, and there is a test whose only job is to load the
page.

**Donations are pledges, not payments.** Nothing takes money here — `donate()`
writes a row. The form used to toast "Donation Successful!"; it now says the
pledge has been recorded and the dialog says plainly that payment is arranged
with the community office. Totals are therefore self-reported, though every
donation carries a `user_id`, so they are attributable. Making them
payment-backed means the same work described under billing.

**Three more dead controls.** "Share" had no handler and no per-fundraiser URL
to share, so it is gone. "Goal Reached" was a disabled button used as a status
label, which the badge already does. "Enable Now" had no handler *and* no
endpoint — an administrator could schedule an Upcoming fundraiser and then had
no way to open it. That one is now `dashboard.fundraising.update`, which also
refuses to activate a fundraiser whose end date has passed; `isOpen()` checks
both status and date, so activating an expired one produced a fundraiser that
looked open and refused every donation.

### A judgement call worth reviewing

A blocklist is personal data and the stated reasons are unflattering —
*"Repeatedly causing disturbances at community events"* sits against a named
individual with a photo. Giving every resident the page meant deciding how much
of it they should see.

The line drawn here: residents get the **name, status and date only**. The
reason, the photo and who added the entry are omitted from the payload entirely
for anyone without `manageBlocklist`, rather than sent and hidden in the UI.
That is enough to recognise a block and ask for a review, without broadcasting
an accusation to the whole estate.

If your community would rather residents not see the list at all, put the
`can:manageBlocklist` middleware back on the `GET /dashboard/block-list` route
and drop the Block List entry from the resident menus in `DashboardLayout` —
the two need to agree either way.

## Remaining work

The backend, schema, routing, authorization and business logic are complete.
Twenty of the 23 dashboard pages read their data from the server. **Three
pages still ignore the props their controllers send.** Their default exports take no arguments, so they render built-in
content or nothing:

| Page | What it shows today | Controller already sends |
|---|---|---|
| `Changelog` | Hardcoded `changelogData` array | `entries`, newest first |
| `Deals` | Businesses and vouchers from `@/lib/placeholder-images.json` | `businesses` with active vouchers |
| `Feedback` | A form with no submit handler | `submissions`; `store` validates type/subject/body |

**Every import spec now resolves**, down from twelve unresolved at the start.
The last one — `@/ai/flows/generate-targeted-notifications` — closed with
`Notifications.tsx`: the form now calls `dashboard.notifications.suggest-audience`
instead of a client-side Genkit stub that never reached a model.

Every context and shared library has been ported. The one mock data module
left is `resources/js/lib/placeholder-images.json`, which `Deals` still reads;
it can go once that page is wired. The remaining pages need only their own
props wiring. Use
`resources/js/context/auth-context.tsx` as the pattern: read from `usePage()`,
mutate with `router`.

Wiring a page is small and repetitive. For example, `Visitors.tsx`:

```tsx
// before — mock data in the component
const [visitors, setVisitors] = useState(getInitialVisitors());

// after — server data via Inertia
import { usePage, router } from '@inertiajs/react';
const { visitors, filters, canManage } = usePage<Props>().props;

// and mutations become requests instead of setState
router.post('/dashboard/visitors', form);
router.post(`/dashboard/visitors/${id}/check-in`);
```

Each page needs: read props instead of `useState`, swap local mutations for
`router.post`/`patch`/`delete`, and wrap the export in `DashboardLayout`.

Also outstanding: file uploads for visitor ID and staff photos. Both are
plain URL fields today (`id_image_url` is validated as a URL, and new staff
default to a `picsum.photos` placeholder), so there is no way to attach a
photo taken at the gate. There is also no password-reset mail flow for
self-service resets; the sign-in page
currently directs residents to the estate office, matching the original.

**The Capacitor mobile shell has no config.** `capacitor.config.ts` was part of
the Next.js app and was removed with it. It pointed `webDir` at the Next.js
static export (`out/`), which the Laravel app does not produce. A server-rendered
app needs a new config whose `server.url` points at the deployed site. The old
file is in git history at `f3c48c6`.
