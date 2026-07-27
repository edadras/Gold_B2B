import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/app.dart';
import 'package:gold_b2b/core/config/app_config.dart';
import 'package:gold_b2b/core/providers.dart';

/// Entry point.
///
/// The flavour is chosen HERE, once, and injected through a ProviderScope
/// override. `appConfigProvider` throws if it is not overridden, so there is
/// no way to accidentally ship a build that silently falls back to a default
/// host.
///
/// Run with:
///
/// ```sh
/// flutter run --dart-define=FLAVOR=dev
/// flutter build apk --release --dart-define=FLAVOR=prod \
///   --dart-define=REVERB_APP_KEY=... --dart-define=APP_VERSION=1.2.3
/// ```
void main() {
  runZonedGuarded<void>(
    () {
      WidgetsFlutterBinding.ensureInitialized();

      SystemChrome.setPreferredOrientations(<DeviceOrientation>[
        DeviceOrientation.portraitUp,
      ]);

      FlutterError.onError = (details) {
        FlutterError.presentError(details);

        // TODO(mobile-platform): wire a crash reporter here (Sentry, Crashlytics
        // or the platform's own). Whatever is chosen MUST scrub the report:
        // this app's stack traces and breadcrumbs can carry balances, IBANs
        // and settlement amounts, none of which may leave the device.
        debugPrint('Unhandled Flutter error: ${details.exception}');
      };

      runApp(
        ProviderScope(
          overrides: <Override>[
            appConfigProvider.overrideWithValue(_resolveConfig()),
          ],
          child: const GoldB2BApp(),
        ),
      );
    },
    (error, stack) {
      // Same caveat as above about scrubbing before reporting.
      debugPrint('Unhandled zone error: $error');
    },
  );
}

AppConfig _resolveConfig() {
  const flavor = String.fromEnvironment('FLAVOR', defaultValue: 'dev');

  return switch (flavor) {
    'prod' => AppConfig.prod(),
    'staging' => AppConfig.staging(),
    _ => AppConfig.dev(),
  };
}
