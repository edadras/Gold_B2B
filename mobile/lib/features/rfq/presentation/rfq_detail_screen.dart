import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/purity_formatter.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/counterparties/presentation/widgets/reputation_row.dart';
import 'package:gold_b2b/features/rfq/application/rfq_controller.dart';
import 'package:gold_b2b/features/rfq/data/rfq_repository.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/confirm_sheet.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// Quote comparison (docs/07-mobile-flutter/02-screens.md §2.6).
///
/// Ordered by price, with reputation on every row. The screen deliberately
/// does NOT compute a "best" quote for the user: price, coverage and
/// counterparty risk are three different axes and the trade-off between them
/// is a commercial judgement, not an algorithm.
class RfqDetailScreen extends ConsumerWidget {
  const RfqDetailScreen({required this.rfqId, super.key});

  final int rfqId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rfq = ref.watch(rfqProvider(rfqId));
    final quotes = ref.watch(rfqQuotesProvider(rfqId));
    final now = ref.watch(clockProvider).valueOrNull ?? DateTime.now();

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(title: const Text('درخواست قیمت')),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            Expanded(
              child: AsyncValueView<Rfq>(
                value: rfq,
                onRetry: () => ref.invalidate(rfqProvider(rfqId)),
                data: (value) => ListView(
                  padding: const EdgeInsets.all(16),
                  children: <Widget>[
                    _Header(rfq: value, now: now),
                    const SizedBox(height: 16),
                    Row(
                      children: <Widget>[
                        Text(
                          'پیشنهادهای دریافتی',
                          style: AppTypography.titleMedium,
                        ),
                        const SizedBox(width: 6),
                        Text(
                          '(${MoneyFormatter.integer(value.quoteCount)})',
                          style: AppTypography.caption,
                        ),
                        const Spacer(),
                        Text('مرتب: قیمت', style: AppTypography.caption),
                      ],
                    ),
                    const SizedBox(height: 8),
                    AsyncValueView<List<RfqQuote>>(
                      value: quotes,
                      onRetry: () => ref.invalidate(rfqQuotesProvider(rfqId)),
                      data: (items) {
                        if (items.isEmpty) {
                          return const EmptyState(
                            title: 'هنوز پیشنهادی نرسیده است',
                            message: 'به محض دریافت پیشنهاد، اینجا نمایش '
                                'داده می‌شود.',
                            icon: Icons.hourglass_empty,
                          );
                        }

                        return Column(
                          children: <Widget>[
                            for (var i = 0; i < items.length; i++)
                              Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: _QuoteCard(
                                  quote: items[i],
                                  rfq: value,
                                  rank: i,
                                  now: now,
                                ),
                              ),
                          ],
                        );
                      },
                    ),
                    if (value.isOpen) ...<Widget>[
                      const SizedBox(height: 8),
                      OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: AppColors.negative,
                        ),
                        onPressed: () => ref
                            .read(rfqActionsProvider.notifier)
                            .cancel(value.id),
                        child: const Text('لغو درخواست'),
                      ),
                    ],
                    const SizedBox(height: 32),
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

class _Header extends StatelessWidget {
  const _Header({required this.rfq, required this.now});

  final Rfq rfq;
  final DateTime now;

  @override
  Widget build(BuildContext context) => SectionCard(
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
            Text('درخواست ${rfq.sideLabel}', style: AppTypography.titleLarge),
            const SizedBox(height: 8),
            KeyValueRow(
              label: 'مقدار',
              value: WeightDisplay(rfq.quantity),
            ),
            if (rfq.minPurity != null)
              KeyValueRow(
                label: 'حداقل عیار',
                value: Text(
                  PurityFormatter.ppt(rfq.minPurity!),
                  style: AppTypography.numeric,
                ),
              ),
            if (rfq.settlementType != null)
              KeyValueRow(
                label: 'نوع تسویه',
                value: Text(rfq.settlementType!, style: AppTypography.body),
              ),
            KeyValueRow(
              label: 'پذیرش جزئی',
              value: Text(
                rfq.allowPartial ? 'بله' : 'خیر',
                style: AppTypography.body,
              ),
            ),
          ],
        ),
      );
}

