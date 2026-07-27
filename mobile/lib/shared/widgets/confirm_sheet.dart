import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/security/transaction_authenticator.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';

/// One row of the breakdown shown before the user commits.
final class ConfirmationLine {
  const ConfirmationLine({
    required this.label,
    required this.value,
    this.emphasis = false,
    this.dividerAbove = false,
  });

  final String label;

  /// A `MoneyDisplay`, `WeightDisplay`, `PriceDisplay` or plain `Text` —
  /// never a raw formatted string, so the LTR/tabular rules cannot be skipped
  /// on the single most consequential screen in the app.
  final Widget value;

  final bool emphasis;

  /// Draws a rule above this line — used to separate the bottom line (what the
  /// user actually pays or receives) from its components.
  final bool dividerAbove;
}

/// Outcome of the confirmation flow.
final class ConfirmationResult {
  const ConfirmationResult.rejected()
      : confirmed = false,
        totpCode = null;

  const ConfirmationResult.approvedByBiometrics()
      : confirmed = true,
        totpCode = null;

  const ConfirmationResult.approvedByTotp(String code)
      : confirmed = true,
        totpCode = code;

  final bool confirmed;

  /// Present only when the second factor was a TOTP code, in which case the
  /// caller must forward it to the endpoint.
  final String? totpCode;
}

/// The mandatory two-step confirmation for a financial action.
///
/// docs/07-mobile-flutter/01-architecture.md §1.10:
///
///   1. show an explicit summary — the full computed breakdown, not a
///      one-line "are you sure?";
///   2. biometric;
///   3. TOTP as fallback.
///
/// Step 1 is not skippable and the sheet is not dismissible by tapping
/// outside, because "I did not mean to place that" is the most expensive
/// sentence in this product. Step 2 or 3 always runs — there is no
/// "remember me for 5 minutes".
Future<ConfirmationResult> confirmFinancialAction({
  required BuildContext context,
  required WidgetRef ref,
  required String title,
  required List<ConfirmationLine> lines,
  required Rial amount,
  String confirmLabel = 'تأیید',
  String? warning,
  String? authenticationReason,
}) async {
  await HapticFeedback.mediumImpact();

  final config = ref.read(appConfigProvider);

  final agreed = await showModalBottomSheet<bool>(
    context: context,
    isDismissible: false,
    enableDrag: false,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (sheetContext) => ConfirmSheet(
      title: title,
      lines: lines,
      confirmLabel: confirmLabel,
      warning: warning,
      isLargeAmount: amount.absolute.amount > config.highValueThresholdRial,
    ),
  );

  if (agreed != true) {
    return const ConfirmationResult.rejected();
  }

  if (!context.mounted) {
    return const ConfirmationResult.rejected();
  }

  final authenticator = ref.read(transactionAuthenticatorProvider);
  final outcome = await authenticator.authenticate(
    reason: authenticationReason ?? 'تأیید $title',
  );

  switch (outcome) {
    case AuthenticationOutcome.succeeded:
      return const ConfirmationResult.approvedByBiometrics();

    case AuthenticationOutcome.cancelled:
      return const ConfirmationResult.rejected();

    case AuthenticationOutcome.unavailable:
    case AuthenticationOutcome.lockedOut:
      {
        if (!context.mounted) {
          return const ConfirmationResult.rejected();
        }

        final code = await promptForTotp(
          context,
          lockedOut: outcome == AuthenticationOutcome.lockedOut,
        );

        return code == null
            ? const ConfirmationResult.rejected()
            : ConfirmationResult.approvedByTotp(code);
      }
  }
}

/// The summary sheet itself. Exposed separately so it can be widget-tested
/// without a biometric sensor.
class ConfirmSheet extends StatelessWidget {
  const ConfirmSheet({
    required this.title,
    required this.lines,
    this.confirmLabel = 'تأیید',
    this.warning,
    this.isLargeAmount = false,
    super.key,
  });

