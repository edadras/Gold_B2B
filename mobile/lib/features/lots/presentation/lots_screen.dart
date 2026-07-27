import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/purity_formatter.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/lots/application/lots_controller.dart';
import 'package:gold_b2b/features/lots/data/lot_repository.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// "دارایی فیزیکی" (docs/07-mobile-flutter/02-screens.md §2.7).
class LotsScreen extends ConsumerWidget {
  const LotsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final lots = ref.watch(lotsProvider);
    final summary = ref.watch(lotsSummaryProvider);

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(
          title: const Text('دارایی فیزیکی'),
          actions: <Widget>[
            IconButton(
              tooltip: 'اسکن QR',
              onPressed: () => context.push('/lots/scan'),
              icon: const Icon(Icons.qr_code_scanner),
            ),
          ],
        ),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            if (summary != null) _SummaryHeader(summary: summary),
            Expanded(
              child: RefreshIndicator(
                onRefresh: () async => ref.invalidate(lotsProvider),
                child: AsyncValueView<Paginated<GoldLot>>(
                  value: lots,
                  loading: const ListSkeleton(rowHeight: 96),
                  onRetry: () => ref.invalidate(lotsProvider),
                  data: (page) {
                    if (page.isEmpty) {
                      return ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        children: const <Widget>[
                          SizedBox(height: 80),
                          EmptyState(
                            title: 'قطعه‌ای ثبت نشده است',
                            message: 'lotهای سپرده‌شده و در اختیار شما '
                                'اینجا نمایش داده می‌شوند.',
                            icon: Icons.inventory_2_outlined,
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
                          LotCard(lot: page.items[index]),
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

class _SummaryHeader extends StatelessWidget {
  const _SummaryHeader({required this.summary});

  final LotsSummary summary;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        color: AppColors.surface,
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                WeightDisplay(
                  summary.totalFine,
                  emphasis: WeightEmphasis.large,
                ),
                const SizedBox(width: 8),
                Text(
                  '· ${MoneyFormatter.integer(summary.pieceCount)} قطعه',
                  style: AppTypography.bodyMuted,
                ),
              ],
            ),
            const ThinDivider(vertical: 10),
            Row(
              children: <Widget>[
                Text('در خزانه ', style: AppTypography.caption),
                LtrNumber(
                  summary.inVaultFine.gramsFormatted,
                  style: AppTypography.numericSmall,
                ),
                const SizedBox(width: 12),
                Text('· نزد خودم ', style: AppTypography.caption),
                LtrNumber(
                  summary.selfCustodyFine.gramsFormatted,
                  style: AppTypography.numericSmall,
                ),
              ],
            ),
          ],
        ),
      );
}

class LotCard extends ConsumerWidget {
  const LotCard({required this.lot, super.key});

  final GoldLot lot;

  @override
  Widget build(BuildContext context, WidgetRef ref) => SectionCard(
        onTap: () => context.push('/lots/${lot.id}'),
        borderColor: lot.needsAssay ? AppColors.warning : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                Text(lot.lotCode, style: AppTypography.code),
                const Spacer(),
                StatusBadge(
                  label: LotStatus.label(lot.status),
                  tone: LotStatus.tone(lot.status),
                  dense: true,
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: <Widget>[
                GrossWeightDisplay(
                  lot.grossWeight,
                  emphasis: WeightEmphasis.small,
                ),
                const SizedBox(width: 8),
                Text(
                  '· ${PurityFormatter.withLabel(lot.purity)}',
                  style: AppTypography.caption,
                ),
                const SizedBox(width: 4),
                Icon(
                  lot.isAssayCertified
                      ? Icons.verified
                      : Icons.help_outline,
                  size: 14,
                  color: lot.isAssayCertified
                      ? AppColors.positive
                      : AppColors.warning,
                ),
              ],
            ),
            const SizedBox(height: 4),
            Row(
              children: <Widget>[
                Text('خالص ', style: AppTypography.caption),
                WeightDisplay(lot.fineWeight, emphasis: WeightEmphasis.small),
              ],
            ),
            const SizedBox(height: 6),
            Row(
              children: <Widget>[
                Icon(
                  lot.isInVault ? Icons.account_balance : Icons.storefront,
                  size: 14,
                  color: AppColors.inkMuted,
                ),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    lot.isInVault
                        ? '${lot.vaultName ?? 'خزانه'}'
                            '${lot.vaultLocation == null ? '' : ' · ${lot.vaultLocation}'}'
                        : 'نزد خودم',
                    style: AppTypography.caption,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                if (lot.assayedAt != null)
                  Text(
                    JalaliFormatter.date(lot.assayedAt!),
                    style: AppTypography.caption,
                  ),
              ],
            ),
            if (lot.reservedForReference != null) ...<Widget>[
              const SizedBox(height: 6),
              Text(
                'رزروشده بابت ${lot.reservedForReference}',
                style: AppTypography.caption,
              ),
            ],
            if (lot.needsAssay) ...<Widget>[
              const ThinDivider(vertical: 8),
              Row(
                children: <Widget>[
                  const Icon(
                    Icons.priority_high,
                    size: 14,
                    color: AppColors.warning,
                  ),
                  const SizedBox(width: 4),
                  Expanded(
                    child: Text(
                      'برای معامله در بازار نیاز به ری‌گیری دارد',
                      style: AppTypography.caption
                          .copyWith(color: AppColors.warning),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              OfflineActionGuard(
                builder: (context, online) => OutlinedButton(
                  onPressed: online
                      ? () => ref
                          .read(lotActionsProvider.notifier)
                          .requestAssay(lot.id)
                      : null,
                  child: const Text('درخواست ری‌گیری'),
                ),
              ),
            ],
          ],
        ),
      );
}
