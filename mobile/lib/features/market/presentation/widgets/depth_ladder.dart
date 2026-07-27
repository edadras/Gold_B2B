import 'package:flutter/material.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/weight_formatter.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/market/data/models/quote.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';

/// The order book, asks descending on top and bids descending below, with the
/// spread called out in the middle (docs/07-mobile-flutter/02-screens.md §2.4).
///
/// Tapping a row fills the order form with that price — §2.4: "Interaction:
/// tapping a row auto-fills the order form with that price."
class DepthLadder extends StatelessWidget {
  const DepthLadder({
    required this.depth,
    this.onSelectPrice,
    this.maxLevels = 8,
    super.key,
  });

  final MarketDepth depth;
  final void Function(PricePerFineGram price)? onSelectPrice;
  final int maxLevels;

  @override
  Widget build(BuildContext context) {
    if (depth.isEmpty) {
      return const EmptyState(
        title: 'سفارشی در دفتر نیست',
        message: 'در حال حاضر هیچ سفارش بازی برای این ابزار ثبت نشده است.',
        icon: Icons.horizontal_rule,
      );
    }

    final scale = depth.maxLevelQuantity;

    // Asks are sent lowest-first; the ladder shows the highest ask at the top
    // so that price runs monotonically down the screen and the spread sits in
    // the middle where it belongs.
    final asks = depth.asks.take(maxLevels).toList(growable: false).reversed
        .toList(growable: false);
    final bids = depth.bids.take(maxLevels).toList(growable: false);

    final spread = _spreadOf(depth);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
          child: Text('فروش', style: AppTypography.label.copyWith(color: AppColors.sell)),
        ),
        for (final level in asks)
          _LadderRow(
            level: level,
            scale: scale,
            color: AppColors.sell,
            onTap: onSelectPrice == null
                ? null
                : () => onSelectPrice!(level.price),
          ),
        Container(
          margin: const EdgeInsets.symmetric(vertical: 6),
          padding: const EdgeInsets.symmetric(vertical: 6),
          decoration: const BoxDecoration(
            border: Border.symmetric(
              horizontal: BorderSide(color: AppColors.divider),
            ),
          ),
          child: Center(
            child: spread == null
                ? Text('—', style: AppTypography.caption)
                : Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text('اسپرد ', style: AppTypography.caption),
                      LtrNumber(
                        MoneyFormatter.price(spread),
                        style: AppTypography.numericSmall,
                      ),
                    ],
                  ),
          ),
        ),
        for (final level in bids)
          _LadderRow(
            level: level,
            scale: scale,
            color: AppColors.buy,
            onTap: onSelectPrice == null
                ? null
                : () => onSelectPrice!(level.price),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
          child: Text('خرید', style: AppTypography.label.copyWith(color: AppColors.buy)),
        ),
      ],
    );
  }

  static PricePerFineGram? _spreadOf(MarketDepth depth) {
    if (depth.bids.isEmpty || depth.asks.isEmpty) {
      return null;
    }

    final bestBid = depth.bids.first.price;
    final bestAsk = depth.asks.first.price;
    if (bestAsk < bestBid) {
      return null;
    }

    return PricePerFineGram.fromRial(bestAsk.rial - bestBid.rial);
  }
}

class _LadderRow extends StatelessWidget {
  const _LadderRow({
    required this.level,
    required this.scale,
    required this.color,
    this.onTap,
  });

  final DepthLevel level;
  final FineWeight scale;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    // The depth bar is the one place a double is legitimate: it is a fraction
    // of the row width, computed from integers and never displayed.
    final fill = WeightFormatter.ratio(level.quantity, scale);

    return InkWell(
      onTap: onTap,
      child: Semantics(
        button: onTap != null,
        label: '${MoneyFormatter.price(level.price)} — '
            '${WeightFormatter.fineWithUnit(level.quantity)}',
        child: SizedBox(
          height: 34,
          child: Stack(
            children: <Widget>[
              // Depth bar grows from the leading edge of the row, which in an
              // RTL layout is the right — matching the mockup.
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: FractionallySizedBox(
                  widthFactor: fill,
                  child: Container(color: color.withOpacity(0.10)),
                ),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Row(
                  children: <Widget>[
                    LtrNumber(
                      MoneyFormatter.price(level.price),
                      style: AppTypography.numeric.copyWith(color: color),
                    ),
                    const Spacer(),
                    LtrNumber(
                      WeightFormatter.compactFine(level.quantity),
                      style: AppTypography.numericSmall,
                    ),
                    const SizedBox(width: 4),
                    Text('گرم', style: AppTypography.caption),
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
