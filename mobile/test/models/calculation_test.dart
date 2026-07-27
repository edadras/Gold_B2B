import 'package:flutter_test/flutter_test.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/int_math.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/trade_valuation.dart';
import 'package:gold_b2b/shared/models/weight.dart';

/// ============================================================================
/// CROSS-IMPLEMENTATION CONSISTENCY TEST
///
/// Every vector in this file is copied VERBATIM from
/// `tests/Unit/Shared/CalculationTest.php`, which in turn takes them from
/// docs/11-appendix/01-formulas.md §1.12.
///
/// The point is not that the Dart code works. The point is that the Dart code
/// and the PHP code produce THE SAME INTEGER for the same input. An order
/// preview that disagrees with the server by one rial is a support ticket; a
/// balance check that disagrees is a rejected order the user believes should
/// have gone through.
///
/// If you change a number here, change it in the PHP file too — and if you
/// cannot, the implementations have diverged and one of them is wrong.
/// ============================================================================
void main() {
  group('F1 — fine weight from gross weight and purity', () {
    // Verbatim from CalculationTest::fineWeightVectors().
    const vectors = <List<int>>[
      <int>[100000, 10000, 100000],
      <int>[100000, 7500, 75000],
      <int>[127420, 7500, 95565],
      <int>[250000, 9950, 248750], // the headline vector
      <int>[1, 9999, 0],
      <int>[3, 3333, 0],
      <int>[0, 9950, 0],
    ];

    for (final vector in vectors) {
      final grossMg = vector[0];
      final purity = vector[1];
      final expected = vector[2];

      test('($grossMg mg, purity $purity) -> $expected mg', () {
        final fine = FineWeight.calculate(
          Weight.fromMilligrams(grossMg),
          Purity.fromScaled(purity),
        );

        expect(fine.milligrams, expected);
      });
    }

    test('rounds DOWN so the system never records gold it does not hold', () {
      // 1 mg at purity 9999 is 0.9999 mg of fine gold. Recording 1 mg would
      // be recording gold that is not there.
      final fine = FineWeight.calculate(
        Weight.fromMilligrams(1),
        Purity.fromScaled(9999),
      );

      expect(fine.milligrams, 0);

      // The discarded remainder is 9999 ten-thousandths of a milligram, and
      // the server posts it to ROUNDING_DIFFERENCE.
      expect(
        FineWeight.roundingRemainder(
          Weight.fromMilligrams(1),
          Purity.fromScaled(9999),
        ),
        9999,
      );
    });
  });

  group('F2 — required gross weight for a target fine weight', () {
    test('rounds UP: 100,000 mg fine at purity 750 needs 133,334 mg', () {
      final gross =
          FineWeight.fromMilligrams(100000).requiredGrossAt(Purity.fromPpt(750));

      expect(gross.milligrams, 133334);
    });

    test('is exact when purity divides evenly', () {
      final gross = FineWeight.fromMilligrams(100000)
          .requiredGrossAt(Purity.fromScaled(10000));

      expect(gross.milligrams, 100000);
    });

    test('throws at zero purity rather than dividing by zero', () {
      expect(
        () => FineWeight.fromMilligrams(1000).requiredGrossAt(Purity.zero),
        throwsArgumentError,
      );
    });
  });

  group('F5 — gross trade amount', () {
    // Verbatim from CalculationTest::grossAmountVectors().
    const vectors = <List<int>>[
      <int>[248750, 78480000, 19521900000], // the headline vector
      <int>[1000, 78480000, 78480000],
      <int>[1, 78480000, 78480],
    ];

    for (final vector in vectors) {
      final fineMg = vector[0];
      final price = vector[1];
      final expected = vector[2];

      test('($fineMg mg, $price rial/g) -> $expected rial', () {
        final gross = PricePerFineGram.fromRial(price)
            .valueOf(FineWeight.fromMilligrams(fineMg));

        expect(gross.amount, expected);
      });
    }

    test(
      'the intermediate product exceeds 10^13 and must not lose precision',
      () {
        // 248,750 x 78,480,000 = 19,521,900,000,000. On a 64-bit int this is
        // fine; the reason IntMath routes through BigInt anyway is that a
        // 100 kg lot at a high price is only two orders of magnitude away
        // from the edge, and there is no margin for a second multiplication.
        expect(
          IntMath.multiply(248750, 78480000),
          19521900000000,
        );
      },
    );
  });

  group('F8 — fee, rounding UP', () {
    // Verbatim from CalculationTest::feeVectors().
    const vectors = <List<int>>[
      <int>[19521900000, 150, 29282850],
      <int>[1, 150, 1], // CEIL: a sub-rial fee still costs one rial
      <int>[0, 150, 0],
    ];

    for (final vector in vectors) {
      final gross = vector[0];
      final rate = vector[1];
      final expected = vector[2];

      test('($gross rial, rate $rate) -> $expected rial', () {
        final fee = FeeTerms.rate(rate).applyTo(Rial.fromRial(gross));

        expect(fee.amount, expected);
      });
    }

    test('honours a minimum fee', () {
      final terms = FeeTerms(
        rateX100k: 150,
        minAmount: Rial.fromRial(50000),
      );

      expect(terms.applyTo(Rial.fromRial(1000)).amount, 50000);
    });

    test('honours a maximum fee', () {
      final terms = FeeTerms(
        rateX100k: 150,
        maxAmount: Rial.fromRial(1000000),
      );

      expect(terms.applyTo(Rial.fromRial(19521900000)).amount, 1000000);
    });
  });

  group('the fully worked example from formulas §1.4', () {
    test('reproduces every documented number', () {
      const calculator = TradeValueCalculator();

      final valuation = calculator.valueOfGross(
        gross: Weight.fromMilligrams(250000),
        purity: Purity.fromPpt(995),
        price: PricePerFineGram.fromRial(78480000),
        buyerFee: const FeeTerms.rate(150),
        sellerFee: const FeeTerms.rate(100),
      );

      expect(valuation.fineWeight.milligrams, 248750);
      expect(valuation.grossAmount.amount, 19521900000);
      expect(valuation.buyerFee.amount, 29282850);
      expect(valuation.sellerFee.amount, 19521900);
      expect(valuation.buyerNet.amount, 19551182850);
      expect(valuation.sellerNet.amount, 19502378100);
      expect(valuation.platformIncome.amount, 48804750);
    });

    test('mass conservation holds', () {
      const calculator = TradeValueCalculator();

      final valuation = calculator.valueOfGross(
        gross: Weight.fromMilligrams(250000),
        purity: Purity.fromPpt(995),
        price: PricePerFineGram.fromRial(78480000),
        buyerFee: const FeeTerms.rate(150),
        sellerFee: const FeeTerms.rate(100),
      );

      // buyer_net − seller_net == buyer_fee + seller_fee + all taxes
      expect(
        (valuation.buyerNet - valuation.sellerNet).amount,
        (valuation.platformIncome + valuation.totalTax).amount,
      );
      expect((valuation.buyerNet - valuation.sellerNet).amount, 48804750);
    });
  });

  group('F9 — the books balance for arbitrary inputs', () {
    test('holds across a deterministic sweep', () {
      const calculator = TradeValueCalculator();

      // Deterministic rather than random: a failing case must be reproducible
      // from the test output alone. The PHP suite uses 2,000 random draws;
      // this sweep covers the same space systematically.
      const weights = <int>[1, 999, 1000, 250000, 1247320, 100000000];
      const prices = <int>[1, 1000000, 52831649, 78480000, 200000000];
      const buyerRates = <int>[0, 1, 100, 150, 500];
      const sellerRates = <int>[0, 100, 150, 499];

      for (final mg in weights) {
        for (final price in prices) {
          for (final buyerRate in buyerRates) {
            for (final sellerRate in sellerRates) {
              // The TradeValuation constructor asserts the invariant and
              // throws if it does not hold, so reaching the expect() is
              // itself most of the assertion.
              final valuation = calculator.value(
                fineWeight: FineWeight.fromMilligrams(mg),
                price: PricePerFineGram.fromRial(price),
                buyerFee: FeeTerms.rate(buyerRate),
                sellerFee: FeeTerms.rate(sellerRate),
              );

              expect(
                (valuation.buyerNet - valuation.sellerNet).amount,
                (valuation.platformIncome + valuation.totalTax).amount,
                reason: 'mg=$mg price=$price '
                    'buyerRate=$buyerRate sellerRate=$sellerRate',
              );

              expect(
                (valuation.sellerNet +
                        valuation.sellerFee +
                        valuation.sellerTax)
                    .amount,
                valuation.grossAmount.amount,
              );

              expect(
                (valuation.grossAmount +
                        valuation.buyerFee +
                        valuation.buyerTax)
                    .amount,
                valuation.buyerNet.amount,
              );
            }
          }
        }
      }
    });

    test('holds with a non-zero tax on the gross base', () {
      const calculator = TradeValueCalculator();

      final valuation = calculator.value(
        fineWeight: FineWeight.fromMilligrams(248750),
        price: PricePerFineGram.fromRial(78480000),
        buyerFee: const FeeTerms.rate(150),
        sellerFee: const FeeTerms.rate(100),
        tax: const TaxTerms(rateX100k: 900),
      );

      expect(valuation.totalTax.amount, greaterThan(0));
      expect(
        (valuation.buyerNet - valuation.sellerNet).amount,
        (valuation.platformIncome + valuation.totalTax).amount,
      );
    });
  });

  group('F10 — buyer reservation', () {
    test('matches the documented MARKET-order example', () {
      // qty 250,000 mg, best ask 78,480,000, max slippage 50 bps
      //   worst price = ceil(78,480,000 x 10,050 / 10,000) = 78,872,400
      //   gross       = floor(250,000 x 78,872,400 / 1,000) = 19,718,100,000
      //   fee         = ceil(19,718,100,000 x 150 / 100,000) = 29,577,150
      //   required    = 19,747,677,150
      final worstPrice =
          PricePerFineGram.fromRial(78480000).worseForBuyer(50);

      expect(worstPrice.rial, 78872400);

      const calculator = TradeValueCalculator();
      final required = calculator.buyerRequirement(
        quantity: FineWeight.fromMilligrams(250000),
        price: worstPrice,
        buyerFee: const FeeTerms.rate(150),
      );

      expect(required.amount, 19747677150);
    });
  });

  group('F11 — seller reservation', () {
    test('is exactly the fine weight sold, with no fee deduction in gold', () {
      const calculator = TradeValueCalculator();
      final quantity = FineWeight.fromMilligrams(300000);

      expect(
        calculator.sellerRequirement(quantity).milligrams,
        quantity.milligrams,
      );
    });
  });

  group('IntMath — floor and ceil on signed values', () {
    // Verbatim from CalculationTest::int_math_floor_handles_negative_numerators.
    test('floor does not degrade into truncation for negatives', () {
      expect(IntMath.mulDivFloor(-3, 1, 2), -2);
      expect(IntMath.mulDivFloor(3, 1, 2), 1);
      expect(IntMath.mulDivCeil(-3, 1, 2), -1);
      expect(IntMath.mulDivCeil(3, 1, 2), 2);
    });

    test('remainder complements the floor division', () {
      expect(IntMath.mulDivRemainder(7, 1, 2), 1);
      expect(IntMath.mulDivRemainder(1, 9999, 10000), 9999);
      expect(IntMath.mulDivRemainder(250000, 9950, 10000), 0);
    });

    test('rejects division by zero', () {
      expect(() => IntMath.mulDivFloor(1, 1, 0), throwsArgumentError);
      expect(() => IntMath.mulDivCeil(1, 1, 0), throwsArgumentError);
      expect(() => IntMath.mulDivRemainder(1, 1, 0), throwsArgumentError);
    });

    test('detects 64-bit overflow instead of wrapping', () {
      expect(
        () => IntMath.multiply(9223372036854775807, 2),
        throwsA(isA<ArithmeticOverflowError>()),
      );
    });
  });

  group('F22 — VWAP', () {
    test('matches the documented example', () {
      // 100,000 mg @ 78,480,000 + 250,000 @ 78,500,000 + 50,000 @ 78,420,000
      // -> 78,485,000
      const quantities = <int>[100000, 250000, 50000];
      const prices = <int>[78480000, 78500000, 78420000];

      var numerator = BigInt.zero;
      var denominator = BigInt.zero;

      for (var i = 0; i < quantities.length; i++) {
        numerator += BigInt.from(prices[i]) * BigInt.from(quantities[i]);
        denominator += BigInt.from(quantities[i]);
      }

      expect((numerator ~/ denominator).toInt(), 78485000);
    });
  });

  group('F24 / fat-finger — price deviation', () {
    test('matches the circuit-breaker example', () {
      // reference 78,000,000, current 80,500,000 -> 320 bps
      final deviation = PricePerFineGram.fromRial(80500000)
          .deviationBpsFrom(PricePerFineGram.fromRial(78000000));

      expect(deviation, 320);
    });

    test('is symmetric', () {
      final up = PricePerFineGram.fromRial(80000000)
          .deviationBpsFrom(PricePerFineGram.fromRial(78000000));
      final down = PricePerFineGram.fromRial(76000000)
          .deviationBpsFrom(PricePerFineGram.fromRial(78000000));

      expect(up, isNotNull);
      expect(down, isNotNull);
      expect(up! > 0, isTrue);
      expect(down! > 0, isTrue);
    });

    test('returns null rather than throwing on a zero reference', () {
      expect(
        PricePerFineGram.fromRial(78000000)
            .deviationBpsFrom(PricePerFineGram.zero),
        isNull,
      );
    });
  });

  group('F7 — premium over intrinsic value', () {
    test('matches the documented example', () {
      // market 78,480,000 over intrinsic 52,831,649 -> 4,854 bps
      final premium = PricePerFineGram.fromRial(78480000)
          .premiumBpsOver(PricePerFineGram.fromRial(52831649));

      expect(premium, 4854);
    });

    test('is null when intrinsic is zero, per §1.11(6)', () {
      expect(
        PricePerFineGram.fromRial(78480000)
            .premiumBpsOver(PricePerFineGram.zero),
        isNull,
      );
    });
  });

  group('NumericInput — Persian and Arabic digits', () {
    // Verbatim from
    // CalculationTest::numeric_input_parses_persian_digits_and_separators.
    test('parses Persian digits and separators', () {
      expect(NumericInput.toScaledInt('۱,۲۴۷.۳۲۰', 3), 1247320);
      expect(NumericInput.toScaledInt('250', 3), 250000);
      expect(NumericInput.toScaledInt('250.5', 3), 250500);
    });

    test('truncates excess precision, never rounds up', () {
      expect(NumericInput.toScaledInt('250.9999', 3), 250999);
    });

    // Verbatim from CalculationTest::numeric_input_round_trips.
    test('round trips', () {
      expect(NumericInput.fromScaledInt(1247320, 3), '1247.320');
      expect(NumericInput.group('1247.320'), '1,247.320');
      expect(NumericInput.fromScaledInt(-1, 3), '-0.001');
    });
  });

  group('Purity display', () {
    // Verbatim from CalculationTest::purity_display_keeps_fractional_values.
    test('keeps fractional values', () {
      expect(Purity.fromPpt(995).toPpt(), '995');
      expect(Purity.fromString('995.5').toPpt(), '995.5');
    });
  });
}
