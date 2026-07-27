import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/market/data/models/instrument.dart';
import 'package:gold_b2b/features/market/data/models/quote.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';

/// Last price, day change and spread — the strip at the top of every market
/// screen (docs/07-mobile-flutter/02-screens.md §2.4).
class QuoteHeader extends ConsumerWidget {
  const QuoteHeader({
    required this.quote,
    this.session,
    super.key,
  });

  final Quote quote;
  final MarketSessionState? session;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final realtime = ref.watch(realtimeStatusProvider);
    final last = quote.lastPrice ?? quote.midPrice;
    final spread = quote.spread;
    final spreadBps = quote.spreadBps;

    return Container(
      padding: const EdgeInsets.all(16),
      color: AppColors.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              if (last != null)
                PriceDisplay(last, emphasis: MoneyEmphasis.large)
              else
                const SkeletonBox(width: 140, height: 28),
              const SizedBox(width: 12),
              ChangeDisplay(quote.dayChangeBps),
              const Spacer(),
              if (session != null)
                StatusBadge(
                  label: session!.label,
                  tone: session!.isOpen
                      ? StatusTone.positive
                      : session!.isPaused
                          ? StatusTone.warning
                          : StatusTone.neutral,
                  dense: true,
                ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: <Widget>[
              if (spread != null) ...<Widget>[
                Text('اسپرد ', style: AppTypography.caption),
                LtrNumber(
                  MoneyFormatter.price(spread),
                  style: AppTypography.numericSmall,
                ),
                if (spreadBps != null) ...<Widget>[
                  Text(
                    '  (${PercentFormatter.fromBps(spreadBps)})',
                    style: AppTypography.caption,
                  ),
                ],
              ] else
                Text('بازار یک‌طرفه است', style: AppTypography.caption),
              const Spacer(),
              // The socket state is shown next to the price it feeds, not in
              // some distant status bar: a stale price with a live-looking
              // badge is the worst of both worlds.
              Icon(
                realtime == RealtimeStatus.connected
                    ? Icons.bolt
                    : Icons.bolt_outlined,
                size: 14,
                color: realtime == RealtimeStatus.connected
                    ? AppColors.positive
                    : AppColors.inkDisabled,
              ),
              const SizedBox(width: 4),
              Text(
                JalaliFormatter.time(quote.timestamp),
                style: AppTypography.caption,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Best bid / best ask pair, with the quantity resting at each.
class TopOfBookRow extends StatelessWidget {
  const TopOfBookRow({required this.quote, this.onTapBid, this.onTapAsk, super.key});

  final Quote quote;
  final VoidCallback? onTapBid;
  final VoidCallback? onTapAsk;

  @override
  Widget build(BuildContext context) => Row(
        children: <Widget>[
          Expanded(
            child: _Side(
              label: 'خرید',
              price: quote.bestBid == null
                  ? null
                  : MoneyFormatter.price(quote.bestBid!),
              color: AppColors.buy,
              onTap: onTapBid,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _Side(
              label: 'فروش',
              price: quote.bestAsk == null
                  ? null
                  : MoneyFormatter.price(quote.bestAsk!),
              color: AppColors.sell,
              onTap: onTapAsk,
            ),
          ),
        ],
      );
}

class _Side extends StatelessWidget {
  const _Side({
    required this.label,
    required this.price,
    required this.color,
    this.onTap,
  });

  final String label;
  final String? price;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            color: color.withOpacity(0.06),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Text(label, style: AppTypography.label.copyWith(color: color)),
              const SizedBox(height: 2),
              if (price == null)
                Text('—', style: AppTypography.numeric)
              else
                LtrNumber(price!, style: AppTypography.numeric),
            ],
          ),
        ),
      );
}
