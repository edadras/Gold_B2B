/// Build flavour.
enum AppFlavor { dev, staging, prod }

/// Everything that differs between environments, resolved once at startup and
/// injected through `appConfigProvider`. Nothing in the app reads an
/// environment variable directly.
final class AppConfig {
  const AppConfig({
    required this.flavor,
    required this.apiBaseUrl,
    required this.webSocketUrl,
    required this.reverbAppKey,
    required this.clientVersion,
    this.certificateFingerprints = const <String>[],
    this.connectTimeout = const Duration(seconds: 10),
    this.receiveTimeout = const Duration(seconds: 30),
    this.idleLockTimeout = const Duration(minutes: 5),
    this.highValueThresholdRial = 1000000000,
    this.stalenessWarningAfter = const Duration(minutes: 2),
    this.maxRetryAttempts = 3,
  });

  final AppFlavor flavor;

  /// Includes the version segment, e.g. `https://api.goldb2b.ir/api/v1`.
  final String apiBaseUrl;

  /// Reverb endpoint WITHOUT the `/app/{key}` suffix, e.g.
  /// `wss://ws.goldb2b.ir`. `WebSocketService` appends the key and the Pusher
  /// protocol query string.
  final String webSocketUrl;

  /// The Reverb (Pusher-protocol) application key. Public by design — it is
  /// not a secret; private channels are authorised server-side via
  /// `POST /broadcasting/auth`.
  final String reverbAppKey;

  /// Sent as `X-Client-Version`, e.g. `flutter/1.2.3`.
  final String clientVersion;

  /// Lower-case hex SHA-256 fingerprints of the DER-encoded server
  /// certificates that are acceptable in this environment.
  ///
  /// EMPTY MEANS PINNING IS DISABLED. That is correct for dev and staging,
  /// where certificates are issued by a local CA and rotate constantly, and it
  /// is a release blocker for prod. See `CertificatePinning` for how to
  /// obtain a fingerprint, and README.md "Certificate pinning".
  final List<String> certificateFingerprints;

  final Duration connectTimeout;
  final Duration receiveTimeout;

  /// docs/07-mobile-flutter/01-architecture.md §1.10 — auto-lock after five
  /// minutes with no user interaction.
  final Duration idleLockTimeout;

  /// Above this amount, a confirmation sheet additionally shows the
  /// "large amount" warning banner. Biometric/TOTP re-authentication is
  /// required for every financial action regardless of size; this threshold
  /// only controls the extra visual emphasis.
  final int highValueThresholdRial;

  /// How old a cached balance may get before its staleness label switches
  /// from informational grey to warning amber.
  final Duration stalenessWarningAfter;

  /// Maximum automatic retries for a request that is safe to retry. See
  /// `RetryInterceptor` for what "safe" means — in short: GET, or a request
  /// that already carries an Idempotency-Key.
  final int maxRetryAttempts;

  bool get isProduction => flavor == AppFlavor.prod;

  bool get isDebug => flavor == AppFlavor.dev;

  bool get pinningEnabled => certificateFingerprints.isNotEmpty;

  /// `wss://ws.goldb2b.ir/app/{key}?protocol=7&client=flutter&version=1.0`
  Uri get webSocketUri => Uri.parse(
        '$webSocketUrl/app/$reverbAppKey'
        '?protocol=7&client=flutter&version=1.0',
      );

  // -------------------------------------------------------------------------
  // Flavours
  //
  // Values come from --dart-define so that a release build cannot accidentally
  // ship a staging host. See README.md "Running" for the exact command lines.
  // -------------------------------------------------------------------------

  static const String _versionName =
      String.fromEnvironment('APP_VERSION', defaultValue: '1.0.0');

  static AppConfig dev() => const AppConfig(
        flavor: AppFlavor.dev,
        apiBaseUrl: String.fromEnvironment(
          'API_BASE_URL',
          defaultValue: 'http://10.0.2.2:8000/api/v1',
        ),
        webSocketUrl: String.fromEnvironment(
          'WS_URL',
          defaultValue: 'ws://10.0.2.2:8080',
        ),
        reverbAppKey: String.fromEnvironment(
          'REVERB_APP_KEY',
          defaultValue: 'goldb2b-local',
        ),
        clientVersion: 'flutter/$_versionName+dev',
        // Intentionally empty: the dev API is plain HTTP on the emulator's
        // host loopback and there is nothing to pin.
      );

  static AppConfig staging() => const AppConfig(
        flavor: AppFlavor.staging,
        apiBaseUrl: String.fromEnvironment(
          'API_BASE_URL',
          defaultValue: 'https://sandbox-api.goldb2b.ir/api/v1',
        ),
        webSocketUrl: String.fromEnvironment(
          'WS_URL',
          defaultValue: 'wss://sandbox-ws.goldb2b.ir',
        ),
        reverbAppKey: String.fromEnvironment(
          'REVERB_APP_KEY',
          defaultValue: '',
        ),
        clientVersion: 'flutter/$_versionName+staging',
      );

  static AppConfig prod() => const AppConfig(
        flavor: AppFlavor.prod,
        apiBaseUrl: 'https://api.goldb2b.ir/api/v1',
        webSocketUrl: 'wss://ws.goldb2b.ir',
        reverbAppKey: String.fromEnvironment(
          'REVERB_APP_KEY',
          defaultValue: '',
        ),
        clientVersion: 'flutter/$_versionName',
        // TODO(release-engineering): populate before the first production
        // build. Run `tool/fingerprint.sh api.goldb2b.ir` (documented in
        // README.md) against BOTH the current leaf certificate and the
        // already-issued backup certificate, and put both here — pinning a
        // single certificate means an emergency rotation bricks every
        // installed app. An empty list ships an unpinned production binary.
        certificateFingerprints: <String>[],
      );

  static AppConfig forFlavor(AppFlavor flavor) => switch (flavor) {
        AppFlavor.dev => dev(),
        AppFlavor.staging => staging(),
        AppFlavor.prod => prod(),
      };
}
