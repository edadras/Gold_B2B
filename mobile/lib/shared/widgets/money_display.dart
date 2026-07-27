import 'package:flutter/material.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';

enum MoneyEmphasis { small, normal, large }

/// The only sanctioned way to put a rial amount on screen.
///
/// [compact] must be false anywhere the user is agreeing to the number. The
/// confirmation sheet and the payment declaration screen both hard-code it to
/// false; everywhere else it is a judgement call about space.
class MoneyDisplay extends StatelessWidget {
  const MoneyDisplay(
    this.amount, {
    this.emphasis = MoneyEmphasis.normal,
    this.showUnit = true,
    this.compact = false,
    this.signed = false,
    this.colorBySign = false,
    this.color,
    this.isStale = false,
    super.key,
  });

  final Rial amount;
  final MoneyEmphasis emphasis;
  final bool showUnit;

  /// "۱۹.۵۲ میلیارد ریال" instead of the exact figure.
  final bool compact;

  /// Prefix with + or − (U+2212).
  final bool signed;

  /// Green when positive, red when negative. Never the only signal: [signed]
  /// should be true whenever this is.
  final bool colorBySign;

  final Color? color;
  final bool isStale;

  @override
  Widget build(BuildContext context) {
    final String text;
    if (compact) {
      text = MoneyFormatter.compact(amount);
    } else if (signed) {
      final figure = MoneyFormatter.signed(amount);
      text = showUnit ? '$figure ${MoneyFormatter.unit}' : figure;
    } else {
      text = showUnit
          ? MoneyFormatter.exactWithUnit(amount)
          : MoneyFormatter.exact(amount);
    }

    return LtrNumber(
      text,
      style: _style.copyWith(color: _resolveColor()),
      semanticsLabel: MoneyFormatter.exactWithUnit(amount),
    );
  }

  Color? _resolveColor() {
    if (isStale) {
      return AppColors.stale;
    }

    if (color != null) {
      return color;
    }

    if (!colorBySign || amount.isZero) {
      return null;
    }

    return amount.isNegative ? AppColors.negative : AppColors.positive;
  }

  TextStyle get _style => switch (emphasis) {
        MoneyEmphasis.small => AppTypography.numericSmall,
        MoneyEmphasis.normal => AppTypography.numeric,
        MoneyEmphasis.large => AppTypography.numericLarge,
      };
}

/// Price per fine gram — the number the whole market is quoted in.
class PriceDisplay extends StatelessWidget {
  const PriceDisplay(
    this.price, {
    this.emphasis = MoneyEmphasis.normal,
    this.showUnit = false,
    this.color,
    super.key,
  });

  final PricePerFineGram price;
  final MoneyEmphasis emphasis;
  final bool showUnit;
  final Color? color;

  @override
  Widget build(BuildContext context) => LtrNumber(
        showUnit
            ? MoneyFormatter.priceWithUnit(price)
            : MoneyFormatter.price(price),
        style: _style.copyWith(color: color),
        semanticsLabel: MoneyFormatter.priceWithUnit(price),
      );

  TextStyle get _style => switch (emphasis) {
        MoneyEmphasis.small => AppTypography.numericSmall,
        MoneyEmphasis.normal => AppTypography.numeric,
        MoneyEmphasis.large => AppTypography.numericLarge,
      };
}

/// A percentage change with a direction arrow.
///
/// The arrow carries the meaning as well as the colour, per the accessibility
/// rule that colour must never be the sole signal.
class ChangeDisplay extends StatelessWidget {
  const ChangeDisplay(this.bps, {this.emphasis = MoneyEmphasis.normal, super.key});

  /// Change in basis points. Positive is up.
  final int bps;

  final MoneyEmphasis emphasis;

  @override
  Widget build(BuildContext context) {
    final isUp = bps > 0;
    final isFlat = bps == 0;

    final color = isFlat
        ? AppColors.inkMuted
        : isUp
            ? AppColors.positive
            : AppColors.negative;

    final arrow = isFlat
        ? '•'
        : isUp
            ? '▲'
            : '▼';

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(arrow, style: TextStyle(color: color, fontSize: 10)),
        const SizedBox(width: 4),
        LtrNumber(
          PercentFormatter.fromBps(bps.abs()),
          style: _style.copyWith(color: color),
        ),
      ],
    );
  }

  TextStyle get _style => switch (emphasis) {
        MoneyEmphasis.small => AppTypography.numericSmall,
        MoneyEmphasis.normal => AppTypography.numeric,
        MoneyEmphasis.large => AppTypography.numericLarge,
      };
}
