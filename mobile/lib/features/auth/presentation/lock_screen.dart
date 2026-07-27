import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/security/app_lock.dart';
import 'package:gold_b2b/core/security/transaction_authenticator.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/auth/application/auth_controller.dart';

/// Shown after the five-minute idle timeout, or after a long background.
///
/// The session is still valid — this is a device-presence check, not a
/// re-login — so the only way out is biometric/device credential, or signing
/// out entirely. Nothing behind the lock is rendered while it is up.
class LockScreen extends ConsumerStatefulWidget {
  const LockScreen({super.key});

  @override
  ConsumerState<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends ConsumerState<LockScreen> {
  bool _busy = false;
  String? _message;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _attempt());
  }

  Future<void> _attempt() async {
    if (_busy) {
      return;
    }

    setState(() {
      _busy = true;
      _message = null;
    });

    final outcome = await ref
        .read(transactionAuthenticatorProvider)
        .authenticate(reason: 'باز کردن قفل برنامه');

    if (!mounted) {
      return;
    }

    switch (outcome) {
      case AuthenticationOutcome.succeeded:
        ref.read(appLockProvider.notifier).unlock();

      case AuthenticationOutcome.cancelled:
        setState(() {
          _busy = false;
          _message = 'برای ادامه، هویت خود را تأیید کنید.';
        });

      case AuthenticationOutcome.lockedOut:
        setState(() {
          _busy = false;
          _message = 'تشخیص بیومتریک قفل شده است. '
              'با رمز دستگاه تلاش کنید یا از حساب خارج شوید.';
        });

      case AuthenticationOutcome.unavailable:
        // No biometric and no device credential configured. Requiring one
        // would strand the user, so the lock degrades to a manual tap — the
        // idle timeout still hid the balances, which is most of the value.
        ref.read(appLockProvider.notifier).unlock();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  const Icon(
                    Icons.lock_outline,
                    size: 56,
                    color: AppColors.gold,
                  ),
                  const SizedBox(height: 16),
                  Text('برنامه قفل شده است', style: AppTypography.titleLarge),
                  const SizedBox(height: 8),
                  Text(
                    'به دلیل عدم فعالیت، برای مشاهده اطلاعات مالی '
                    'باید هویت خود را تأیید کنید.',
                    style: AppTypography.bodyMuted,
                    textAlign: TextAlign.center,
                  ),
                  if (_message != null) ...<Widget>[
                    const SizedBox(height: 16),
                    Text(
                      _message!,
                      style: AppTypography.caption
                          .copyWith(color: AppColors.warning),
                      textAlign: TextAlign.center,
                    ),
                  ],
                  const SizedBox(height: 28),
                  FilledButton.icon(
                    onPressed: _busy ? null : _attempt,
                    icon: const Icon(Icons.fingerprint),
                    label: const Text('باز کردن قفل'),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () =>
                            ref.read(authControllerProvider.notifier).logout(),
                    child: const Text('خروج از حساب'),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
}
