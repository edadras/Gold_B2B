# Trader web panel — `resources/`

Implements part A of `docs/08-frontend-web/01-web-panels.md` (§1.1–1.6). Served
by `app/Modules/Web` at `/app`.

```
resources/
├── css/panel.css               ◄── the whole stylesheet, hand-written
├── views/web/
│   ├── panel.blade.php         ◄── the SPA shell
│   └── login.blade.php
└── js/
    ├── app.js                  ◄── entry; mounts Panel or Login
    ├── Panel.vue               ◄── root: client routing + `?` help
    ├── lib/                    ◄── framework-free, `node --test`-able
    │   ├── numeric-input.js    ◄── NumericInput  (Persian digits, BigInt)
    │   ├── int-math.js         ◄── IntMath       (floor/ceil/remainder)
    │   ├── value-objects.js    ◄── Weight, FineWeight, Purity, Rial, Price…
    │   ├── negotiation.js      ◄── OTC/RFQ: notional, quote ranking, expiry
    │   ├── enum-catalogue.js   ◄── reading GET /meta/enums
    │   ├── kyc-checklist.js    ◄── missing_items → instructions
    │   ├── digest.js           ◄── SHA-256 of an evidence file, in-browser
    │   ├── format.js           ◄── Display.php's client half
    │   ├── jalali.js           ◄── JalaliDate.php, ported line for line
    │   ├── api.js              ◄── the ONLY place that calls fetch
    │   ├── feed.js             ◄── WebSocket-or-poll transport + staleness
    │   └── __tests__/          ◄── node --test
    ├── Stores/                 ◄── panel.js, market.js, enums.js
    ├── Composables/            ◄── useKeyboard.js, useFormatting.js, useFreshness.js
    ├── Layouts/                ◄── AppLayout.vue
    ├── Components/             ◄── Market/, Data/, Common/, Otc/, Rfq/, Disputes/, Settings/
    └── Pages/                  ◄── Market/Terminal.vue, Ledger/, …
```

## Running it

```bash
npm install
npm run build          # or: npm run dev
node --test "resources/js/lib/__tests__/*.test.js"
composer run test:js   # same thing, from PHP tooling
npm test               # same thing, from JS tooling
DB_DATABASE=goldb2b_test_d vendor/bin/phpunit app/Modules/Web/Tests
```

### Provider registration

`bootstrap/providers.php` discovers module providers from `app/Modules/`, so
`WebServiceProvider` is picked up with no edit to that file — confirm with
`php artisan route:list --path=app`. `app/Modules/Web/Tests/WebTestCase` also
registers it explicitly, so the module's tests do not depend on that discovery
still being in place.

Note the routes file is `Http/web.php`, not `Http/routes.php`:
`ModuleServiceProvider` publishes the latter under `/api/v1` with the `api`
middleware group, and none of this is API surface. `WebServiceProvider::boot()`
mounts `web.php` under `/app` with the `web` group instead.

## What was built, and what was not

| Doc | Built |
|---|---|
| §1.3 trading terminal | three columns, instrument rail, balance rail, ticker, chart, depth ladder, open orders, ticket, tape |
| §1.3 keyboard | B/S, Esc, Enter, Ctrl+Enter, ↑/↓, Shift+↑/↓, Ctrl+A, 1–9, `/`, `?` |
| §1.4 data table | sort, filter, search, weight/money/Jalali/status cells, totals row, cursor pagination, async export |
| §1.5 ledger | running statement, per-row link to the source document, closing-balance breakdown, reconciliation banner |
| §1.6 WebSocket | implemented as an interface with a polling fallback — see below |
| Settlements, orders, trades, lots, counterparties, reports | built on the §1.4 table |
| OTC (§4.6) | offer list by direction, create, counter, accept, reject, cancel, live countdowns |
| RFQ (§4.7) | my requests and inbox, create, quote, withdraw, side-aware quote ranking, accept (incl. partial) |
| Disputes (§13) | list, open a case, merged timeline + transcript, reply, accept ✍️, escalate, withdraw, evidence with a browser-computed SHA-256 |
| KYC (§2.2) | status and `missing_items` checklist, document upload/delete, licences, bank accounts, submit / resubmit |
| Team (§2.2) | member list, invite, role editing, deactivation |
| Settings | notification preferences, webhooks (register, rotate, test, deliveries), profile, sessions, organisation contact details |
| Vault operations | **not built** — endpoints exist, screens do not |

