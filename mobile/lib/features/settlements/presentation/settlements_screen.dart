import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/settlements/application/settlements_controller.dart';
import 'package:gold_b2b/features/settlements/data/models/settlement.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// docs/07-mobile-flutter/02-screens.md §2.5.
class SettlementsScreen extends ConsumerWidget {
  const SettlementsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    const filters = SettlementFilter.values;

    return SecureScreen(
      child: DefaultTabController(
        length: filters.length + 1,
        child: Scaffold(
          appBar: AppBar(
            title: const Text('تسویه‌ها'),
            bottom: TabBar(
              isScrollable: true,
              tabs: <Widget>[
                for (final filter in filters) Tab(text: filter.label),
                const Tab(text: 'تهاتر'),
              ],
            ),
          ),
          body: Column(
            children: <Widget>[
              const ConnectivityBanner(),
              Expanded(
                child: TabBarView(
                  children: <Widget>[
                    for (final filter in filters)
                      _SettlementList(filter: filter),
                    const NettingTab(),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _SettlementList extends ConsumerWidget {
  const _SettlementList({required this.filter});

  final SettlementFilter filter;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final settlements = ref.watch(settlementListProvider(filter));
    final now = ref.watch(clockProvider).valueOrNull ?? DateTime.now();

    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(settlementListProvider(filter)),
      child: AsyncValueView<Paginated<Settlement>>(
        value: settlements,
        loading: const ListSkeleton(),
        onRetry: () => ref.invalidate(settlementListProvider(filter)),
        data: (page) {
          if (page.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: <Widget>[
                const SizedBox(height: 80),
                EmptyState(
                  title: filter == SettlementFilter.needsAction
                      ? 'اقدامی در انتظار شما نیست'
                      : 'موردی یافت نشد',
                  message: filter == SettlementFilter.needsAction
                      ? 'هر تسویه‌ای که نوبت اقدام شما باشد اینجا '
                          'نمایش داده می‌شود.'
                      : null,
                  icon: Icons.done_all,
                ),
              ],
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.all(16),
            physics: const AlwaysScrollableScrollPhysics(),
            itemCount: page.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, index) => SettlementCard(
              settlement: page.items[index],
              now: now,
            ),
          );
        },
      ),
    );
  }
}

class SettlementCard extends StatelessWidget {
  const SettlementCard({
    required this.settlement,
    required this.now,
    super.key,
  });

  final Settlement settlement;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final overdue = settlement.isOverdueAt(now);
    final needsAction = settlement.requiresMyAction;

    return SectionCard(
      borderColor: overdue
          ? AppColors.negative
          : needsAction
              ? AppColors.warning
              : null,
      onTap: () => context.push('/settlements/${settlement.id}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Text(settlement.settlementCode, style: AppTypography.code),
              const Spacer(),
              if (settlement.deadlineAt != null)
                CountdownChip(deadline: settlement.deadlineAt!, now: now),
            ],
          ),
          const SizedBox(height: 8),
          Text(settlement.summaryLine, style: AppTypography.titleMedium),
          const ThinDivider(vertical: 10),
          KeyValueRow(
            label: 'مبلغ',
            value: MoneyDisplay(settlement.amount, emphasis: MoneyEmphasis.small),
          ),
          KeyValueRow(
            label: 'مقدار',
            value: WeightDisplay(
              settlement.fineWeight,
              emphasis: WeightEmphasis.small,
            ),
          ),
          KeyValueRow(
            label: 'وضعیت',
            value: StatusBadge(
              label: SettlementStatus.label(settlement.status),
              tone: SettlementStatus.tone(settlement.status),
              dense: true,
            ),
          ),
          if (needsAction) ...<Widget>[
            const SizedBox(height: 10),
            FilledButton(
              onPressed: () => context.push('/settlements/${settlement.id}'),
              child: Text(settlement.actionTitle),
            ),
          ],
        ],
      ),
    );
  }
}