  final String title;
  final List<ConfirmationLine> lines;
  final String confirmLabel;
  final String? warning;
  final bool isLargeAmount;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.divider,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Text(
                title,
                style: AppTypography.titleLarge,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 16),
              for (final line in lines) ...<Widget>[
                if (line.dividerAbove) const ThinDivider(vertical: 6),
                KeyValueRow(
                  label: line.label,
                  value: line.value,
                  emphasis: line.emphasis,
                ),
              ],
              if (warning != null) ...<Widget>[
                const SizedBox(height: 12),
                _Notice(
                  message: warning!,
                  tone: AppColors.warning,
                  surface: AppColors.warningSurface,
                  icon: Icons.warning_amber_rounded,
                ),
              ],
              if (isLargeAmount) ...<Widget>[
                const SizedBox(height: 8),
                const _Notice(
                  message: 'این یک عملیات با مبلغ بالا است. '
                      'اعداد را یک بار دیگر بررسی کنید.',
                  tone: AppColors.negative,
                  surface: AppColors.negativeSurface,
                  icon: Icons.priority_high_rounded,
                ),
              ],
              const SizedBox(height: 20),
              Row(
                children: <Widget>[
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.of(context).pop(false),
                      child: const Text('انصراف'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () => Navigator.of(context).pop(true),
                      icon: const Icon(Icons.lock_outline, size: 18),
                      label: Text(confirmLabel),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      );
}

class _Notice extends StatelessWidget {
  const _Notice({
    required this.message,
    required this.tone,
    required this.surface,
    required this.icon,
  });

  final String message;
  final Color tone;
  final Color surface;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: surface,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Icon(icon, size: 18, color: tone),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                message,
                style: AppTypography.caption.copyWith(color: tone),
              ),
            ),
          ],
        ),
      );
}

/// TOTP fallback prompt. Returns the six-digit code, or null if cancelled.
///
/// Persian and Arabic digits are normalised on the way out, because a user
/// with a Persian keyboard will type ۱۲۳۴۵۶ and the server expects 123456.
Future<String?> promptForTotp(
  BuildContext context, {
  bool lockedOut = false,
}) =>
    showDialog<String>(
      context: context,
      barrierDismissible: false,
      builder: (dialogContext) => _TotpDialog(lockedOut: lockedOut),
    );

class _TotpDialog extends StatefulWidget {
  const _TotpDialog({required this.lockedOut});

  final bool lockedOut;

  @override
  State<_TotpDialog> createState() => _TotpDialogState();
}

class _TotpDialogState extends State<_TotpDialog> {
  final TextEditingController _controller = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    final normalized =
        NumericInput.normalizeDigits(_controller.text).replaceAll(' ', '');

    if (normalized.length != 6 || int.tryParse(normalized) == null) {
      setState(() => _error = 'کد باید ۶ رقم باشد.');

      return;
    }

    Navigator.of(context).pop(normalized);
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('تأیید عملیات'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(
              widget.lockedOut
                  ? 'تشخیص بیومتریک موقتاً غیرفعال است. '
                      'کد ۶ رقمی برنامه احرازهویت را وارد کنید.'
                  : 'کد ۶ رقمی برنامه احرازهویت را وارد کنید.',
              style: AppTypography.bodyMuted,
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _controller,
              autofocus: true,
              keyboardType: TextInputType.number,
              textAlign: TextAlign.center,
              maxLength: 6,
              style: AppTypography.numericLarge,
              decoration: InputDecoration(
                counterText: '',
                errorText: _error,
                hintText: '------',
              ),
              onSubmitted: (_) => _submit(),
            ),
          ],
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('انصراف'),
          ),
          FilledButton(onPressed: _submit, child: const Text('تأیید')),
        ],
      );
}

/// Convenience for the common shape: a money line.
ConfirmationLine moneyLine(
  String label,
  Rial amount, {
  bool emphasis = false,
  bool dividerAbove = false,
}) =>
    ConfirmationLine(
      label: label,
      // Never compact on a confirmation surface — the user is agreeing to an
      // exact number and must see every digit of it.
      value: MoneyDisplay(
        amount,
        emphasis: emphasis ? MoneyEmphasis.normal : MoneyEmphasis.small,
      ),
      emphasis: emphasis,
      dividerAbove: dividerAbove,
    );
