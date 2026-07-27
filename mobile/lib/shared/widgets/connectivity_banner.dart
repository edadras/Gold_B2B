import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/connectivity/connectivity_monitor.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';

/// Persistent connection banner.
///
/// docs/07-mobile-flutter/01-architecture.md §1.9 gives the three states and
/// the exact wording. Offline is red and explicit that trading is impossible,
/// because the alternative — buttons that simply do nothing — makes users
/// tap harder.
class ConnectivityBanner extends ConsumerWidget {
  const ConnectivityBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = ref.watch(connectivityProvider);
    final message = status.bannerMessage;

    if (message == null) {
      return const SizedBox.shrink();
    }

    final isOffline = status == ConnectivityStatus.offline;

    return Material(
      color: isOffline ? AppColors.negative : AppColors.warning,
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: <Widget>[
              Icon(
                isOffline ? Icons.cloud_off : Icons.sync_problem,
                size: 16,
                color: Colors.white,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  message,
                  style: AppTypography.caption.copyWith(color: Colors.white),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// "آخرین به‌روزرسانی: ۲ دقیقه پیش".
///
/// Mandatory next to any figure served from cache. §1.9 lists the cached
/// balance as available offline "with an indication of how old it is" — the
/// indication is not optional, because a balance with no timestamp is
/// indistinguishable from a live one and that is exactly the mistake that
/// causes an order the user cannot cover.
class StalenessLabel extends ConsumerWidget {
  const StalenessLabel({
    required this.asOf,
    this.prefix,
    this.warnAfter = const Duration(minutes: 2),
    super.key,
  });

  /// When the displayed data was produced (server `as_of`, or the cache write
  /// time when offline).
  final DateTime asOf;

  final String? prefix;

  /// Past this age the label turns amber.
  final Duration warnAfter;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Rebuilds once a second so "۱ دقیقه پیش" becomes "۲ دقیقه پیش" without
    // the user pulling to refresh.
    final now = ref.watch(clockProvider).valueOrNull ?? DateTime.now();
    final age = now.toUtc().difference(asOf.toUtc());
    final isStale = age >= warnAfter;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(
          isStale ? Icons.history_toggle_off : Icons.schedule,
          size: 12,
          color: isStale ? AppColors.warning : AppColors.inkDisabled,
        ),
        const SizedBox(width: 4),
        Text(
          prefix == null
              ? JalaliFormatter.lastUpdated(asOf, now: now)
              : '$prefix ${JalaliFormatter.lastUpdated(asOf, now: now)}',
          style: AppTypography.caption.copyWith(
            color: isStale ? AppColors.warning : AppColors.inkDisabled,
          ),
        ),
      ],
    );
  }
}

/// Explains why an action button is disabled.
///
/// Wrap any trading or settlement control. When offline it replaces the
/// control with a disabled version plus a reason; when online it renders the
/// control untouched.
///
/// There is deliberately NO queue-for-later option (§1.9): prices move, and
/// executing a ten-minute-old intent is dangerous.
class OfflineActionGuard extends ConsumerWidget {
  const OfflineActionGuard({
    required this.builder,
    this.reason,
    super.key,
  });

  /// Receives whether financial actions are currently permitted.
  final Widget Function(BuildContext context, bool enabled) builder;

  /// Overrides the default explanation.
  final String? reason;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = ref.watch(connectivityProvider);
    final enabled = status.allowsFinancialActions;

    if (enabled) {
      return builder(context, true);
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        builder(context, false),
        const SizedBox(height: 8),
        Row(
          children: <Widget>[
            const Icon(Icons.wifi_off, size: 14, color: AppColors.warning),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                reason ??
                    'بدون اتصال اینترنت، عملیات مالی انجام نمی‌شود. '
                        'سفارش‌ها برای ارسال بعدی ذخیره نمی‌شوند.',
                style:
                    AppTypography.caption.copyWith(color: AppColors.warning),
              ),
            ),
          ],
        ),
      ],
    );
  }
}
