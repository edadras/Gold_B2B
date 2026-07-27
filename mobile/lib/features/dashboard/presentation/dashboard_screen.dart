import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/weight_formatter.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/auth/application/auth_controller.dart';
import 'package:gold_b2b/features/dashboard/application/balance_controller.dart';
import 'package:gold_b2b/features/dashboard/data/models/balances.dart';
import 'package:gold_b2b/features/dashboard/data/models/daily_summary.dart';
import 'package:gold_b2b/features/market/application/market_providers.dart';
import 'package:gold_b2b/features/notifications/application/notifications_controller.dart';
import 'package:gold_b2b/features/settlements/application/settlements_controller.dart';
import 'package:gold_b2b/features/settlements/data/models/settlement.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// docs/07-mobile-flutter/02-screens.md §2.2.
///
/// Layout rules from that section, in the order they are applied:
///
///   * "action required" always ABOVE informational content;
///   * near deadlines in a warning colour;
///   * the balance always carries its update time;
///   * buy/sell reachable in one tap.
///
/// Note the deliberate ordering below: the balance card comes first because
/// it is what the user opened the app for, but the action-required block is
/// placed immediately after and is visually louder. Putting it at the very
/// top was considered and rejected — an empty action list would then leave
/// the screen looking broken.
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final unread = ref.watch(unreadNotificationCountProvider).valueOrNull ?? 0;

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(
          centerTitle: false,
          title: Row(
            children: <Widget>[
              Flexible(
                child: Text(
                  auth.organization?.displayName ?? 'داشبورد',
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: 6),
              TierBadge(auth.organization?.verificationTier),
            ],
          ),
          actions: <Widget>[
            IconButton(
              tooltip: 'اعلان‌ها',
              onPressed: () => context.push('/notifications'),
              icon: Badge(
                isLabelVisible: unread > 0,
                label: Text(MoneyFormatter.integer(unread)),
                child: const Icon(Icons.notifications_none),
              ),
            ),
            IconButton(
              tooltip: 'تنظیمات',
              onPressed: () => context.push('/settings'),
              icon: const Icon(Icons.settings_outlined),
            ),
          ],
        ),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            if (auth.organization != null && !auth.organization!.canTrade)
              _AccountStatusNotice(status: auth.organization!.statusLabel),
            Expanded(
              child: RefreshIndicator(
                onRefresh: () async {
                  await ref.read(balanceProvider.notifier).refreshQuietly();
                  ref.invalidate(dailySummaryProvider);
                  await ref
                      .read(pendingSettlementsProvider.notifier)
                      .refresh();
                },
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: const <Widget>[
                    GoldBalanceCard(),
                    SizedBox(height: 12),
                    RialBalanceCard(),
                    SizedBox(height: 12),
                    MarketCard(),
                    SizedBox(height: 20),
                    ActionRequiredSection(),
                    SizedBox(height: 20),
                    TodaySection(),
                    SizedBox(height: 24),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class GoldBalanceCard extends ConsumerWidget {
  const GoldBalanceCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final balance = ref.watch(balanceProvider);

    return AsyncValueView<BalanceSnapshot>(
      value: balance,
      loading: const BalanceCardSkeleton(),
      onRetry: () => ref.read(balanceProvider.notifier).refresh(),
      data: (snapshot) {
        final gold = snapshot.gold;

        return SectionCard(
          title: 'موجودی طلا',
          trailing: StalenessLabel(asOf: snapshot.asOf),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              WeightDisplay(
                gold.total,
                emphasis: WeightEmphasis.large,
                isStale: snapshot.isFromCache,
              ),
              const SizedBox(height: 4),
              Row(
                children: <Widget>[
                  Text('≈ ', style: AppTypography.caption),
                  MoneyDisplay(
                    gold.marketValue,
                    compact: true,
                    emphasis: MoneyEmphasis.small,
                    isStale: snapshot.isFromCache,
                  ),
                ],
              ),
              if (gold.hasCommitments) ...<Widget>[
                const ThinDivider(),
                _BucketRow(label: 'در دسترس', weight: gold.available),
                _BucketRow(label: 'رزرو', weight: gold.reserved),
                _BucketRow(label: 'در تسویه', weight: gold.inSettlement),
                if (!gold.inDispute.isZero)
                  _BucketRow(
                    label: 'در اختلاف',
                    weight: gold.inDispute,
                    color: AppColors.negative,
                  ),
              ] else ...<Widget>[
                const SizedBox(height: 8),
                Row(
                  children: <Widget>[
                    Text('در دسترس: ', style: AppTypography.caption),
                    LtrNumber(
                      WeightFormatter.fine(gold.available),
                      style: AppTypography.numericSmall,
                    ),
                  ],
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}

class _BucketRow extends StatelessWidget {
  const _BucketRow({required this.label, required this.weight, this.color});

  final String label;
  final FineWeight weight;
  final Color? color;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          children: <Widget>[
            Expanded(child: Text(label, style: AppTypography.bodyMuted)),
            LtrNumber(
              WeightFormatter.fine(weight),
              style: AppTypography.numericSmall.copyWith(color: color),
            ),
          ],
        ),
      );
}

class RialBalanceCard extends ConsumerWidget {
  const RialBalanceCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final balance = ref.watch(balanceProvider).valueOrNull;

    if (balance == null) {
      return const BalanceCardSkeleton();
    }

    return SectionCard(
      title: 'موجودی ریالی',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          MoneyDisplay(
            balance.rial.available,
            emphasis: MoneyEmphasis.large,
            compact: true,
            isStale: balance.isFromCache,
          ),
          if (balance.rial.payable.isNegative) ...<Widget>[
            const SizedBox(height: 6),
            Row(
              children: <Widget>[
                Text('بدهی: ', style: AppTypography.caption),
                MoneyDisplay(
                  balance.rial.payable.absolute,
                  emphasis: MoneyEmphasis.small,
                  color: AppColors.negative,
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The one-tap buy/sell card.
class MarketCard extends ConsumerWidget {
  const MarketCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final code = ref.watch(selectedInstrumentProvider);
    final quote = ref.watch(quoteProvider(code)).valueOrNull;
    final session = ref.watch(marketSessionProvider(code)).valueOrNull;

    return SectionCard(
      onTap: () => context.push('/market/$code'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Text(code, style: AppTypography.titleMedium),
              const Spacer(),
              if (session != null)
                StatusBadge(
                  label: session.label,
                  tone: session.isOpen
                      ? StatusTone.positive
                      : StatusTone.neutral,
                  dense: true,
                ),
            ],
          ),
          const ThinDivider(vertical: 10),
          if (quote == null)
            const SkeletonBox(width: 220, height: 24)
          else
            Row(
              children: <Widget>[
                Expanded(
                  child: _QuoteCell(
                    label: 'خرید',
                    color: AppColors.buy,
                    child: quote.bestBid == null
                        ? const Text('—')
                        : PriceDisplay(quote.bestBid!, color: AppColors.buy),
                  ),
                ),
                Expanded(
                  child: _QuoteCell(
                    label: 'فروش',
                    color: AppColors.sell,
                    child: quote.bestAsk == null
                        ? const Text('—')
                        : PriceDisplay(quote.bestAsk!, color: AppColors.sell),
                  ),
                ),
                Expanded(
                  child: _QuoteCell(
                    label: 'آخرین',
                    color: AppColors.inkMuted,
                    child: ChangeDisplay(quote.dayChangeBps),
                  ),
                ),
              ],
            ),
          const SizedBox(height: 12),
          OfflineActionGuard(
            builder: (context, online) => Row(
              children: <Widget>[
                Expanded(
                  child: FilledButton(
                    style:
                        FilledButton.styleFrom(backgroundColor: AppColors.buy),
                    onPressed: online && (session?.isOpen ?? false)
                        ? () => context.push('/market/$code/order/BUY')
                        : null,
                    child: const Text('خرید'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: AppColors.sell,
                    ),
                    onPressed: online && (session?.isOpen ?? false)
                        ? () => context.push('/market/$code/order/SELL')
                        : null,
                    child: const Text('فروش'),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _QuoteCell extends StatelessWidget {
  const _QuoteCell({
    required this.label,
    required this.color,
    required this.child,
  });

  final String label;
  final Color color;
  final Widget child;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(label, style: AppTypography.caption.copyWith(color: color)),
          const SizedBox(height: 2),
          child,
        ],
      );
}

/// "⚠️ نیازمند اقدام شما" — the highest-priority block on the screen.
class ActionRequiredSection extends ConsumerWidget {
  const ActionRequiredSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending = ref.watch(pendingSettlementsProvider);
    final items = pending.valueOrNull ?? const <Settlement>[];

    if (items.isEmpty) {
      return const SizedBox.shrink();
    }

    final now = ref.watch(clockProvider).valueOrNull ?? DateTime.now();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Row(
          children: <Widget>[
            const Icon(
              Icons.warning_amber_rounded,
              size: 18,
              color: AppColors.warning,
            ),
            const SizedBox(width: 6),
            Text(
              'نیازمند اقدام شما (${MoneyFormatter.integer(items.length)})',
              style: AppTypography.titleMedium,
            ),
          ],
        ),
        const SizedBox(height: 8),
        for (final settlement in items.take(3))
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: SectionCard(
              borderColor: settlement.isOverdueAt(now)
                  ? AppColors.negative
                  : AppColors.warning,
              onTap: () => context.push('/settlements/${settlement.id}'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Row(
                    children: <Widget>[
                      Expanded(
                        child: Text(
                          settlement.actionTitle,
                          style: AppTypography.titleMedium,
                        ),
                      ),
                      if (settlement.deadlineAt != null)
                        CountdownChip(
                          deadline: settlement.deadlineAt!,
                          now: now,
                        ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Row(
                    children: <Widget>[
                      Text(settlement.settlementCode,
                          style: AppTypography.caption),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          settlement.counterpartyName ?? '',
                          style: AppTypography.caption,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      MoneyDisplay(
                        settlement.amount,
                        compact: true,
                        emphasis: MoneyEmphasis.small,
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        if (items.length > 3)
          TextButton(
            onPressed: () => context.go('/settlements'),
            child: Text(
              'مشاهده همه '
              '(${MoneyFormatter.integer(items.length)})',
            ),
          ),
      ],
    );
  }
}

class TodaySection extends ConsumerWidget {
  const TodaySection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summary = ref.watch(dailySummaryProvider);

    return AsyncValueView<DailySummary>(
      value: summary,
      loading: const BalanceCardSkeleton(),
      onRetry: () => ref.invalidate(dailySummaryProvider),
      data: (value) => SectionCard(
        title: 'امروز',
        child: Column(
          children: <Widget>[
            KeyValueRow(
              label: 'معاملات',
              value: LtrNumber(
                MoneyFormatter.integer(value.tradeCount),
                style: AppTypography.numeric,
              ),
            ),
            KeyValueRow(
              label: 'حجم',
              value: WeightDisplay(value.volume, emphasis: WeightEmphasis.small),
            ),
            KeyValueRow(
              label: 'سود تحقق‌یافته',
              value: MoneyDisplay(
                value.realizedProfit,
                signed: true,
                colorBySign: true,
                emphasis: MoneyEmphasis.small,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AccountStatusNotice extends StatelessWidget {
  const _AccountStatusNotice({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        color: AppColors.warningSurface,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: <Widget>[
            const Icon(Icons.lock_outline, size: 16, color: AppColors.warning),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'وضعیت حساب شما «$status» است؛ امکان معامله وجود ندارد. '
                'برای رفع محدودیت به پنل وب مراجعه کنید.',
                style:
                    AppTypography.caption.copyWith(color: AppColors.warning),
              ),
            ),
          ],
        ),
      );
}
