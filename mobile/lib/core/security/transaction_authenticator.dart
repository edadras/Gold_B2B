import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:local_auth/local_auth.dart';

/// Result of the second factor on a financial action.
enum AuthenticationOutcome {
  /// Biometric or device credential succeeded.
  succeeded,

  /// The user dismissed the prompt.
  cancelled,

  /// No biometric and no device credential is available. The caller must fall
  /// back to a TOTP prompt.
  unavailable,

  /// Hardware present but locked out after repeated failures.
  lockedOut,
}

/// Second factor for high-value operations.
///
/// docs/07-mobile-flutter/01-architecture.md §1.10 defines the sequence:
///
///   1. show an explicit summary of what is about to happen;
///   2. biometric, or
///   3. TOTP as fallback.
///
/// This class owns step 2 only. Step 1 is `ConfirmSheet` and step 3 is the
/// TOTP prompt, because a fallback that is silently skipped when the sensor
/// is missing would turn a two-factor action into a one-tap action on exactly
/// the devices least likely to be the owner's.
///
/// `biometricOnly` is false on purpose: a device PIN or pattern is an
/// acceptable second factor here. The threat being defended against is
/// somebody picking up an unlocked, unattended phone, not a determined
/// attacker with the passcode.
class TransactionAuthenticator {
  TransactionAuthenticator({LocalAuthentication? localAuth})
      : _auth = localAuth ?? LocalAuthentication();

  final LocalAuthentication _auth;

  Future<bool> get isAvailable async {
    try {
      return await _auth.isDeviceSupported() &&
          await _auth.canCheckBiometrics;
    } on Object {
      return false;
    }
  }

  /// Prompt for the second factor.
  ///
  /// [reason] is shown by the OS and should name the operation, not just say
  /// "authenticate" — "تأیید ثبت سفارش فروش ۳۰۰ گرم" tells the user what they
  /// are approving even if the app is not what asked.
  Future<AuthenticationOutcome> authenticate({required String reason}) async {
    try {
      final supported = await _auth.isDeviceSupported();
      if (!supported) {
        return AuthenticationOutcome.unavailable;
      }

      final ok = await _auth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(
          biometricOnly: false,
          stickyAuth: true,
          useErrorDialogs: true,
        ),
      );

      return ok
          ? AuthenticationOutcome.succeeded
          : AuthenticationOutcome.cancelled;
    } on Object catch (error) {
      // local_auth throws a PlatformException with codes such as
      // `NotAvailable`, `NotEnrolled`, `LockedOut`, `PermanentlyLockedOut`.
      // The type is not exported cleanly across platforms, so match on the
      // message rather than importing platform-specific error classes.
      final description = error.toString();

      if (description.contains('LockedOut')) {
        return AuthenticationOutcome.lockedOut;
      }

      return AuthenticationOutcome.unavailable;
    }
  }
}

final transactionAuthenticatorProvider = Provider<TransactionAuthenticator>(
  (ref) => TransactionAuthenticator(),
);
