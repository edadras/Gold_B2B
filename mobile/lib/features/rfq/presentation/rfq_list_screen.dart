import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/purity_formatter.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/rfq/application/rfq_controller.dart';
import 'package:gold_b2b/features/rfq/data/rfq_repository.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// My RFQs and the inbox of RFQs I can answer.
class RfqListTab extends ConsumerWidget {
  const RfqListTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) => DefaultTabController(
        length: 2,
        child: Column(
          children: <Widget>[
            const TabBar(
              tabs: <Widget>[
                Tab(text: 'درخواست‌های من'),
                Tab(text: 'دریافتی'),
              ],
            ),
            const Expanded(
              child: TabBarView(
                children: <Widget>[
                  _RfqList(mine: true),
                  _RfqList(mine: false),
                ],
              ),
            ),
            SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: FilledButton.icon(
                  onPressed: () => context.push('/rfq/create'),
                  icon: const Icon(Icons.add),
                  label: const Text('درخواست قیمت جدید'),
                ),
              ),
            ),
          ],
        ),
      );
}

class _RfqList extends ConsumerWidget {
  const _RfqList({required this.mine});

  final bool mine;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final provider = mine ? myRfqsProvider : rfqInboxProvider;
    final rfqs = ref.watch(provider);
    final now = ref.watch(clockProvider).valueOrNull ?? DateTime.now();

    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(provider),
      child: AsyncValueView<Paginated<Rfq>>(
        value: rfqs,
        loading: const ListSkeleton(),
        onRetry: () => ref.invalidate(provider),
        data: (page) {
          if (page.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: <Widget>[
                const SizedBox(height: 80),
                EmptyState(
                  title: mine
                      ? 'درخواستی ثبت نکرده‌اید'
                      : 'درخواستی دریافت نکرده‌اید',
                  icon: Icons.request_quote_outlined,
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
                RfqCard(rfq: page.items[index], now: now),
          );
        },
      ),
    );
  }
}

class RfqCard extends StatelessWidget {
  const RfqCard({required this.rfq, required this.now, super.key});

  final Rfq rfq;
  final DateTime now;

  @override
  Widget build(BuildContext context) => SectionCard(
        onTap: () => context.push('/rfq/${rfq.id}'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                Text(rfq.rfqCode, style: AppTypography.code),
                const Spacer(),
                if (rfq.expiresAt != null)
                  CountdownChip(deadline: rfq.expiresAt!, now: now)
                else
                  StatusBadge(
                    label: RfqStatus.label(rfq.status),
                    tone: RfqStatus.tone(rfq.status),
                    dense: true,
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              'درخواست ${rfq.sideLabel}',
              style: AppTypography.titleMedium,
            ),
            const SizedBox(height: 6),
            Row(
              children: <Widget>[
                WeightDisplay(rfq.quantity, emphasis: WeightEmphasis.small),
                if (rfq.minPurity != null) ...<Widget>[
                  const SizedBox(width: 8),
                  Text(
                    '· عیار ≥ ${PurityFormatter.ppt(rfq.minPurity!)}',
                    style: AppTypography.caption,
                  ),
                ],
                if (rfq.settlementType != null) ...<Widget>[
                  const SizedBox(width: 8),
                  Text('· ${rfq.settlementType}', style: AppTypography.caption),
                ],
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: <Widget>[
                if (rfq.recipientCount != null)
                  Text(
                    'ارسال به ${MoneyFormatter.integer(rfq.recipientCount!)} '
                    'فروشنده',
                    style: AppTypography.caption,
                  ),
                const Spacer(),
                Text(
                  '${MoneyFormatter.integer(rfq.quoteCount)} پیشنهاد',
                  style: AppTypography.caption,
                ),
              ],
            ),
          ],
        ),
      );
}