---

## Vue vs. Inertia: what this is, and what would change

The doc specifies **Laravel + Inertia + Vue 3**. This is **Vue 3 + Vite without
Inertia**, because `composer require inertiajs/inertia-laravel` fails in this
environment: the package proxy refuses GitHub authentication, so the dist
download 404s and the source fallback cannot authenticate either. The npm half
(`@inertiajs/vue3`) installs fine; the server adapter is the blocker, and
Inertia without its server adapter is not Inertia.

`npm install` itself works, so **Vue, Vite and single-file components are all
real here** — this is not a no-build-step fallback. Everything below is a
description of a small, mechanical migration, not a rewrite.

### What does not change at all

- **`lib/`** — the value objects, formatting, Jalali conversion, the API client
  and the feed transport are plain ES modules with no framework import. They are
  what the Node test suite exercises, and Inertia does not touch them.
- **`Components/`, `Layouts/`, `Composables/`** — ordinary Vue 3 SFCs. Every one
  of them takes props and emits events; none reaches for a router or a page
  object.
- **`Stores/`** — `reactive()` objects with the same shape a Pinia store would
  have. `defineStore(...)` wrapping is the whole difference.
- **`resources/css/panel.css`**.

### What changes

| Piece | Now | Under Inertia |
|---|---|---|
| `app.js` | `createApp(Panel).mount('#panel')` | `createInertiaApp({ resolve, setup })` with `import.meta.glob('./Pages/**/*.vue')` |
| `Panel.vue` | 30 lines of `history.pushState` routing + a `v-if` chain | deleted; Inertia resolves the page component from the server response |
| `PanelController` | returns `view('web.panel', ['screen' => …])` | returns `Inertia::render('Market/Terminal', […])` |
| `panel.blade.php` | `<script type="application/json">` + `<div id="panel">` | `@inertia` + `@inertiaHead` |
| `PanelBootstrap` | serialised into the shell once | moves to `HandleInertiaRequests::share()`, re-sent per visit |
| Navigation | `<a @click.prevent="navigate">` | `<Link href="/app/ledger">` |
| Page props | none — every page fetches for itself | first page of each list could be server-rendered into props |
| `Pages/Auth/Login.vue` | posts to `/api/v1/auth/login`, then `/app/session` | could become an Inertia form post, but keeping the API login is better: it reuses Identity's lockout and 2FA rather than duplicating them |
| CSRF | read from `<meta name="csrf-token">` | Inertia's axios instance handles it |

Steps, in order:

1. `composer require inertiajs/inertia-laravel` and
   `npm install @inertiajs/vue3` (already in `package.json`).
2. `php artisan inertia:middleware`, then add `HandleInertiaRequests` to the
   `/app` route group in `app/Modules/Web/Http/web.php` — **not** to
   `bootstrap/app.php`, so the API and the admin panel are unaffected.
3. Move `PanelBootstrap::forUser()` into the middleware's `share()`.
4. Replace `app.js`'s mount block with `createInertiaApp`, delete `Panel.vue`.
5. Change `PanelController::show()` to `Inertia::render()` keyed on
   `PanelScreen`.
6. Swap the nav's anchors for `<Link>`.

Nothing in `Components/`, `lib/` or `Stores/` is touched by any of those steps.

### The data path either way

The panel reads business data from `/api/v1` with a bearer token, not from
Inertia props. That is deliberate and would stay true under Inertia:

- the depth ladder, the tape, balances and open orders are **live** and refresh
  every two seconds; Inertia props are a page-load snapshot;
- it keeps `app/Modules/Web` depending on nothing but `Shared` and `Identity`
  (see the ALLOWED map in `tests/Architecture/ArchitectureTest.php`) — a PHP
  controller assembling terminal data would have to import Trading, Ledger,
  Pricing, Custody and Settlement; and
- the API's own tenancy and permission checks run on every request instead of
  being re-implemented in a second place.

---

## WebSocket vs. polling

`docs/…§1.6` describes Laravel Echo over Reverb. `config/broadcasting.php`
defaults to the `log` driver here and `laravel/reverb` is not installed, so
there is no socket for a browser to open.

`lib/feed.js` implements both against one interface. `PanelBootstrap::realtime()`
reports whether a pusher-protocol connection with a real key is configured:

