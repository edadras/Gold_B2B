import 'package:gold_b2b/shared/models/int_math.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';

/// A rial amount.
///
/// Dart port of `App\Modules\Shared\ValueObjects\Rial`. Signed, because ledger
/// entries carry direction and the PAYABLE bucket can legitimately go
/// negative. Rate arithmetic uses the x100k scale defined in the docs: 0.15%
/// is `150`.
final class Rial implements Comparable<Rial> {
  const Rial._(this.amount);

  final int amount;

  /// Percentage scale: 0.15% -> 150.
  static const int rateScale = 100000;

  const factory Rial.fromRial(int amount) = Rial._;

  factory Rial.fromString(String input) =>
      Rial._(NumericInput.toScaledInt(input, 0));

  /// Non-throwing form for live form validation.
  static Rial? tryFromString(String input) {
    final value = NumericInput.tryToScaledInt(input, 0);

    return value == null ? null : Rial._(value);
  }

  static const Rial zero = Rial._(0);

  Rial operator +(Rial other) => Rial._(IntMath.add(amount, other.amount));

  Rial operator -(Rial other) => Rial._(IntMath.subtract(amount, other.amount));

  Rial operator -() => Rial._(-amount);

  Rial get absolute => Rial._(amount.abs());

  /// Apply a rate expressed in hundred-thousandths, rounding UP.
  ///
  /// Fees round up (F8): the direction is in the platform's favour and is
  /// disclosed. Do NOT use this for amounts that must tie out by subtraction —
  /// compute one side and derive the other (F9).
  Rial rateCeil(int rateX100k) =>
      Rial._(IntMath.mulDivCeil(amount, rateX100k, rateScale));

  /// Apply a rate rounding DOWN.
  Rial rateFloor(int rateX100k) =>
      Rial._(IntMath.mulDivFloor(amount, rateX100k, rateScale));

  bool get isZero => amount == 0;

  bool get isNegative => amount < 0;

  bool get isPositive => amount > 0;

  bool operator >(Rial other) => amount > other.amount;

  bool operator <(Rial other) => amount < other.amount;

  bool operator >=(Rial other) => amount >= other.amount;

  bool operator <=(Rial other) => amount <= other.amount;

  /// `"19,521,900,000"` — grouped, ASCII digits.
  String get formatted => NumericInput.group(amount.toString());

  /// `"19,521,900,000 ریال"`.
  String get withUnit => '$formatted ریال';

  /// Abbreviated form for cards where the full number will not fit:
  /// `"۹۷.۹۱ میلیارد ریال"`.
  ///
  /// ALLOW_DOUBLE_DISPLAY_ONLY — this is the single place in the app where a
  /// rial value touches floating point. The result is a string that is
  /// rendered and then discarded; no computation is ever performed on it, and
  /// the exact value remains available via [amount] and [formatted]. Every
  /// screen that shows a compact amount must also make the exact amount
  /// reachable (tap to expand, or the detail screen).
  String get displayCompact {
    final magnitude = amount.abs();

    if (magnitude >= 1000000000) {
      final billions = amount / 1000000000;

      return '${billions.toStringAsFixed(2)} میلیارد ریال';
    }

    if (magnitude >= 1000000) {
      final millions = amount / 1000000;

      return '${millions.toStringAsFixed(1)} میلیون ریال';
    }

    return withUnit;
  }

  /// The JSON representation the API expects (`*_rial`, `amount`).
  int toJson() => amount;

  @override
  int compareTo(Rial other) => amount.compareTo(other.amount);

  @override
  bool operator ==(Object other) => other is Rial && other.amount == amount;

  @override
  int get hashCode => amount.hashCode;

  @override
  String toString() => amount.toString();
}
