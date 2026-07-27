import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/providers.dart';

/// Whether the app is showing content or is locked behind re-authentication.
enum LockState {
  /// Content is visible.
  unlocked,

  /// The idle timer fired, or the app was backgrounded. The router forces
  /// `/lock` and nothing behind it is rendered.
  locked,
}

/// Idle auto-lock.
///
/// docs/07-mobile-flutter/01-architecture.md §1.10 — lock after five minutes
/// of inactivity. This implementation additionally locks on backgrounding,
/// because a phone handed to somebody else is at least as likely a threat as
/// one left on a desk.
///
/// The timer is deliberately NOT reset by network activity or by realtime
/// events. Only genuine user interaction counts; otherwise a dashboard
/// receiving a price tick every two seconds would never lock.
class AppLockController extends StateNotifier<LockState> with WidgetsBindingObserver {
  AppLockController({required this.timeout}) : super(LockState.unlocked) {
    WidgetsBinding.instance.addObserver(this);
    _restart();
  }

  final Duration timeout;

  Timer? _timer;
  DateTime? _backgroundedAt;

  /// Call on any real user interaction. Wired once, at the top of the widget
  /// tree, via `AppLockScope`.
  void recordActivity() {
    if (state == LockState.locked) {
      return;
    }

    _restart();
  }

  /// Called by the lock screen once biometric or password re-authentication
  /// has succeeded.
  void unlock() {
    state = LockState.unlocked;
    _restart();
  }

  void lockNow() {
    _timer?.cancel();
    _timer = null;
    state = LockState.locked;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState lifecycle) {
    switch (lifecycle) {
      case AppLifecycleState.paused:
      case AppLifecycleState.hidden:
        {
          _backgroundedAt = DateTime.now();
          _timer?.cancel();
          _timer = null;
          break;
        }

      case AppLifecycleState.resumed:
        {
          final since = _backgroundedAt;
          _backgroundedAt = null;

          // A quick switch to the banking app to copy an IBAN and straight
          // back must not force a re-auth; a phone left in a pocket must.
          if (since != null && DateTime.now().difference(since) >= timeout) {
            lockNow();
          } else if (state == LockState.unlocked) {
            _restart();
          }
          break;
        }

      case AppLifecycleState.inactive:
      case AppLifecycleState.detached:
        break;
    }
  }

  void _restart() {
    _timer?.cancel();
    _timer = Timer(timeout, lockNow);
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }
}

final appLockProvider =
    StateNotifierProvider<AppLockController, LockState>((ref) {
  final config = ref.watch(appConfigProvider);

  return AppLockController(timeout: config.idleLockTimeout);
});

/// Feeds user interaction into [AppLockController].
///
/// Wraps the whole app rather than individual screens: a `Listener` at the
/// root sees every pointer-down regardless of which widget ultimately handles
/// it, and `behavior: HitTestBehavior.translucent` keeps it out of the way.
class AppLockScope extends ConsumerWidget {
  const AppLockScope({required this.child, super.key});

  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) => Listener(
        behavior: HitTestBehavior.translucent,
        onPointerDown: (_) => ref.read(appLockProvider.notifier).recordActivity(),
        child: child,
      );
}
