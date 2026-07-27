import 'package:gold_b2b/shared/models/int_math.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';

/// Gross weight, always stored in milligrams.
///
/// Dart port of `App\Modules\Shared\ValueObjects\Weight`. Deliberately a
/// distinct type from `FineWeight`: mixing gross and fine weight is one of the
/// easiest ways to corrupt a gold ledger, so the type system refuses it. There
/// is no implicit conversion in either direction — going from gross to fine
/// requires a `Purity` (F1), and going back requires one too (F2).
///
/// See docs/00-overview/02-glossary.md §2.6 and ADR-002.
final class Weight implements Comparable<Weight> {
  const Weight._(this.milligrams);

  final int milligrams;

  static const int mgPerGram = 1000;

  /// 4.6083 g, stored scaled by 10 to stay integral.
  static const int mesghalMgX10 = 46083;

  /// 31.1034768 g, stored scaled by 10^4 to stay integral.
  static const int ounceMgX10000 = 311034768;

  factory Weight.fromMilligrams(int mg) {
    if (mg < 0) {
      throw ArgumentError.value(mg, 'mg', 'Weight cannot be negative');
    }

    return Weight._(mg);
  }

  /// Parse a user-entered gram amount without going through `double`.
  ///
  /// Accepts `"250"`, `"250.5"`, `"1,247.320"` and Persian/Arabic digits.
  factory Weight.fromGramsString(String input) =>
      Weight.fromMilligrams(NumericInput.toScaledInt(input, 3));

  /// Non-throwing form for live form validation.
  static Weight? tryFromGramsString(String input) {
    final mg = NumericInput.tryToScaledInt(input, 3);
    if (mg == null || mg < 0) {
      return null;
    }

    return Weight._(mg);
  }

  factory Weight.fromMesghalString(String input) {
    final scaled = NumericInput.toScaledInt(input, 4); // mesghal x 10^4
    return Weight.fromMilligrams(
      IntMath.mulDivFloor(scaled, mesghalMgX10, 100000),
    );
  }

  static const Weight zero = Weight._(0);

  Weight operator +(Weight other) =>
      Weight.fromMilligrams(IntMath.add(milligrams, other.milligrams));

  Weight operator -(Weight other) =>
      Weight.fromMilligrams(IntMath.subtract(milligrams, other.milligrams));

  bool operator >(Weight other) => milligrams > other.milligrams;

  bool operator <(Weight other) => milligrams < other.milligrams;

  bool operator >=(Weight other) => milligrams >= other.milligrams;

  bool operator <=(Weight other) => milligrams <= other.milligrams;

  bool get isZero => milligrams == 0;

  /// Exact decimal string in grams, e.g. `"1247.320"`. Display only.
  String get grams => NumericInput.fromScaledInt(milligrams, 3);

  /// Grouped for UI, e.g. `"1,247.320"`. Display only.
  String get gramsFormatted => NumericInput.group(grams);

  /// F4 — display-only conversion to mesghal, four decimal places.
  String get mesghal => NumericInput.fromScaledInt(
        IntMath.mulDivFloor(milligrams, 100000, mesghalMgX10),
        4,
      );

  /// F4 — display-only conversion to troy ounces, four decimal places.
  String get troyOunces => NumericInput.fromScaledInt(
        IntMath.mulDivFloor(milligrams, 100000000, ounceMgX10000),
        4,
      );

  /// The JSON representation the API expects (`*_mg` fields).
  int toJson() => milligrams;

  @override
  int compareTo(Weight other) => milligrams.compareTo(other.milligrams);

  @override
  bool operator ==(Object other) =>
      other is Weight && other.milligrams == milligrams;

  @override
  int get hashCode => milligrams.hashCode;

  @override
  String toString() => grams;
}
