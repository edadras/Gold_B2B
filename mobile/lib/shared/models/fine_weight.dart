import 'package:gold_b2b/shared/models/int_math.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/models/weight.dart';

/// Pure-gold-equivalent weight in milligrams.
///
/// Dart port of `App\Modules\Shared\ValueObjects\FineWeight`. This is the unit
/// the gold ledger is denominated in, and the unit the whole market quotes
/// against, so it is what every order quantity and every balance is expressed
/// in. Deliberately a separate type from [Weight] so gross and fine can never
/// be added together.
///
/// Formula F1, docs/11-appendix/01-formulas.md.
final class FineWeight implements Comparable<FineWeight> {
  const FineWeight._(this.milligrams);

  final int milligrams;

  factory FineWeight.fromMilligrams(int mg) {
    if (mg < 0) {
      throw ArgumentError.value(mg, 'mg', 'Fine weight cannot be negative');
    }

    return FineWeight._(mg);
  }

  /// Parse a user-entered fine-gram amount. Persian and Arabic digits are
  /// accepted; precision beyond milligrams is truncated, never rounded up.
  factory FineWeight.fromGramsString(String input) =>
      FineWeight.fromMilligrams(NumericInput.toScaledInt(input, 3));

  /// Non-throwing form for live form validation, where a partially typed
  /// value is the normal case rather than an error.
  static FineWeight? tryFromGramsString(String input) {
    final mg = NumericInput.tryToScaledInt(input, 3);
    if (mg == null || mg < 0) {
      return null;
    }

    return FineWeight._(mg);
  }

  static const FineWeight zero = FineWeight._(0);

  /// F1 — `fine = floor(gross * purity / 10000)`.
  ///
  /// Rounds DOWN so the system never records gold it does not hold. The
  /// discarded remainder is available via [roundingRemainder]; on the server
  /// it is posted to the ROUNDING_DIFFERENCE system account.
  factory FineWeight.calculate(Weight gross, Purity purity) =>
      FineWeight.fromMilligrams(
        IntMath.mulDivFloor(gross.milligrams, purity.value, Purity.scale),
      );

  /// The sub-milligram remainder discarded by [FineWeight.calculate],
  /// expressed in ten-thousandths of a milligram.
  static int roundingRemainder(Weight gross, Purity purity) =>
      IntMath.mulDivRemainder(gross.milligrams, purity.value, Purity.scale);

  /// F2 — `gross = ceil(fine * 10000 / purity)`.
  ///
  /// Rounds UP: the caller needs at least this much gross weight to deliver
  /// the requested fine weight.
  Weight requiredGrossAt(Purity purity) {
    if (purity.isZero) {
      throw ArgumentError.value(
        purity,
        'purity',
        'Cannot derive gross weight at zero purity',
      );
    }

    return Weight.fromMilligrams(
      IntMath.mulDivCeil(milligrams, Purity.scale, purity.value),
    );
  }

  FineWeight operator +(FineWeight other) =>
      FineWeight.fromMilligrams(IntMath.add(milligrams, other.milligrams));

  FineWeight operator -(FineWeight other) =>
      FineWeight.fromMilligrams(IntMath.subtract(milligrams, other.milligrams));

  bool operator >(FineWeight other) => milligrams > other.milligrams;

  bool operator <(FineWeight other) => milligrams < other.milligrams;

  bool operator >=(FineWeight other) => milligrams >= other.milligrams;

  bool operator <=(FineWeight other) => milligrams <= other.milligrams;

  bool get isZero => milligrams == 0;

  FineWeight min(FineWeight other) =>
      milligrams <= other.milligrams ? this : other;

  /// A fraction of this weight, rounded DOWN — used by the 25%/50%/75%
  /// quick-fill buttons on the order form. Rounding down guarantees the
  /// "max" button can never propose more than the available balance.
  FineWeight percentFloor(int percent) {
    if (percent < 0 || percent > 100) {
      throw ArgumentError.value(percent, 'percent', 'Must be 0..100');
    }

    return FineWeight._(IntMath.mulDivFloor(milligrams, percent, 100));
  }

  /// Exact decimal string in fine grams, e.g. `"1247.320"`. Display only.
  String get grams => NumericInput.fromScaledInt(milligrams, 3);

  /// Grouped for UI, e.g. `"1,247.320"`. Display only.
  String get gramsFormatted => NumericInput.group(grams);

  /// The JSON representation the API expects (`quantity_mg`, `*_fine_mg`).
  int toJson() => milligrams;

  @override
  int compareTo(FineWeight other) => milligrams.compareTo(other.milligrams);

  @override
  bool operator ==(Object other) =>
      other is FineWeight && other.milligrams == milligrams;

  @override
  int get hashCode => milligrams.hashCode;

  @override
  String toString() => grams;
}