class _QuoteCard extends ConsumerWidget {
  const _QuoteCard({
    required this.quote,
    required this.rfq,
    required this.rank,
    required this.now,
  });

  final RfqQuote quote;
  final Rfq rfq;
  final int rank;
  final DateTime now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isPartial = quote.isPartialFor(rfq.quantity);
    final coverage = quote.coverageBpsOf(rfq.quantity);

    return SectionCard(
      borderColor: rank == 0 ? AppColors.gold : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              if (quote.counterparty != null) ...<Widget>[
                TierBadge(quote.counterparty!.verificationTier),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    quote.counterparty!.displayName,
                    style: AppTypography.titleMedium,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
              const Spacer(),
              if (rank == 0)
                const StatusBadge(
                  label: 'بهترین قیمت',
                  tone: StatusTone.positive,
                  dense: true,
                  showIcon: false,
                ),
              if (isPartial) ...<Widget>[
                const SizedBox(width: 6),
                StatusBadge(
                  label: 'جزئی (${PercentFormatter.fromBps(coverage)})',
                  tone: StatusTone.warning,
                  dense: true,
                  showIcon: false,
                ),
              ],
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: <Widget>[
              WeightDisplay(quote.quantity, emphasis: WeightEmphasis.small),
              const SizedBox(width: 6),
              Text('@', style: AppTypography.caption),
              const SizedBox(width: 6),
              PriceDisplay(quote.price),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: <Widget>[
              Text('کل: ', style: AppTypography.caption),
              MoneyDisplay(
                quote.total,
                compact: true,
                emphasis: MoneyEmphasis.small,
              ),
            ],
          ),
          if (quote.reputation != null) ...<Widget>[
            const ThinDivider(vertical: 8),
            ReputationRow(reputation: quote.reputation!),
          ],
          if (quote.note != null && quote.note!.isNotEmpty) ...<Widget>[
            const SizedBox(height: 6),
            Text(quote.note!, style: AppTypography.caption),
          ],
          if (quote.isPending && rfq.isOpen) ...<Widget>[
            const SizedBox(height: 12),
            OfflineActionGuard(
              builder: (context, online) => FilledButton(
                onPressed: online ? () => _accept(context, ref) : null,
                child: const Text('پذیرش'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _accept(BuildContext context, WidgetRef ref) async {
    final reputation = quote.reputation;

    final result = await confirmFinancialAction(
      context: context,
      ref: ref,
      title: 'پذیرش پیشنهاد',
      amount: quote.total,
      confirmLabel: 'پذیرش',
      warning: reputation != null && reputation.hasElevatedDisputeRate
          // The warning that was on the card follows the user into the
          // confirmation sheet: this is the last moment before a binding
          // trade, and it is exactly where the risk should be restated.
          ? 'نرخ اختلاف این طرف مقابل بالاتر از میانگین بازار است. '
              'پیش از پذیرش، شرایط تسویه را در نظر بگیرید.'
          : 'با پذیرش، معامله قطعی می‌شود و تعهد تسویه ایجاد می‌گردد.',
      lines: <ConfirmationLine>[
        ConfirmationLine(
          label: 'طرف مقابل',
          value: Text(
            quote.counterparty?.displayName ?? '',
            style: AppTypography.body,
          ),
        ),
        ConfirmationLine(
          label: 'مقدار',
          value: WeightDisplay(quote.quantity),
        ),
        ConfirmationLine(
          label: 'قیمت هر گرم',
          value: PriceDisplay(quote.price, showUnit: true),
        ),
        moneyLine('مبلغ کل', quote.total, emphasis: true, dividerAbove: true),
      ],
    );

    if (!result.confirmed || !context.mounted) {
      return;
    }

    final ok =
        await ref.read(rfqActionsProvider.notifier).acceptQuote(quote.id);

    if (!context.mounted) {
      return;
    }

    ref.invalidate(rfqQuotesProvider(rfq.id));
    ref.invalidate(rfqProvider(rfq.id));

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(ok ? 'پیشنهاد پذیرفته شد.' : 'پذیرش انجام نشد.')),
    );
  }
}
