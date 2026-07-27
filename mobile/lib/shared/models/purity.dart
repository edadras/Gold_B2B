import 'package:gold_b2b/shared/models/numeric_input.dart';

/// Gold purity in ten-thousandths: market purity 995 is stored as `9950`.
///
/// Dart port of `App\Modules\Shared\ValueObjects\Purity`. The extra decade of
/// precision exists because the market occasionally quotes fractional purity
/// (995.5) — see ADR-013.
///
/// Note the API field name is `purity_x10` even though the scale is 10,000;
/// that is the server's naming, and [Purity.fromScaled] is what consumes it.
final class Purity implements Comparable<Purity> {
  const Purity._(this.value);

  /// Raw storage value, 0..10000.
  final int value;

  static const int min = 0;
  static const int max = 10000;
  static const int scale = 10000;

  /// Raw storage value (0..10000), i.e. exactly what the API sends.
  factory Purity.fromScaled(int value) {
    if (value < min || value > max) {
      throw ArgumentError.value(value, 'value', 'Purity is outside 0..10000');
    }

    return Purity._(value);
  }

  /// From conventional market purity: 750, 995, 999.
  factory Purity.fromPpt(int ppt) => Purity.fromScaled(ppt * 10);

  /// From user input that may carry one decimal, e.g. `"995.5"`.
  factory Purity.fromString(String input) =>
      Purity.fromScaled(NumericInput.toScaledInt(input, 1));

  /// Non-throwing form for live form validation.
  static Purity? tryFromString(String input) {
    final scaled = NumericInput.tryToScaledInt(input, 1);
    if (scaled == null || scaled < min || scaled > max) {
      return null;
    }

    return Purity._(scaled);
  }

  static const Purity zero = Purity._(min);
  static const Purity pure = Purity._(max);

  bool isAtLeast(Purity other) => value >= other.value;

  bool get isZero => value == 0;

  /// Conventional display: `"995"` or `"995.5"`.
  String toPpt() {
    if (value % 10 == 0) {
      return (value ~/ 10).toString();
    }

    return NumericInput.fromScaledInt(value, 1);
  }

  /// The JSON representation the API expects (`purity_x10`).
  int toJson() => value;

  @override
  int compareTo(Purity other) => value.compareTo(other.value);

  bool operator >(Purity other) => value > other.value;

  bool operator <(Purity other) => value < other.value;

  bool operator >=(Purity other) => value >= other.value;

  bool operator <=(Purity other) => value <= other.value;

  @override
  bool operator ==(Object other) => other is Purity && other.value == value;

  @override
  int get hashCode => value.hashCode;

  @override
  String toString() => toPpt();
}
