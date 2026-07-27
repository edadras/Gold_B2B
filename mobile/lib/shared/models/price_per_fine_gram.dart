import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/int_math.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/weight.dart';

/// Rial per gram of pure gold.
///
/// Dart port of `App\Modules\Shared\ValueObjects\PricePerFineGram`. The whole
/// market quotes in fine grams so that purities are directly comparable —
/// docs/03-domain/04-trading.md §4.1.
final class PricePerFineGram implements Comparable<PricePerFineGram> {
  const PricePerFineGram._(this.rial);

  final int rial;

  static const int bpsScale = 10000;

  factory PricePerFineGram.fromRial(int rial) {
    if (rial < 0) {
      throw ArgumentError.value(rial, 'rial', 'Price cannot be negative');
    }

    return PricePerFineGram._(rial);
  }

  factory PricePerFineGram.fromString(String input) =>
      PricePerFineGram.fromRial(NumericInput.toScaledInt(input, 0));

  /// Non-throwing form for live form validation.
  static PricePerFineGram? tryFromString(String input) {
    final value = NumericInput.tryToScaledInt(input, 0);
    if (value == null || value < 0) {
      return null;
    }

    return PricePerFineGram._(value);
  }

  static const PricePerFineGram zero = PricePerFineGram._(0);

  /// F5 — `gross = floor(fine_mg * price / 1000)`.
  Rial valueOf(FineWeight weight) => Rial.fromRial(
        IntMath.mulDivFloor(weight.milligrams, rial, Weight.mgPerGram),
      );

  /// Apply a slippage allowance upward, rounding up (worst case for a buyer).
  ///
  /// This is what a MARKET buy order must reserve against — F10.
  PricePerFineGram worseForBuyer(int slippageBps) => PricePerFineGram._(
        IntMath.mulDivCeil(rial, bpsScale + slippageBps, bpsScale),
      );

  /// Apply a slippage allowance downward, rounding down (worst case for a
  /// seller).
  PricePerFineGram worseForSeller(int slippageBps) => PricePerFineGram._(
        IntMath.mulDivFloor(rial, bpsScale - slippageBps, bpsScale),
      );

  /// Deviation from a reference price in basis points, always non-negative.
  ///
  /// F24 — drives the fat-finger warning on the order form and, server-side,
  /// the circuit breaker. Returns null rather than throwing when the reference
  /// is zero, because the order form asks this question on every keystroke and
  /// a missing quote must not blow up the UI.
  int? deviationBpsFrom(PricePerFineGram reference) {
    if (reference.rial == 0) {
      return null;
    }

    return IntMath.mulDivFloor(
      (rial - reference.rial).abs(),
      bpsScale,
      reference.rial,
    );
  }

  /// F7 — premium ("حباب") over an intrinsic price, in basis points. Signed:
  /// a market trading below intrinsic value yields a negative premium.
  ///
  /// Returns null when [intrinsic] is zero, per the edge-case table in
  /// docs/11-appendix/01-formulas.md §1.11(6).
  int? premiumBpsOver(PricePerFineGram intrinsic) {
    if (intrinsic.rial == 0) {
      return null;
    }

    return IntMath.mulDivFloor(
      rial - intrinsic.rial,
      bpsScale,
      intrinsic.rial,
    );
  }

  bool isMultipleOf(int tickSize) => tickSize > 0 && rial % tickSize == 0;

  /// The nearest valid price at or below this one for a given tick size.
  PricePerFineGram roundDownToTick(int tickSize) {
    if (tickSize <= 0) {
      return this;
    }

    return PricePerFineGram._(rial - rial % tickSize);
  }

  bool operator >(PricePerFineGram other) => rial > other.rial;

  bool operator <(PricePerFineGram other) => rial < other.rial;

  bool operator >=(PricePerFineGram other) => rial >= other.rial;

  bool operator <=(PricePerFineGram other) => rial <= other.rial;

  bool get isZero => rial == 0;

  /// `"78,480,000"` — grouped, ASCII digits.
  String get formatted => NumericInput.group(rial.toString());

  /// `"78,480,000 ریال/گرم"`.
  String get withUnit => '$formatted ریال/گرم';

  /// The JSON representation the API expects (`price_rial`).
  int toJson() => rial;

  @override
  int compareTo(PricePerFineGram other) => rial.compareTo(other.rial);

  @override
  bool operator ==(Object other) =>
      other is PricePerFineGram && other.rial == rial;

  @override
  int get hashCode => rial.hashCode;

  @override
  String toString() => rial.toString();
}
