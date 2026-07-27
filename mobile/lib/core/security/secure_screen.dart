import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';

/// Screenshot and screen-recording suppression for financial screens.
///
/// ## Android
///
/// Sets `WindowManager.LayoutParams.FLAG_SECURE`, which blocks screenshots,
/// blocks screen recording, and blanks the window in the recent-apps
/// thumbnail. Implemented over a MethodChannel handled by `MainActivity.kt`
/// (see android/app/src/main/kotlin/.../MainActivity.kt) rather than through
/// the `flutter_windowmanager` package the architecture doc mentions, because
/// that package is unmaintained and this is twenty lines of Kotlin.
///
/// ## iOS
///
/// iOS has no FLAG_SECURE equivalent. Screenshots CANNOT be blocked. What the
/// native side does instead is cover the window with an opaque view on
/// `applicationWillResignActive`, which keeps balances out of the app-switcher
/// snapshot, and reports `isScreenCaptured` so the app can warn during an
/// active screen recording.
///
/// TODO(mobile-platform): the iOS side of the channel is not implemented in
/// this repository. A developer must add the `secureScreen` channel handler to
/// `ios/Runner/AppDelegate.swift`: install a blur/opaque overlay on
/// `applicationWillResignActive` and remove it on `applicationDidBecomeActive`,
/// and forward `UIScreen.main.isCaptured` plus the
/// `UIScreen.capturedDidChangeNotification` observer back to Dart. Until then
/// [SecureScreen] degrades to a no-op on iOS — which is safe, but leaves
/// balances visible in the app switcher.
final class SecureScreenPlatform {
  const SecureScreenPlatform._();

  static const MethodChannel _channel =
      MethodChannel('ir.goldb2b.mobile/secure_screen');

  static int _activeCount = 0;

  /// Reference counted, because a route can be pushed on top of another
  /// secure route and popping the child must not clear the flag for the
  /// parent.
  static Future<void> acquire() async {
    _activeCount += 1;
    if (_activeCount == 1) {
      await _invoke('enable');
    }
  }

  static Future<void> release() async {
    if (_activeCount == 0) {
      return;
    }

    _activeCount -= 1;
    if (_activeCount == 0) {
      await _invoke('disable');
    }
  }

  static Future<void> _invoke(String method) async {
    if (kIsWeb) {
      return;
    }

    try {
      if (Platform.isAndroid || Platform.isIOS) {
        await _channel.invokeMethod<void>(method);
      }
    } on MissingPluginException {
      // The native handler is not installed (iOS today, or a unit-test
      // binding). Failing loudly here would make every financial screen
      // uninstantiable in tests for no security benefit.
    } on PlatformException {
      // Same reasoning: a failure to set the flag must not take the screen
      // down. It is logged by the platform side.
    }
  }
}

/// Wrap any screen that displays balances, prices, order details, settlement
/// amounts, IBANs or lot identifiers.
///
/// ```dart
/// SecureScreen(child: Scaffold(...))
/// ```
class SecureScreen extends StatefulWidget {
  const SecureScreen({required this.child, super.key});

  final Widget child;

  @override
  State<SecureScreen> createState() => _SecureScreenState();
}

class _SecureScreenState extends State<SecureScreen> {
  @override
  void initState() {
    super.initState();
    SecureScreenPlatform.acquire();
  }

  @override
  void dispose() {
    SecureScreenPlatform.release();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
