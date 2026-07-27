import 'package:gold_b2b/core/formatting/number_display.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/rial.dart';

/// Rial -> display string.
///
/// Rial figures in this market are enormous — a routine trade is twenty
/// billion — so there is a real tension between "show the exact number" and
/// "fit on a phone". The rule adopted here:
///
///   * Anywhere the user is about to AGREE to something (order confirmation,
///     settlement amount, payment declaration), the exact grouped figure is
///     shown, in full, with the unit.
///   * Anywhere the figure is contextual (dashboard cards, chart axes, list
///     subtitles), the compact form is allowed, and the exact figure must be
///     one tap away.
///
/// [compact] is never used on a confirmation surface. `ConfirmSheet` asserts
/// this by taking `Rial` and calling [exact] itself.
final class MoneyFormatter {
  const MoneyFormatter._();

  static const String unit = 'ریال';
  static const String perGramUnit = 'ریال/گرم';

  /// `"۱۹,۵۲۱,۹۰۰,۰۰۰"`
  static String exact(Rial amount, {DigitStyle? style}) =>
      NumberDisplay.apply(amount.formatted, style: style);

  /// `"۱۹,۵۲۱,۹۰۰,۰۰۰ ریال"`
  static String exactWithUnit(Rial amount, {DigitStyle? style}) =>
      '${exact(amount, style: style)} $unit';

  /// `"۱۹.۵۲ میلیارد ریال"` — see [Rial.displayCompact] for the double caveat.
  static String compact(Rial amount, {DigitStyle? style}) =>
      NumberDisplay.apply(amount.displayCompact, style: style);

  /// Signed, for ledger rows and P&L: `"+۳۱۸,۲۲۰,۰۰۰"` / `"−۳۱۸,۲۲۰,۰۰۰"`.
  ///
  /// The sign comes from the amount itself, and U+2212 MINUS SIGN is used so
  /// that a negative figure lines up with a positive one in a column.
  static String signed(Rial amount, {DigitStyle? style}) {
    if (amount.isZero) {
      return exact(amount, style: style);
    }

    final magnitude = NumberDisplay.apply(
      amount.absolute.formatted,
      style: style,
    );

    return '${amount.isNegative ? '−' : '+'}$magnitude';
  }

  /// `"۷۸,۴۸۰,۰۰۰"`
  static String price(PricePerFineGram value, {DigitStyle? style}) =>
      NumberDisplay.apply(value.formatted, style: style);

  /// `"۷۸,۴۸۰,۰۰۰ ریال/گرم"`
  static String priceWithUnit(PricePerFineGram value, {DigitStyle? style}) =>
      '${price(value, style: style)} $perGramUnit';

  /// A bare integer with thousands separators — order counts, trade counts,
  /// anything that is a quantity rather than an amount.
  static String integer(int value, {DigitStyle? style}) =>
      NumberDisplay.apply(NumericInput.group(value.toString()), style: style);
}

/// Basis points and percentages.
///
/// Every rate that crosses the wire is an integer in basis points (1% = 100)
/// or hundred-thousandths (0.15% = 150). Neither is ever converted to a double
/// on the way to the screen: the conversion is done with integer division and
/// string assembly, exactly as `NumericInput.fromScaledInt` does.
final class PercentFormatter {
  const PercentFormatter._();

  /// Basis points -> `"۰.۴۲٪"`. 42 bps is 0.42%.
  static String fromBps(int bps, {DigitStyle? style, bool signed = false}) {
    final magnitude = NumericInput.fromScaledInt(bps.abs(), 2);
    final trimmed = _trimTrailingZeros(magnitude);

    final sign = !signed || bps == 0
        ? ''
        : bps < 0
            ? '−'
            : '+';

    return '$sign${NumberDisplay.apply(trimmed, style: style)}'
        '${NumberDisplay.percentSign}';
  }

  /// Hundred-thousandths -> `"۰.۱۵٪"`. 150 is 0.15%.
  static String fromRateX100k(int rateX100k, {DigitStyle? style}) {
    final magnitude = NumericInput.fromScaledInt(rateX100k.abs(), 3);
    final trimmed = _trimTrailingZeros(magnitude);

    return '${NumberDisplay.apply(trimmed, style: style)}'
        '${NumberDisplay.percentSign}';
  }

  /// `"۹۹.۸٪"` from a rate already expressed in basis points, for reputation
  /// figures such as the on-time settlement rate.
  static String rateBps(int bps, {DigitStyle? style}) =>
      fromBps(bps, style: style);

  /// `"۰.۵۰"` -> `"۰.۵"`, `"۱.۰۰"` -> `"۱"`.
  static String _trimTrailingZeros(String decimal) {
    if (!decimal.contains('.')) {
      return decimal;
    }

    var result = decimal;
    while (result.endsWith('0')) {
      result = result.substring(0, result.length - 1);
    }
    if (result.endsWith('.')) {
      result = result.substring(0, result.length - 1);
    }

    return result;
  }
}
