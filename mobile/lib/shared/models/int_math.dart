/// Overflow-safe integer arithmetic for financial values.
///
/// Dart port of `App\Modules\Shared\Support\IntMath`. Where the PHP version
/// routes intermediate products through bcmath, this version routes them
/// through [BigInt] — the reason is the same in both languages: the product
/// `fine_weight_mg * price_per_gram_rial` for a large lot is ~10^16, which
/// still fits in 64 bits, but only just, and there is no margin left for a
/// second multiplication before an overflow silently produces a wrong number.
///
/// See docs/11-appendix/01-formulas.md and ADR-002.
library;

final class IntMath {
  const IntMath._();

  /// Largest value representable by a Dart native `int`.
  static final BigInt maxInt = BigInt.parse('9223372036854775807');

  /// Smallest value representable by a Dart native `int`.
  static final BigInt minInt = BigInt.parse('-9223372036854775808');

  /// `floor(a * b / c)` with an arbitrary-precision intermediate product.
  ///
  /// Throws [ArithmeticOverflowError] if the result does not fit in an `int`,
  /// and [ArgumentError] if [c] is zero.
  static int mulDivFloor(int a, int b, int c) {
    if (c == 0) {
      throw ArgumentError.value(c, 'c', 'Division by zero in mulDivFloor');
    }

    final product = BigInt.from(a) * BigInt.from(b);

    return _toInt(_divFloor(product, BigInt.from(c)));
  }

  /// `ceil(a * b / c)` with an arbitrary-precision intermediate product.
  ///
  /// Throws [ArithmeticOverflowError] if the result does not fit in an `int`,
  /// and [ArgumentError] if [c] is zero.
  static int mulDivCeil(int a, int b, int c) {
    if (c == 0) {
      throw ArgumentError.value(c, 'c', 'Division by zero in mulDivCeil');
    }

    final product = BigInt.from(a) * BigInt.from(b);

    return _toInt(_divCeil(product, BigInt.from(c)));
  }

  /// The remainder discarded by [mulDivFloor]: `a*b - floor(a*b/c)*c`.
  ///
  /// The server routes this dust into the `ROUNDING_DIFFERENCE` system account
  /// so that mass conservation holds. The client only ever displays it.
  static int mulDivRemainder(int a, int b, int c) {
    if (c == 0) {
      throw ArgumentError.value(c, 'c', 'Division by zero in mulDivRemainder');
    }

    final divisor = BigInt.from(c);
    final product = BigInt.from(a) * BigInt.from(b);
    final quotient = _divFloor(product, divisor);

    return _toInt(product - quotient * divisor);
  }

  /// Addition with overflow detection.
  static int add(int a, int b) => _toInt(BigInt.from(a) + BigInt.from(b));

  /// Subtraction with overflow detection.
  static int subtract(int a, int b) => _toInt(BigInt.from(a) - BigInt.from(b));

  /// Multiplication with overflow detection.
  static int multiply(int a, int b) => _toInt(BigInt.from(a) * BigInt.from(b));

  /// Sum of [values] with overflow detection.
  static int sum(Iterable<int> values) {
    var total = BigInt.zero;
    for (final value in values) {
      total += BigInt.from(value);
    }

    return _toInt(total);
  }

  /// BigInt `~/` truncates toward zero. Ledger amounts are signed, so a true
  /// floor is required: `-3 ~/ 2` is `-1`, but `floor(-3/2)` is `-2`.
  static BigInt _divFloor(BigInt numerator, BigInt denominator) {
    final quotient = numerator ~/ denominator;
    final remainder = numerator - quotient * denominator;

    if (remainder != BigInt.zero && _signsDiffer(numerator, denominator)) {
      return quotient - BigInt.one;
    }

    return quotient;
  }

  static BigInt _divCeil(BigInt numerator, BigInt denominator) {
    final quotient = numerator ~/ denominator;
    final remainder = numerator - quotient * denominator;

    if (remainder != BigInt.zero && !_signsDiffer(numerator, denominator)) {
      return quotient + BigInt.one;
    }

    return quotient;
  }

  static bool _signsDiffer(BigInt a, BigInt b) => a.isNegative != b.isNegative;

  static int _toInt(BigInt value) {
    if (value > maxInt || value < minInt) {
      throw ArithmeticOverflowError(value.toString());
    }

    return value.toInt();
  }
}

/// Thrown when a financial computation produces a value outside the 64-bit
/// integer range. This is never recoverable in the UI: it means the inputs are
/// nonsensical, so it surfaces as a hard error rather than a formatted zero.
class ArithmeticOverflowError extends Error {
  ArithmeticOverflowError(this.value);

  final String value;

  @override
  String toString() =>
      'ArithmeticOverflowError: $value does not fit in a 64-bit integer';
}
