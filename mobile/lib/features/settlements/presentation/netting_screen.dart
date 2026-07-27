import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/weight_formatter.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/settlements/application/settlements_controller.dart';
import 'package:gold_b2b/features/settlements/data/models/netting_batch.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/confirm_sheet.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// Netting proposals (docs/07-mobile-flutter/02-screens.md §2.9).
class NettingTab extends ConsumerWidget {
  const NettingTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final batches = ref.watch(nettingBatchesProvider);
    final now = ref.watch(clockProvider).valueOrNull ?? DateTime.now();

    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(nettingBatchesProvider),
      child: AsyncValueView<List<NettingBatch>>(
        value: batches,
        loading: const ListSkeleton(rows: 2, rowHeight: 160),
        onRetry: () => ref.invalidate(nettingBatchesProvider),
        data: (items) {
          if (items.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: const <Widget>[
                SizedBox(height: 80),
                EmptyState(
                  title: 'پیشنهاد تهاتری وجود ندارد',
                  message: 'تهاتر زمانی پیشنهاد می‌شود که چند تعهد '
                      'متقابل قابل خالص‌سازی باشند.',
                  icon: Icons.compare_arrows,
                ),
              ],
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.all(16),
            physics: const AlwaysScrollableScrollPhysics(),
            itemCount: items.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, index) =>
                NettingCard(batch: items[index], now: now),
          );
        },
      ),
    );
  }
}

class NettingCard extends ConsumerWidget {
  const NettingCard({required this.batch, required this.now, super.key});

  final NettingBatch batch;
  final DateTime now;

  @override
  Widget build(BuildContext context, WidgetRef ref) => SectionCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                Text(batch.batchCode, style: AppTypography.code),
                const Spacer(),
                if (batch.expiresAt != null)
                  CountdownChip(deadline: batch.expiresAt!, now: now)
                else
                  StatusBadge(
                    label: batch.statusLabel,
                    tone: batch.statusTone,
                    dense: true,
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              '${MoneyFormatter.integer(batch.participantCount)} عضو '
              'شرکت‌کننده',
              style: AppTypography.caption,
            ),
            const ThinDivider(),
            Text('بدون تهاتر — تعهدات شما:', style: AppTypography.label),
            const SizedBox(height: 6),
            for (final obligation in batch.obligations)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Text(
                        obligation.counterpartyName,
                        style: AppTypography.bodyMuted,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    LtrNumber(
                      WeightFormatter.compactFine(obligation.weight),
                      style: AppTypography.numericSmall,
                    ),
                    const SizedBox(width: 4),
                    Text(
                      'گرم ${obligation.directionLabel}',
                      style: AppTypography.caption.copyWith(
                        color: obligation.isDebit
                            ? AppColors.negative
                            : AppColors.positive,
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 8),
            Center(
              child: Text(
                '▼ با تهاتر ▼',
                style: AppTypography.caption,
              ),
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.infoSurface,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Text('موقعیت خالص شما', style: AppTypography.label),
                  const SizedBox(height: 6),
                  Row(
                    children: <Widget>[
                      WeightDisplay(
                        batch.netPosition,
                        emphasis: WeightEmphasis.large,
                        showUnit: false,
                      ),
                      const SizedBox(width: 8),
                      Text(
                        batch.netPositionMg == 0
                            ? 'گرم — تسویه کامل'
                            : batch.isDebit
                                ? 'گرم بدهکار'
                                : 'گرم بستانکار',
                        style: AppTypography.body.copyWith(
                          color: batch.netPositionMg == 0
                              ? AppColors.positive
                              : batch.isDebit
                                  ? AppColors.negative
                                  : AppColors.positive,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    batch.netPositionMg == 0
                        ? 'هیچ انتقالی لازم نیست.'
                        : '۱ انتقال به‌جای '
                            '${MoneyFormatter.integer(batch.obligations.length)}',
                    style: AppTypography.caption,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),
            Text(
              'تهاتر فقط با پذیرش همه شرکت‌کنندگان اجرا می‌شود. '
              'پذیرش ${MoneyFormatter.integer(batch.acceptedCount)} نفر از '
              '${MoneyFormatter.integer(batch.participantCount)} ثبت شده.',
              style: AppTypography.caption,
            ),
            if (batch.awaitsMyResponse) ...<Widget>[
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppColors.warningSurface,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  'با پذیرش، تعهدات فوق تسویه‌شده تلقی می‌شود و مانده '
                  'خالص جایگزین آن می‌گردد.',
                  style: AppTypography.caption
                      .copyWith(color: AppColors.warning),
                ),
              ),
              const SizedBox(height: 12),
              OfflineActionGuard(
                builder: (context, online) => Row(
                  children: <Widget>[
                    Expanded(
                      child: OutlinedButton(
                        onPressed: online ? () => _reject(context, ref) : null,
                        child: const Text('رد'),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton.icon(
                        onPressed: online ? () => _accept(context, ref) : null,
                        icon: const Icon(Icons.lock_outline, size: 16),
                        label: const Text('پذیرش'),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      );

  Future<void> _accept(BuildContext context, WidgetRef ref) async {
    final result = await confirmFinancialAction(
      context: context,
      ref: ref,
      title: 'پذیرش تهاتر',
      // Netting is denominated in gold, not rial; there is no amount to
      // threshold against, so zero is passed and the large-amount banner is
      // suppressed. The weight is spelled out in the lines instead.
      amount: Rial.zero,
      confirmLabel: 'پذیرش',
      warning: 'با پذیرش، تعهدات ناخالص شما در این دسته تسویه‌شده تلقی '
          'می‌شود و تنها مانده خالص باقی می‌ماند.',
      lines: <ConfirmationLine>[
        ConfirmationLine(
          label: 'دسته',
          value: Text(batch.batchCode, style: AppTypography.code),
        ),
        ConfirmationLine(
          label: 'تعداد تعهدات',
          value: Text(
            MoneyFormatter.integer(batch.obligations.length),
            style: AppTypography.numeric,
          ),
        ),
        ConfirmationLine(
          label: batch.isDebit ? 'موقعیت خالص (بدهکار)' : 'موقعیت خالص (بستانکار)',
          value: WeightDisplay(batch.netPosition),
          emphasis: true,
          dividerAbove: true,
        ),
      ],
    );

    if (!result.confirmed || !context.mounted) {
      return;
    }

    final ok = await ref.read(nettingActionsProvider.notifier).accept(
          batchId: batch.id,
          totpCode: result.totpCode,
        );

    if (!context.mounted) {
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(ok ? 'تهاتر پذیرفته شد.' : 'پذیرش انجام نشد.')),
    );
  }

  Future<void> _reject(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('رد تهاتر'),
        content: const Text(
          'با رد این پیشنهاد، تعهدات به‌صورت ناخالص و جداگانه تسویه '
          'خواهند شد.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('رد کن'),
          ),
        ],
      ),
    );

    if (confirmed != true || !context.mounted) {
      return;
    }

    await ref.read(nettingActionsProvider.notifier).reject(batchId: batch.id);
  }
}
