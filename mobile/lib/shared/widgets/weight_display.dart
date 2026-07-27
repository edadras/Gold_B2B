import 'package:flutter/material.dart';
import 'package:gold_b2b/core/formatting/weight_formatter.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/weight.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';

enum WeightEmphasis { small, normal, large }

/// The only sanctioned way to put a weight on screen.
///
/// docs/07-mobile-flutter/02-screens.md §2.10: "always use the shared widget,
/// never a raw Text". Two reasons — the LTR direction override, and the
/// tabular figures that keep columns aligned.
class WeightDisplay extends StatelessWidget {
  const WeightDisplay(
    this.weight, {
    this.emphasis = WeightEmphasis.normal,
    this.showUnit = true,
    this.compact = false,
    this.color,
    this.isStale = false,
    super.key,
  });

  final FineWeight weight;
  final WeightEmphasis emphasis;
  final bool showUnit;

  /// Drop the milligram decimals when the value is a whole number of grams.
  /// For chips and dense rows only — never on a confirmation surface.
  final bool compact;

  final Color? color;

  /// Renders greyed. Always pair with a `StalenessLabel`; colour alone is not
  /// an adequate signal (§2.10, accessibility).
  final bool isStale;

  @override
  Widget build(BuildContext context) {
    final figure =
        compact ? WeightFormatter.compactFine(weight) : WeightFormatter.fine(weight);

    final text = showUnit ? '$figure ${WeightFormatter.fineUnit}' : figure;

    return LtrNumber(
      text,
      style: _style.copyWith(color: isStale ? AppColors.stale : color),
      semanticsLabel: WeightFormatter.fineWithUnit(weight),
    );
  }

  TextStyle get _style => switch (emphasis) {
        WeightEmphasis.small => AppTypography.numericSmall,
        WeightEmphasis.normal => AppTypography.numeric,
        WeightEmphasis.large => AppTypography.numericLarge,
      };
}

/// Gross weight — physical lots, assay certificates, deposits.
///
/// A separate widget rather than a flag, mirroring the type split in the
/// models: a screen that shows both (a lot detail shows gross AND fine) must
/// make it impossible to mistake one for the other, and reading
/// `GrossWeightDisplay(lot.grossWeight)` next to `WeightDisplay(lot.fineWeight)`
/// does that at the call site.
class GrossWeightDisplay extends StatelessWidget {
  const GrossWeightDisplay(
    this.weight, {
    this.emphasis = WeightEmphasis.normal,
    this.showUnit = true,
    this.color,
    super.key,
  });

  final Weight weight;
  final WeightEmphasis emphasis;
  final bool showUnit;
  final Color? color;

  @override
  Widget build(BuildContext context) => LtrNumber(
        showUnit
            ? WeightFormatter.grossWithUnit(weight)
            : WeightFormatter.gross(weight),
        style: _style.copyWith(color: color),
        semanticsLabel: WeightFormatter.grossWithUnit(weight),
      );

  TextStyle get _style => switch (emphasis) {
        WeightEmphasis.small => AppTypography.numericSmall,
        WeightEmphasis.normal => AppTypography.numeric,
        WeightEmphasis.large => AppTypography.numericLarge,
      };
}
