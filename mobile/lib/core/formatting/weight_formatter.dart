import 'package:gold_b2b/core/formatting/number_display.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/models/weight.dart';

/// Milligrams -> display string.
///
/// Never rounds. A weight is shown with all three decimal places or not at
/// all: "۵۰۰.۳" and "۵۰۰.۳۰۰" are the same number but only the second one
/// tells the reader that the value is exact to the milligram, and on a
/// settlement screen that distinction is the difference between agreeing and
/// arguing.
final class WeightFormatter {
  const WeightFormatter._();

  static const String fineUnit = 'گرم خالص';
  static const String grossUnit = 'گرم';

  /// `"۱,۲۴۷.۳۲۰"`
  static String fine(FineWeight weight, {DigitStyle? style}) =>
      NumberDisplay.apply(weight.gramsFormatted, style: style);

  /// `"۱,۲۴۷.۳۲۰ گرم خالص"`
  static String fineWithUnit(FineWeight weight, {DigitStyle? style}) =>
      '${fine(weight, style: style)} $fineUnit';

  /// `"۵۰۰.۳۰۰"`
  static String gross(Weight weight, {DigitStyle? style}) =>
      NumberDisplay.apply(weight.gramsFormatted, style: style);

  /// `"۵۰۰.۳۰۰ گرم"`
  static String grossWithUnit(Weight weight, {DigitStyle? style}) =>
      '${gross(weight, style: style)} $grossUnit';

  /// Signed form for ledger rows: `"+۲۵۰.۰۰۰"` / `"−۲۵۰.۰۰۰"`.
  ///
  /// Uses U+2212 MINUS SIGN rather than a hyphen so the glyph is the same
  /// width as the plus and columns line up.
  static String signedFine(
    FineWeight weight, {
    required bool isCredit,
    DigitStyle? style,
  }) {
    if (weight.isZero) {
      return fine(weight, style: style);
    }

    return '${isCredit ? '+' : '−'}${fine(weight, style: style)}';
  }

  /// Compact form for chips and dense list rows: drops the decimals when the
  /// value is a whole number of grams, which most order quantities are.
  ///
  /// `250000 mg` -> `"۲۵۰"`, `500300 mg` -> `"۵۰۰.۳۰۰"`.
  static String compactFine(FineWeight weight, {DigitStyle? style}) {
    if (weight.milligrams % Weight.mgPerGram == 0) {
      final grams = weight.milligrams ~/ Weight.mgPerGram;

      return NumberDisplay.apply(
        NumericInput.group(grams.toString()),
        style: style,
      );
    }

    return fine(weight, style: style);
  }

  /// Mesghal, the unit most bazaar traders still think in. Display only —
  /// nothing is ever ordered, settled or ledgered in mesghal.
  static String mesghal(Weight weight, {DigitStyle? style}) =>
      '${NumberDisplay.apply(NumericInput.group(weight.mesghal), style: style)}'
      ' مثقال';

  /// Fraction of [total] that [part] represents, as a 0..1 ratio for progress
  /// bars. Returns 0 when [total] is zero.
  ///
  /// ALLOW_DOUBLE_DISPLAY_ONLY — the result drives a bar width in logical
  /// pixels and is never shown as a number.
  // ignore: prefer_int_literals
  static double ratio(FineWeight part, FineWeight total) { // ALLOW_DOUBLE_DISPLAY_ONLY
    if (total.isZero) {
      return 0;
    }

    final value = part.milligrams / total.milligrams;

    return value.clamp(0.0, 1.0);
  }
}
