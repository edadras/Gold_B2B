# Gold B2B — Flutter client

---

## ⚠️ READ THIS FIRST: this project has never been compiled

**This entire application was written without a Flutter SDK present on the
machine.** The SDK could not be installed in the authoring environment (no
network access to the Flutter distribution), which means that at the time of
writing:

- `flutter create` was never run — every file, including `pubspec.yaml`,
  `analysis_options.yaml` and the Android/iOS files, was hand-written;
- **`flutter pub get` has never been run** — no dependency has been resolved,
  and no version constraint in `pubspec.yaml` has been checked against pub.dev;
- **`flutter analyze` has never been run** — no type error, no missing import
  and no lint violation has been caught by a tool;
- **`flutter test` has never been run** — the tests under `test/` have never
  executed. They are written to pass, and their vectors are copied from the
  passing PHP suite, but that is an argument, not evidence;
- **the app has never been built or launched on any device or emulator.**

Treat the first `flutter analyze` as a real code review that will find real
problems. Expect to fix things. The likely categories are listed under
[Known unverified areas](#known-unverified-areas) below.

### Commands a developer must run first, in this order

```sh
cd mobile

# 1. Generate the platform scaffolding that only the SDK can produce
#    (Gradle files, Xcode project, launcher icons, LaunchScreen, and so on).
#    This does NOT overwrite the files already committed here — but check
#    `git status` afterwards and restore anything it did touch. The files that
#    must survive are listed under "Platform files that must not be lost".
flutter create --project-name gold_b2b --org ir.goldb2b \
               --platforms=android,ios --no-overwrite .

# 2. Resolve dependencies. If any constraint fails to resolve, see
#    "Dependency versions" below.
flutter pub get

# 3. Static analysis. This is the first real check the code has ever had.
flutter analyze

# 4. Tests. Start with the value-object tests — if those fail, nothing else
#    matters, because the client and the server disagree about arithmetic.
flutter test test/models/
flutter test

# 5. The no-float guard (see "No double, anywhere").
./tool/no_double_in_money.sh

# 6. Run it.
flutter run --dart-define=FLAVOR=dev
```

### Fonts

`pubspec.yaml` declares a `Vazirmatn` font family whose `.ttf` files are **not
in the repository**. Either download them into `assets/fonts/` from
<https://github.com/rastikerdar/vazirmatn/releases>, or comment out the
`fonts:` block in `pubspec.yaml` — the build fails on a declared-but-missing
font asset.

---

## What this app is

The mobile client for the Gold B2B inter-dealer trading platform. It covers
what a trader does while moving around
(docs/07-mobile-flutter/01-architecture.md §1.1):

| In the app | On the web panel |
|---|---|
| Balances and prices | KYC and document upload |
| Placing and cancelling orders | Detailed accounting |
| OTC offers and counter-offers | Heavy reports |
| Answering RFQs | User and role management |
| Settlement confirmation | Vault operations |
| Live notifications | Dispute handling (notify + view only on mobile) |
| QR authenticity verification | |
| Accepting netting proposals | |

---

## The five rules this codebase is organised around

These are not style preferences. Each one exists because breaking it costs
somebody money.

### 1. No `double` for money or weight

Money is `int` rial. Weight is `int` milligrams. Purity is `int`
ten-thousandths. The value objects in `lib/shared/models/` are Dart ports of
`app/Modules/Shared/ValueObjects/` and mirror them method for method:

| Dart | PHP |
|---|---|
| `FineWeight` | `App\Modules\Shared\ValueObjects\FineWeight` |
| `Weight` | `…\Weight` |
| `Purity` | `…\Purity` |
| `Rial` | `…\Rial` |
| `PricePerFineGram` | `…\PricePerFineGram` |
| `NumericInput` | `…\NumericInput` |
| `IntMath` | `App\Modules\Shared\Support\IntMath` (bcmath → `BigInt`) |
| `TradeValueCalculator` | `App\Modules\Shared\Calculation\TradeValueCalculator` |

`test/models/calculation_test.dart` ports the vectors from
`tests/Unit/Shared/CalculationTest.php` **verbatim**, so the two
implementations are provably consistent — including the two that matter most:

```
F1: fine weight   (250,000 mg, purity 9,950)     -> 248,750 mg
F5: gross amount  (248,750 mg, 78,480,000 r/g)   -> 19,521,900,000 rial
```

If you change a vector on one side, change it on the other. If you cannot, the
implementations have diverged and one of them is wrong.

`tool/no_double_in_money.sh` fails CI if the token `double` appears anywhere
under `lib/shared/models/`, `lib/core/formatting/` or any feature's `data/`,
`application/` or `domain/` directory. There are exactly two sanctioned
exceptions, both annotated `ALLOW_DOUBLE_DISPLAY_ONLY` and both producing a
string or a pixel width that is never computed on:
`Rial.displayCompact` and `WeightFormatter.ratio`.

### 2. Numbers render LTR inside an RTL layout

The app is forced RTL at the root (`app.dart`). Digits are strongly LTR in the
bidi algorithm but commas and full stops are **neutral**, so a formatted figure
laid out RTL can render as `۳۲۰.۱,۲۴۷` instead of `۱,۲۴۷.۳۲۰`.

Every number therefore goes through `LtrNumber`, or one of the widgets built on
it — `WeightDisplay`, `GrossWeightDisplay`, `MoneyDisplay`, `PriceDisplay`,
`ChangeDisplay`. **A bare `Text` containing a formatted figure is a bug.**

Table and column styles carry `FontFeature.tabularFigures()`
(`AppTypography.numeric`, `.numericSmall`, `.numericLarge`, `.code`) so a depth
ladder does not wobble as prices change width.

### 3. An idempotency key survives retries

This is the single most dangerous thing in the app. `docs/05-api/01-conventions`
§1.10 and `docs/07-mobile-flutter/01-architecture` §1.6 both say it: a request
retried after a network failure must carry **the same** `Idempotency-Key`, or a
response lost in transit becomes a second order at a moved price.

The key is minted **once, at the moment the user's intent is formed** —
`OrderFormController`'s constructor, not the submit handler — and is reused by
every delivery attempt:

| Path | Mechanism |
|---|---|
| Transport retry after a dropped connection | `RetryInterceptor` re-issues the same `RequestOptions` instance |
| Replay after a 401 token refresh | `AuthInterceptor` replays the same `RequestOptions` instance |
| User taps "try again" after a timeout | `OrderFormState.idempotencyKey` is unchanged on failure |
| Safety net for any path that lost the header | `IdempotencyInterceptor` restores it from `options.extra` |

A new key is minted only by `OrderFormController.resetForNextOrder()`, which is
called **after** an order has been accepted.

`RetryInterceptor` refuses to retry a non-GET request that has no idempotency
key, no matter how transient the failure looked.

### 4. Offline is read-only

`docs/07-mobile-flutter/01-architecture` §1.9 is explicit that there is **no
offline write queue**, because prices move and executing a ten-minute-old
intent is dangerous. So:

- cached data (currently the balance snapshot, in `flutter_secure_storage`)
  renders greyed, with a mandatory `StalenessLabel` showing its age;
- every trading and settlement control is wrapped in `OfflineActionGuard`,
  which disables it and states the reason;
- `ConnectivityMonitor` derives online/degraded/offline from **actual request
  outcomes** plus the WebSocket state, not from an OS connectivity flag — a
  phone on a captive-portal Wi-Fi reports "connected" and can reach nothing.

### 5. WebSocket is for updates, not for truth

`docs/05-api/03-realtime-webhooks` §3.4. The socket patches what is on screen
so a fill appears instantly; it never becomes authoritative. After **every**
connection — including the first — `RealtimeSync` re-reads balances, open
orders and the pending-settlement queue from REST.

`WebSocketService` speaks the Pusher wire protocol against Laravel Reverb over
a raw socket (`web_socket_channel`) rather than using
`pusher_channels_flutter`, precisely so that reconnect behaviour is
observable and controllable from Dart. Backoff is 1s, 2s, 4s, 8s, 16s, then 30s
forever, and a socket that stops answering pings is treated as dead rather than
being allowed to look live.

---

## Architecture

```
lib/
├── main.dart                     flavour selection, ProviderScope override
├── app.dart                      MaterialApp.router, RTL, text scaling, lock scope
├── core/
│   ├── config/                   AppConfig (dev/staging/prod), constants
│   ├── network/                  ApiClient, typed failures, interceptors, pinning
│   ├── realtime/                 WebSocketService, RealtimeSync
│   ├── connectivity/             ConnectivityMonitor
│   ├── storage/                  SecureStorage (tokens + small encrypted cache)
│   ├── formatting/               weight / money / percent / purity / Jalali
│   ├── security/                 app lock, FLAG_SECURE, biometric authenticator
│   ├── router/                   GoRouter + the single redirect
│   └── theme/                    colours, typography, ThemeData
├── shared/
│   ├── models/                   value objects, IntMath, NumericInput, Paginated
│   ├── widgets/                  LtrNumber, displays, ConfirmSheet, ErrorView, …
│   └── extensions/               JSON accessors, Persian digits, deadline maths
└── features/<name>/
    ├── data/                     repository + DTOs (parse by hand, no codegen)
    ├── application/              Riverpod StateNotifier / providers
    └── presentation/             screens + widgets
```

Features: `auth`, `dashboard`, `market`, `orders`, `otc`, `rfq`, `settlements`
(including netting), `lots`, `counterparties`, `notifications`, `settings`.

### No code generation

There is **no `build_runner` step**. No freezed, no json_serializable, no
riverpod_generator. Every `fromJson`, `copyWith` and provider is written by
hand, and providers use the non-generated API (`StateNotifierProvider`,
`FutureProvider`, `Provider`, `StreamProvider`).

This was a deliberate constraint of the authoring environment, but it is worth
keeping: adding a generator means every developer and every CI job needs a
successful `dart run build_runner build` before the app will compile at all.

### The one deliberate layering exception

`core/realtime/realtime_sync.dart` imports from `features/`. It is the
composition root for realtime: deciding which private channels to subscribe for
the signed-in organisation, and what "resynchronise" means, cannot be done by
any single feature. Splitting it would mean each feature independently deciding
what to reload after a reconnect, and the first one to get it wrong would be a
silent balance bug.

---

## Security posture

| Concern | Where |
|---|---|
| Tokens at rest | `SecureStorage` → Keychain (`first_unlock_this_device`, no iCloud) / EncryptedSharedPreferences |
| Screenshot / recents blocking | `SecureScreen` → `secure_screen` MethodChannel → `FLAG_SECURE` in `MainActivity.kt` |
| Auto-lock | `AppLockController`, 5 min idle (`AppConfig.idleLockTimeout`), plus lock on long background |
| High-value re-confirmation | `confirmFinancialAction()` → breakdown sheet → biometric → TOTP fallback |
| Certificate pinning | `CertificatePinning`, SHA-256 of the leaf DER, fingerprints per flavour |
| Cleartext | Blocked: `usesCleartextTraffic="false"` + `network_security_config.xml`; ATS on with no exceptions on iOS |
| Backup exfiltration | `allowBackup="false"` + `data_extraction_rules.xml` excluding everything |
| Log leakage | `LoggingInterceptor` redacts tokens, OTP/TOTP codes, passwords; installed only in debug |

### Certificate pinning

`AppConfig.prod().certificateFingerprints` is **empty**, which disables
pinning. That is correct for dev and staging and is a **release blocker for
production**. To populate it:

```sh
openssl s_client -connect api.goldb2b.ir:443 -servername api.goldb2b.ir \
  < /dev/null 2>/dev/null \
  | openssl x509 -outform DER \
  | openssl dgst -sha256 -hex
```

Pin **at least two** fingerprints — the certificate in use and the already
issued backup — and ship the new pin set in a release *before* rotating the
certificate on the server. Pinning a single leaf means the day it rotates,
every installed copy of the app stops working and the only fix is an app-store
release.

### FLAG_SECURE on iOS

There is no iOS equivalent and screenshots cannot be blocked. The `secure_screen`
channel is **not implemented on iOS** — see the `TODO(mobile-platform)` in
`lib/core/security/secure_screen.dart` for what a developer must add to
`AppDelegate.swift` (an opaque overlay on `applicationWillResignActive`, plus
`UIScreen.main.isCaptured` reporting). Until then `SecureScreen` degrades to a
no-op on iOS, which is safe but leaves balances visible in the app switcher.

---

## Flavours

Configuration comes from `--dart-define`; `appConfigProvider` throws if it is
not overridden, so a build cannot silently fall back to a default host.

```sh
# dev — points at a Laravel server on the emulator's host loopback
flutter run --dart-define=FLAVOR=dev

# staging
flutter run --dart-define=FLAVOR=staging \
            --dart-define=REVERB_APP_KEY=...

# production
flutter build apk --release \
  --dart-define=FLAVOR=prod \
  --dart-define=REVERB_APP_KEY=... \
  --dart-define=APP_VERSION=1.2.3
```

---

## Platform files that must not be lost

`flutter create` may regenerate these. They carry the security posture and
must survive:

- `android/app/src/main/AndroidManifest.xml`
- `android/app/src/main/res/xml/network_security_config.xml`
- `android/app/src/main/res/xml/data_extraction_rules.xml`
- `android/app/src/main/kotlin/ir/goldb2b/mobile/MainActivity.kt`
- `ios/Runner/Info.plist`

Diff them after step 1 above. `MainActivity.kt` in particular is the only
native code the project owns, and losing it silently disables FLAG_SECURE on
every financial screen.

### Deep links

QR verification links (`https://goldb2b.ir/verify/<token>`) are wired on
Android via the `autoVerify` intent filter in the manifest, which needs
`https://goldb2b.ir/.well-known/assetlinks.json` to be served. On iOS this
needs an Associated Domains entitlement (`applinks:goldb2b.ir`) in
`Runner.entitlements` and a matching `apple-app-site-association` file — **not
configured in this repository**.

---

## Known unverified areas

Ordered by how likely they are to bite on the first `flutter analyze`.

1. **Dependency versions.** Every constraint in `pubspec.yaml` is a caret range
   picked from memory and never resolved. If resolution fails, run
   `flutter pub upgrade --major-versions` and then re-check the specific API
   uses listed below.
2. **`IOHttpClientAdapter.validateCertificate`** (`certificate_pinning.dart`).
   Present in dio ≥ 5.0; if the resolved dio differs, this is where it will
   fail to compile.
3. **`CardTheme` vs `CardThemeData`** (`app_theme.dart`). Flutter renamed this
   around 3.27. The code uses `CardTheme`, matching the declared
   `flutter: ">=3.19.0"`. On a newer SDK, change the type.
4. **`Color.withOpacity`** is used throughout rather than `withValues`, again
   for 3.19 compatibility. Deprecated on newer SDKs but still functional.
5. **`shamsi_date` API surface.** Only `Jalali.fromDateTime`, `.year`,
   `.month`, `.day` and `.weekDay` are used, all long-standing.
6. **`mobile_scanner` API surface.** `MobileScannerController`,
   `BarcodeCapture`, `DetectionSpeed`, `BarcodeFormat` and `toggleTorch` are
   used; this package changes its API between majors more than most.
7. **`MediaQuery.withClampedTextScaling`** (`app.dart`) requires Flutter 3.16+.
8. **Every screen is unverified visually.** No layout has been rendered. Expect
   overflow warnings, particularly in the RTL rows that mix Persian labels with
   LTR numbers.

---

## Places where the API contract had to be guessed

Each of these is marked with a `TODO(backend)` at the point of use. The
guesses fail *soft* — a missing field yields a documented default rather than a
crash — but a wrong guess means a wrong number on screen, so confirm them
before release.

| Area | What is documented | What was assumed |
|---|---|---|
| `GET /meta/settings` | Endpoint exists (§2.17) | Body shape `{fees: {buyer_rate_x100k, seller_rate_x100k, …}, tax: {…}}`. Falls back to 0.15%/0.10%, the rates used throughout the formula appendix. Drives the order form's fee preview only; the executed trade carries the server's own figures. |
| Settlement resource | Endpoints only (§2.8) | Field names inferred from the §2.5 screen mockups, the `settlement.status_changed` event and the `settlement.completed` webhook. `destination_account` in particular appears in no documented payload but the payment-declaration screen cannot work without it. |
| Netting batch resource | Endpoints only (§2.9) | Fields inferred from the §2.9 mockup. `net_position_mg` assumed signed, negative = owes the clearing account. |
| OTC offer resource | Request body only (§2.6) | Response assumed to mirror the request plus `id`, `offer_code`, `status`, `counterparty`, `expires_at`, `counter_count`. `is_incoming` is a client invention with a fallback. |
| RFQ / RfqQuote resource | Request body only (§2.7) | Same approach. The quote's `reputation` block is assumed to be embedded; if it is not, the comparison screen must fetch `/members/{id}/reputation` per row. |
| Counterparty resource | Endpoints only (§2.11) | `net_balance_rial` and `net_balance_gold_mg` assumed signed, positive = they owe us. |
| Transaction signing (✍️) | Error code `AUTH_TRANSACTION_SIGN_REQUIRED` exists (§1.7) | **No header or field name is specified anywhere.** The client sends `transaction_code` in the request body for ✍️ endpoints. This is a guess and is isolated to `SettlementRepository` and `LotRepository`. |
| `GET /reports/daily-profit` | Endpoint exists (§2.13) | Body `{trade_count, volume_mg, realized_profit_rial, date}`, matching the §2.2 dashboard card and F16. |
| Cold-start user restore | — | There is **no `GET /auth/me`**. On a cold start with a stored token the app restores the ORGANISATION via `GET /organization` and treats the permission list as empty, which fails closed: buttons are hidden rather than shown and then rejected. |
| Receipt upload | `POST /organization/documents` exists (§2.2) | Used with a `purpose: SETTLEMENT_RECEIPT` discriminator; no settlement-scoped upload endpoint is documented. |

---

## Deliberately not implemented

Each has an explicit `TODO(...)` at the point where a developer must pick it up.

- **Push notifications.** No `firebase_messaging`; it needs a Firebase project,
  an APNs key and platform config files that do not exist. `registerDevice` /
  `unregisterDevice` are implemented and unused. Live notification currently
  works only in the foreground, over the WebSocket.
- **Receipt image capture.** No `image_picker`. `SettlementRepository.uploadReceipt`
  is implemented and ready; the screen has no way to obtain a file path.
- **Jalali date picker.** `shamsi_date` ships no widget, so the payment-time
  picker is the Gregorian Material one. The selected value is displayed back in
  Jalali so the user can verify it.
- **RFQ recipient picker.** `GET /members/search` is wired in the repository;
  the UI for choosing specific recipients is not built.
- **Offline cache beyond the balance snapshot.** The architecture doc specifies
  drift/SQLite. What exists is a handful of small JSON blobs in secure storage,
  which is right for the balance and wrong for the ledger.
- **Crash reporting.** `main.dart` has the hooks. Whatever is chosen must scrub
  reports: stack traces and breadcrumbs in this app can carry balances, IBANs
  and settlement amounts.
- **Root/jailbreak detection.** §1.10 suggests warning without blocking. Not
  implemented; it needs another dependency.

---

## Testing

```sh
flutter test                      # everything
flutter test test/models/         # the cross-implementation vectors
flutter test --coverage
./tool/no_double_in_money.sh      # the no-float guard
```

`test/models/calculation_test.dart` is the one that must never be allowed to
fail or to be weakened. It is the only mechanical guarantee that the number the
user sees before tapping "confirm" is the number the server will produce.
