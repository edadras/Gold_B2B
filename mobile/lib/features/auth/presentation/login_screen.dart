import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/auth/application/auth_controller.dart';
import 'package:gold_b2b/features/auth/data/auth_repository.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/widgets/error_view.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final TextEditingController _mobile = TextEditingController();
  final TextEditingController _password = TextEditingController();
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  bool _obscure = true;

  @override
  void initState() {
    super.initState();
    _prefillMobile();
  }

  Future<void> _prefillMobile() async {
    final last = await ref.read(authRepositoryProvider).readLastUserMobile();
    if (last != null && mounted && _mobile.text.isEmpty) {
      _mobile.text = last;
    }
  }

  @override
  void dispose() {
    _mobile.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) {
      return;
    }

    FocusScope.of(context).unfocus();

    await ref.read(authControllerProvider.notifier).login(
          mobile: _mobile.text,
          password: _password.text,
        );
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  const SizedBox(height: 24),
                  const Icon(
                    Icons.workspace_premium_outlined,
                    size: 56,
                    color: AppColors.gold,
                  ),
                  const SizedBox(height: 12),
                  Text(
                    'سامانه معاملات طلا',
                    style: AppTypography.titleLarge,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'ورود اعضای صنفی',
                    style: AppTypography.bodyMuted,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 32),
                  TextFormField(
                    controller: _mobile,
                    keyboardType: TextInputType.phone,
                    textInputAction: TextInputAction.next,
                    autofillHints: const <String>[AutofillHints.telephoneNumber],
                    decoration: const InputDecoration(
                      labelText: 'شماره موبایل',
                      hintText: '09xxxxxxxxx',
                      prefixIcon: Icon(Icons.phone_iphone),
                    ),
                    validator: _validateMobile,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _password,
                    obscureText: _obscure,
                    textInputAction: TextInputAction.done,
                    autofillHints: const <String>[AutofillHints.password],
                    decoration: InputDecoration(
                      labelText: 'رمز عبور',
                      prefixIcon: const Icon(Icons.lock_outline),
                      suffixIcon: IconButton(
                        onPressed: () => setState(() => _obscure = !_obscure),
                        icon: Icon(
                          _obscure ? Icons.visibility_off : Icons.visibility,
                        ),
                        tooltip: _obscure ? 'نمایش رمز' : 'پنهان کردن رمز',
                      ),
                    ),
                    validator: (value) =>
                        (value == null || value.isEmpty) ? 'رمز عبور را وارد کنید.' : null,
                    onFieldSubmitted: (_) => _submit(),
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
                        : const Text('ورود'),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: auth.isBusy ? null : _forgotPassword,
                    child: const Text('رمز عبور را فراموش کرده‌ام'),
                  ),
                  const SizedBox(height: 24),
                  Text(
                    'ثبت‌نام و احراز هویت (KYC) در پنل وب انجام می‌شود.',
                    style: AppTypography.caption,
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _forgotPassword() async {
    final mobile = NumericInput.normalizeDigits(_mobile.text).trim();
    if (_validateMobile(mobile) != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('ابتدا شماره موبایل را وارد کنید.')),
      );

      return;
    }

    await ref.read(authRepositoryProvider).requestPasswordReset(mobile);
    await HapticFeedback.lightImpact();

    if (!mounted) {
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('در صورت وجود حساب، پیامک بازنشانی ارسال شد.'),
      ),
    );
  }

  /// Iranian mobile numbers are 11 digits starting `09`. Persian and Arabic
  /// digits are normalised first, because that is what the keyboard produces.
  static String? _validateMobile(String? value) {
    final normalized = NumericInput.normalizeDigits(value ?? '').trim();

    if (normalized.isEmpty) {
      return 'شماره موبایل را وارد کنید.';
    }

    if (!RegExp(r'^09\d{9}$').hasMatch(normalized)) {
      return 'شماره موبایل معتبر نیست.';
    }

    return null;
  }
}
