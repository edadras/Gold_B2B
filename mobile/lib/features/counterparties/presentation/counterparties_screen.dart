import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/counterparties/data/counterparty_repository.dart';
import 'package:gold_b2b/features/counterparties/presentation/widgets/reputation_row.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// Trading relationships and their running balances.
class CounterpartiesScreen extends ConsumerWidget {
  const CounterpartiesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final counterparties = ref.watch(counterpartiesProvider);

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(title: const Text('طرف‌حساب‌ها')),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            Expanded(
              child: RefreshIndicator(
                onRefresh: () async => ref.invalidate(counterpartiesProvider),
                child: AsyncValueView<Paginated<Counterparty>>(
                  value: counterparties,
                  loading: const ListSkeleton(rowHeight: 110),
                  onRetry: () => ref.invalidate(counterpartiesProvider),
                  data: (page) {
                    if (page.isEmpty) {
                      return ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        children: const <Widget>[
                          SizedBox(height: 80),
                          EmptyState(
                            title: 'طرف‌حسابی ثبت نشده است',
                            message: 'پس از اولین معامله، طرف مقابل به این '
                                'فهرست اضافه می‌شود.',
                            icon: Icons.people_outline,
                          ),
                        ],
                      );
                    }

                    return ListView.separated(
                      padding: const EdgeInsets.all(16),
                      physics: const AlwaysScrollableScrollPhysics(),
                      itemCount: page.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 12),
                      itemBuilder: (context, index) =>
                          CounterpartyCard(counterparty: page.items[index]),
                    );
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class CounterpartyCard extends StatelessWidget {
  const CounterpartyCard({required this.counterparty, super.key});

  final Counterparty counterparty;

  @override
  Widget build(BuildContext context) {
    final goldBalance = counterparty.netBalanceGold;

    return SectionCard(
      borderColor: counterparty.isBlocked ? AppColors.negative : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              TierBadge(counterparty.verificationTier),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  counterparty.displayName,
                  style: AppTypography.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (counterparty.isTrusted)
                const StatusBadge(
                  label: 'مورد اعتماد',
                  tone: StatusTone.positive,
                  dense: true,
                  showIcon: false,
                ),
              if (counterparty.isBlocked)
                const StatusBadge(
                  label: 'مسدود',
                  tone: StatusTone.negative,
                  dense: true,
                  showIcon: false,
                ),
            ],
          ),
          if (counterparty.reputation != null) ...<Widget>[
            const SizedBox(height: 8),
            ReputationRow(reputation: counterparty.reputation!),
          ],
          const ThinDivider(vertical: 10),
          if (counterparty.netBalanceRial != null)
            KeyValueRow(
              label: counterparty.netBalanceRial!.isNegative
                  ? 'بدهی ریالی شما'
                  : 'طلب ریالی شما',
              value: MoneyDisplay(
                counterparty.netBalanceRial!.absolute,
                compact: true,
                emphasis: MoneyEmphasis.small,
                color: counterparty.netBalanceRial!.isNegative
                    ? AppColors.negative
                    : AppColors.positive,
              ),
            ),
          if (goldBalance != null && !goldBalance.isZero)
            KeyValueRow(
              label: counterparty.goldBalanceFavoursUs
                  ? 'طلب طلایی شما'
                  : 'بدهی طلایی شما',
              value: WeightDisplay(
                goldBalance,
                emphasis: WeightEmphasis.small,
                color: counterparty.goldBalanceFavoursUs
                    ? AppColors.positive
                    : AppColors.negative,
              ),
            ),
          if (counterparty.openSettlementCount > 0)
            KeyValueRow(
              label: 'تسویه باز',
              value: Text(
                MoneyFormatter.integer(counterparty.openSettlementCount),
                style: AppTypography.numeric,
              ),
            ),
          if (counterparty.creditUsageBps != null) ...<Widget>[
            const SizedBox(height: 6),
            Row(
              children: <Widget>[
                Text('مصرف سقف اعتباری: ', style: AppTypography.caption),
                Text(
                  PercentFormatter.fromBps(counterparty.creditUsageBps!),
                  style: AppTypography.numericSmall.copyWith(
                    color: counterparty.creditUsageBps! > 8000
                        ? AppColors.warning
                        : AppColors.inkMuted,
                  ),
                ),
              ],
            ),
          ],
          if (counterparty.lastTradedAt != null) ...<Widget>[
            const SizedBox(height: 6),
            Text(
              'آخرین معامله: '
              '${JalaliFormatter.smart(counterparty.lastTradedAt!)}',
              style: AppTypography.caption,
            ),
          ],
        ],
      ),
    );
  }
}