- **key present + `window.Echo` loaded** → subscribe to `market.{code}` and
  `organization.{id}`, with a slow REST heartbeat to catch a silently dropped
  socket;
- **otherwise** → poll every 2 000 ms (5× slower for balances).

The top bar says which is in use — "بلادرنگ" or "به‌روزرسانی هر ۲ ثانیه" — so a
polling deployment is stated rather than mistaken for a live one. When the
broadcasting module lands, setting `BROADCAST_CONNECTION=reverb` and loading
Echo is the entire switch; no component changes.

## Requirements that shaped the code

**No floating point for money or weight.** Every monetary and weight value is a
`BigInt`. `Number` is exact only to 2^53 and `quantity_mg × price_rial` passes
that at realistic sizes — `resources/js/lib/__tests__/value-objects.test.js`
pins a vector where the float answer is one rial too high. The vectors are the
same ones as `tests/Unit/Shared/CalculationTest.php`, including
`(250 000 mg, 9950) → 248 750 mg` and `(248 750 mg, 78 480 000) → 19 521 900 000`.

**Numbers render LTR inside the RTL page.** `.num` in `panel.css` sets
`direction: ltr; unicode-bidi: isolate; font-variant-numeric: tabular-nums`.
Without the first two, "1,247.320" renders as "320.1,247" in an RTL context;
without the third, columns of figures do not align. `WeightCell`, `MoneyCell`,
`JalaliCell` and `NumCell` exist so no template hand-rolls it.

**Idempotency-Key.** `OrderForm.vue` mints a UUID when the ticket opens and
reuses it across every retry, so a double-submit places one order. It rotates
only after the server accepts or definitively rejects — a `409
IDEMPOTENCY_IN_PROGRESS` deliberately keeps the same key, because retrying with
it is what resolves that state.

**Stale data is visible.** Every live figure carries its own age. Past 30 s the
figure is greyed and a `StaleBadge` prints "۴۲ ثانیه پیش". A failed refresh
keeps the last value and lets the age climb rather than blanking the ladder.

---

## Endpoints these screens needed and did not find

Nothing below was invented client-side; each screen was built against what
`php artisan route:list --path=api/v1` actually serves, and the gaps are listed
rather than papered over.

| Wanted by | Endpoint | State |
|---|---|---|
| Disputes — «post a message in the negotiation room» | `POST /disputes/{id}/messages` | `PostDisputeMessageRequest` exists and is documented as §2.12 «پیام در مذاکره», but **no route registers it**. The panel therefore posts prose through `POST /disputes/{id}/reply`, which is the respondent's answer and carries a different meaning; a claimant cannot add a message at all. |
| Disputes — settlement offers inside a case | `POST /disputes/{id}/propose-settlement` | `ProposeSettlementRequest` and `MessageType::isProposal()` exist and `GET /disputes/{id}` returns proposal messages, but **no route registers the write**. Proposals are rendered read-only; there is no way to make one. |
| KYC — «respond to an information request» | none | `INFO_REQUIRED` is a real status and the dossier becomes editable in it, but `KycProfileResource` deliberately withholds the officer's note (`last_decision_note`) and no endpoint accepts a written reply. Responding is expressed as the platform models it: fix the outstanding items, then `POST /organization/kyc/submit`. |
| Settings — API keys | none | There are no personal-access-token endpoints. The panel's bearer token comes from `/auth/login`; nothing lists, mints or revokes a long-lived key, so the tab covers webhooks (which are real) and says so. |
| Team — reactivate a user | none | `DELETE /organization/users/{id}` disables; there is no inverse. A disabled colleague is shown as such with no button. |

## Deliberately left out

- **Two-factor enrolment.** `/auth/2fa/enable` → `/auth/2fa/confirm` needs a QR
  code and a verification step, and `DELETE /auth/2fa` is ✍️ signed. Half an
  enrolment flow is worse than none, so Settings states the current setting and
  stops there.
- **The transaction-signature (✍️) prompt itself.** Accepting a dispute claim
  and adding a bank account both require `X-Transaction-Signature`. There is no
  shared OTP dialog in the panel — the existing settlement screen has the same
  gap — so both screens label the action ✍️ and report
  `AUTH_TRANSACTION_SIGN_REQUIRED` as the second factor it is rather than as a
  failure. One dialog would serve all four call sites and is the obvious next
  piece of work.
- **Vault operations**, which were out of scope for this pass.
