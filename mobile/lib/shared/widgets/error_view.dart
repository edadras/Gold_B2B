import 'package:flutter/material.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';

/// Full-screen (or full-section) error presentation.
///
/// Because [AppFailure] is sealed, adding a new failure mode forces this
/// switch to be updated — a compile error is a much better reminder than a
/// generic "something went wrong" that ships for two years.
///
/// Three things it always shows when it has them:
///
///   * the SERVER's Persian message, never a client-side paraphrase. The
///     server knows the shortfall, the limit and the deadline;
///   * a retry affordance only when retrying can actually help;
///   * the `request_id`, small and selectable, because it turns a support call
///     from "it did not work" into a log lookup.
class ErrorView extends StatelessWidget {
  const ErrorView({
    required this.failure,
    this.onRetry,
    this.compact = false,
    super.key,
  });

  final AppFailure failure;
  final VoidCallback? onRetry;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final presentation = _presentationFor(failure);

    return Center(
      child: Padding(
        padding: EdgeInsets.all(compact ? 16 : 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(
              presentation.icon,
              size: compact ? 32 : 48,
              color: presentation.color,
            ),
            const SizedBox(height: 12),
            Text(
              presentation.title,
              style: AppTypography.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 6),
            Text(
              failure.userMessage,
              style: AppTypography.bodyMuted,
              textAlign: TextAlign.center,
            ),
            if (failure is ValidationException) ...<Widget>[
              const SizedBox(height: 12),
              _FieldErrors(failure as ValidationException),
            ],
            if (onRetry != null && failure.isRetryable) ...<Widget>[
              const SizedBox(height: 20),
              OutlinedButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: const Text('تلاش مجدد'),
              ),
            ],
            if (failure.requestId != null) ...<Widget>[
              const SizedBox(height: 16),
              SelectableText(
                'کد پیگیری: ${failure.requestId}',
                style: AppTypography.caption,
                textAlign: TextAlign.center,
              ),
            ],
          ],
        ),
      ),
    );
  }

  static _ErrorPresentation _presentationFor(AppFailure failure) =>
      switch (failure) {
        NetworkFailure() => const _ErrorPresentation(
            title: 'اتصال برقرار نیست',
            icon: Icons.cloud_off,
            color: AppColors.negative,
          ),
        TimeoutFailure() => const _ErrorPresentation(
            title: 'پاسخی دریافت نشد',
            icon: Icons.timer_off_outlined,
            color: AppColors.warning,
          ),
        CancelledFailure() => const _ErrorPresentation(
            title: 'لغو شد',
            icon: Icons.close,
            color: AppColors.inkMuted,
          ),
        PinningFailure() => const _ErrorPresentation(
            title: 'اتصال امن برقرار نشد',
            icon: Icons.gpp_bad_outlined,
            color: AppColors.negative,
          ),
        OfflineActionFailure() => const _ErrorPresentation(
            title: 'در حالت آفلاین ممکن نیست',
            icon: Icons.wifi_off,
            color: AppColors.warning,
          ),
        UnexpectedFailure() => const _ErrorPresentation(
            title: 'خطای غیرمنتظره',
            icon: Icons.bug_report_outlined,
            color: AppColors.negative,
          ),
        ApiException() => _forApiException(failure),
      };

  static _ErrorPresentation _forApiException(ApiException failure) {
    if (failure.blocksAccount) {
      return const _ErrorPresentation(
        title: 'حساب شما اجازه این عملیات را ندارد',
        icon: Icons.lock_outline,
        color: AppColors.warning,
      );
    }

    if (failure.isBalanceRelated) {
      return const _ErrorPresentation(
        title: 'موجودی کافی نیست',
        icon: Icons.account_balance_wallet_outlined,
        color: AppColors.warning,
      );
    }

    if (failure.isLimitRelated) {
      return const _ErrorPresentation(
        title: 'سقف مجاز نقض شد',
        icon: Icons.speed_outlined,
        color: AppColors.warning,
      );
    }

    if (failure.statusCode >= 500) {
      return const _ErrorPresentation(
        title: 'خطای سرور',
        icon: Icons.dns_outlined,
        color: AppColors.negative,
      );
    }

    return const _ErrorPresentation(
      title: 'انجام نشد',
      icon: Icons.error_outline,
      color: AppColors.negative,
    );
  }
}

class _ErrorPresentation {
  const _ErrorPresentation({
    required this.title,
    required this.icon,
    required this.color,
  });

  final String title;
  final IconData icon;
  final Color color;
}

class _FieldErrors extends StatelessWidget {
  const _FieldErrors(this.failure);

  final ValidationException failure;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: failure.fieldErrors.entries
            .expand(
              (entry) => entry.value.map(
                (message) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Text(
                    '• $message',
                    style: AppTypography.caption
                        .copyWith(color: AppColors.negative),
                  ),
                ),
              ),
            )
            .toList(growable: false),
      );
}

/// Inline, one-line variant for a card that failed inside an otherwise
/// working screen.
class InlineError extends StatelessWidget {
  const InlineError({required this.failure, this.onRetry, super.key});

  final AppFailure failure;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppColors.negativeSurface,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: <Widget>[
            const Icon(
              Icons.error_outline,
              size: 18,
              color: AppColors.negative,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                failure.userMessage,
                style: AppTypography.caption
                    .copyWith(color: AppColors.negative),
              ),
            ),
            if (onRetry != null && failure.isRetryable)
              TextButton(
                onPressed: onRetry,
                child: const Text('تلاش مجدد'),
              ),
          ],
        ),
      );
}

/// Turn any thrown object into an [AppFailure] for display.
///
/// Riverpod's `AsyncValue.error` carries `Object`, so the presentation layer
/// needs one place that narrows it.
AppFailure asAppFailure(Object error) => error is AppFailure
    ? error
    : UnexpectedFailure(error.toString());
