import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/auth/application/auth_controller.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/widgets/error_view.dart';

/// Second factor after a successful password check.
///
/// The challenge token from `POST /auth/login` lives in `AuthState` and is
/// never written to storage: it is short-lived and single-purpose, and putting
/// it on disk would be the one credential in the app that outlives the
/// process for no reason.
class TwoFactorScreen extends ConsumerStatefulWidget {
  const TwoFactorScreen({super.key});

  @override
  ConsumerState<TwoFactorScreen> createState() => _TwoFactorScreenState();
}

class _TwoFactorScreenState extends ConsumerState<TwoFactorScreen> {
  final TextEditingController _code = TextEditingController();

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final normalized = NumericInput.normalizeDigits(_code.text).trim();
    if (normalized.length < 6) {
      return;
    }

    await ref.read(authControllerProvider.notifier).submitSecondFactor(normalized);
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final method = auth.challenge?.preferredMethod ?? 'TOTP';

    return Scaffold(
      appBar: AppBar(
        title: const Text('تأیید دو مرحله‌ای'),
        leading: IconButton(
          icon: const Icon(Icons.arrow_forward),
          onPressed: () =>
              ref.read(authControllerProvider.notifier).cancelSecondFactor(),
          tooltip: 'بازگشت به ورود',
        ),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                method == 'SMS'
                    ? 'کد ارسال‌شده به شماره موبایل خود را وارد کنید.'
                    : 'کد ۶ رقمی برنامه احرازهویت را وارد کنید.',
                style: AppTypography.bodyMuted,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              TextField(
                controller: _code,
                autofocus: true,
                keyboardType: TextInputType.number,
                textAlign: TextAlign.center,
                maxLength: 6,
                style: AppTypography.numericLarge,
                decoration: const InputDecoration(
                  counterText: '',
                  hintText: '------',
                ),
                onChanged: (value) {
                  if (NumericInput.normalizeDigits(value).trim().length == 6) {
                    _submit();
                  }
                },
                onSubmitted: (_) => _submit(),
              ),
              if (auth.failure != null) ...<Widget>[
                const SizedBox(height: 16),
                InlineError(failure: auth.failure!),
              ],
              const SizedBox(height: 24),
              FilledButton(
                onPressed: auth.isBusy ? null : _submit,
                child: auth.isBusy
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('تأیید و ورود'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
