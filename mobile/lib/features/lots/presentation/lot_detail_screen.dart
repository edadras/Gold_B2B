import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/formatting/purity_formatter.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/lots/application/lots_controller.dart';
import 'package:gold_b2b/features/lots/data/lot_repository.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/confirm_sheet.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// Lot detail with lineage (docs/07-mobile-flutter/02-screens.md §2.7).
class LotDetailScreen extends ConsumerWidget {
  const LotDetailScreen({required this.lotId, super.key});

  final int lotId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final lot = ref.watch(lotProvider(lotId));

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(title: const Text('جزئیات قطعه')),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            Expanded(
              child: AsyncValueView<GoldLot>(
                value: lot,
                onRetry: () => ref.invalidate(lotProvider(lotId)),
                data: (value) => _Body(lot: value),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.lot});

  final GoldLot lot;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final lineage = ref.watch(lotLineageProvider(lot.id));

    return ListView(
      padding: const EdgeInsets.all(16),
      children: <Widget>[
        SectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Text(lot.lotCode, style: AppTypography.titleLarge),
                  const Spacer(),
                  StatusBadge(
                    label: LotStatus.label(lot.status),
                    tone: LotStatus.tone(lot.status),
                  ),
                ],
              ),
              const ThinDivider(),
              KeyValueRow(
                label: 'وزن ناخالص',
                value: GrossWeightDisplay(lot.grossWeight),
              ),
              KeyValueRow(
                label: 'عیار',
                value: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(
                      PurityFormatter.ppt(lot.purity),
                      style: AppTypography.numeric,
                    ),
                    const SizedBox(width: 4),
                    Icon(
                      lot.isAssayCertified
                          ? Icons.verified
                          : Icons.help_outline,
                      size: 16,
                      color: lot.isAssayCertified
                          ? AppColors.positive
                          : AppColors.warning,
                    ),
                    const SizedBox(width: 2),
                    Text(
                      lot.isAssayCertified
                          ? PurityFormatter.certifiedLabel
                          : PurityFormatter.declaredLabel,
                      style: AppTypography.caption,
                    ),
                  ],
                ),
              ),
              KeyValueRow(
                label: 'وزن خالص',
                value: WeightDisplay(lot.fineWeight),
              ),
              if (lot.marketValue != null)
                KeyValueRow(
                  label: 'ارزش روز',
                  value: MoneyDisplay(lot.marketValue!, compact: true),
                ),
              if (lot.serialNumber != null)
                KeyValueRow(
                  label: 'سریال',
                  value: Text(lot.serialNumber!, style: AppTypography.code),
                ),
              if (lot.hallmark != null)
                KeyValueRow(
                  label: 'انگ',
                  value: Text(lot.hallmark!, style: AppTypography.code),
                ),
              if (lot.form != null)
                KeyValueRow(
                  label: 'شکل',
                  value: Text(lot.form!, style: AppTypography.body),
                ),
            ],
          ),
        ),
        if (lot.assayCertificateCode != null) ...<Widget>[
          const SizedBox(height: 12),
          SectionCard(
            title: 'گواهی ری‌گیری',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                CopyableCode(lot.assayCertificateCode!),
                const SizedBox(height: 6),
                Text(
                  <String?>[
                    lot.assayLabName,
                    lot.assayMethod == null ? null : 'روش ${lot.assayMethod}',
                    lot.assayedAt == null
                        ? null
                        : JalaliFormatter.date(lot.assayedAt!),
                  ].whereType<String>().join(' · '),
                  style: AppTypography.bodyMuted,
                ),
              ],
            ),
          ),
        ],
        const SizedBox(height: 12),
        SectionCard(
          title: 'نگهداری',
          child: Row(
            children: <Widget>[
              Icon(
                lot.isInVault ? Icons.account_balance : Icons.storefront,
                size: 18,
                color: AppColors.inkMuted,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  lot.isInVault
                      ? <String?>[lot.vaultName, lot.vaultLocation]
                          .whereType<String>()
                          .join(' · ')
                      : 'نزد خودم',
                  style: AppTypography.body,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: 'شجره‌نامه',
          child: AsyncValueView<LotLineage>(
            value: lineage,
            onRetry: () => ref.invalidate(lotLineageProvider(lot.id)),
            data: (value) {
              if (value.isEmpty) {
                return Text(
                  'این قطعه از تفکیک یا ادغام حاصل نشده است.',
                  style: AppTypography.bodyMuted,
                );
              }

              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  for (final ancestor in value.ancestors)
                    _LineageRow(node: ancestor),
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Row(
                      children: <Widget>[
                        const Icon(
                          Icons.my_location,
                          size: 14,
                          color: AppColors.gold,
                        ),
                        const SizedBox(width: 6),
                        Text(
                          '${lot.lotCode} ◄ اینجا',
                          style: AppTypography.numericSmall,
                        ),
                      ],
                    ),
                  ),
                  for (final descendant in value.descendants)
                    _LineageRow(node: descendant),
                  if (value.operations.isNotEmpty) ...<Widget>[
                    const ThinDivider(vertical: 8),
                    for (final operation in value.operations)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 3),
                        child: Row(
                          children: <Widget>[
                            Expanded(
                              child: Text(
                                operation.type,
                                style: AppTypography.caption,
                              ),
                            ),
                            if (!operation.lossFine.isZero) ...<Widget>[
                              Text('افت ', style: AppTypography.caption),
                              WeightDisplay(
                                operation.lossFine,
                                emphasis: WeightEmphasis.small,
                                showUnit: false,
                              ),
                              Text(' گرم', style: AppTypography.caption),
                            ],
                          ],
                        ),
                      ),
                  ],
                ],
              );
            },
          ),
        ),
        const SizedBox(height: 16),
        OfflineActionGuard(
          builder: (context, online) => Row(
            children: <Widget>[
              if (lot.needsAssay)
                Expanded(
                  child: OutlinedButton(
                    onPressed: online
                        ? () => ref
                            .read(lotActionsProvider.notifier)
                            .requestAssay(lot.id)
                        : null,
                    child: const Text('ری‌گیری'),
                  ),
                ),
              if (lot.isInVault) ...<Widget>[
                if (lot.needsAssay) const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton(
                    onPressed:
                        online ? () => _requestWithdrawal(context, ref) : null,
                    child: const Text('برداشت'),
                  ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 32),
      ],
    );
  }

  Future<void> _requestWithdrawal(BuildContext context, WidgetRef ref) async {
    final result = await confirmFinancialAction(
      context: context,
      ref: ref,
      title: 'درخواست برداشت',
      // Withdrawals are denominated in gold, not rial.
      amount: lot.marketValue ?? Rial.zero,
      confirmLabel: 'ثبت درخواست',
      warning: 'برداشت فیزیکی پس از تأیید خزانه و در ساعات کاری انجام '
          'می‌شود. تا زمان تحویل، این قطعه قابل معامله نخواهد بود.',
      lines: <ConfirmationLine>[
        ConfirmationLine(
          label: 'قطعه',
          value: Text(lot.lotCode, style: AppTypography.code),
        ),
        ConfirmationLine(
          label: 'وزن ناخالص',
          value: GrossWeightDisplay(lot.grossWeight),
        ),
        ConfirmationLine(
          label: 'وزن خالص',
          value: WeightDisplay(lot.fineWeight),
          emphasis: true,
          dividerAbove: true,
        ),
      ],
    );

    if (!result.confirmed || !context.mounted) {
      return;
    }

    final ok = await ref.read(lotActionsProvider.notifier).requestWithdrawal(
          lotId: lot.id,
          totpCode: result.totpCode,
        );

    if (!context.mounted) {
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(ok ? 'درخواست برداشت ثبت شد.' : 'ثبت درخواست انجام نشد.'),
      ),
    );
  }
}

class _LineageRow extends StatelessWidget {
  const _LineageRow({required this.node});

  final LineageNode node;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: <Widget>[
            SizedBox(width: 12.0 * node.depth),
            const Icon(
              Icons.subdirectory_arrow_left,
              size: 14,
              color: AppColors.inkDisabled,
            ),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                '${node.lotCode} · ${node.operation}',
                style: AppTypography.caption,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            GrossWeightDisplay(
              node.grossWeight,
              emphasis: WeightEmphasis.small,
              showUnit: false,
            ),
          ],
        ),
      );
}
