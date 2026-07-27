import 'package:flutter_test/flutter_test.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/weight.dart';

/// `NumericInput` is the only bridge between what a user types and the
/// integers the app computes with. Everything here mirrors the behaviour of
/// `App\Modules\Shared\ValueObjects\NumericInput`.
void main() {
  group('normalizeDigits', () {
    test('converts Persian digits', () {
      expect(NumericInput.normalizeDigits('۰۱۲۳۴۵۶۷۸۹'), '0123456789');
    });

    test('converts Arabic-Indic digits', () {
      expect(NumericInput.normalizeDigits('٠١٢٣٤٥٦٧٨٩'), '0123456789');
    });

    test('converts Arabic separators', () {
      // U+066B decimal separator, U+066C thousands separator.
      expect(NumericInput.normalizeDigits('۱٬۲۴۷٫۳۲۰'), '1,247.320');
    });

    test('leaves ASCII untouched', () {
      expect(NumericInput.normalizeDigits('1,247.320'), '1,247.320');
    });
  });

  group('toScaledInt', () {
    test('handles whole numbers', () {
      expect(NumericInput.toScaledInt('250', 3), 250000);
      expect(NumericInput.toScaledInt('0', 3), 0);
      expect(NumericInput.toScaledInt('1000000', 0), 1000000);
    });

    test('handles decimals up to the scale', () {
      expect(NumericInput.toScaledInt('250.5', 3), 250500);
      expect(NumericInput.toScaledInt('250.05', 3), 250050);
      expect(NumericInput.toScaledInt('250.005', 3), 250005);
    });

    test('TRUNCATES beyond the scale rather than rounding', () {
      // A user cannot conjure weight by typing more digits.
      expect(NumericInput.toScaledInt('250.9999', 3), 250999);
      expect(NumericInput.toScaledInt('0.0009', 3), 0);
      expect(NumericInput.toScaledInt('0.9999', 0), 0);
    });

    test('strips thousands separators, spaces and underscores', () {
      expect(NumericInput.toScaledInt('1,247.320', 3), 1247320);
      expect(NumericInput.toScaledInt('1 247.320', 3), 1247320);
      expect(NumericInput.toScaledInt('1_247.320', 3), 1247320);
    });

    test('accepts a leading decimal point', () {
      expect(NumericInput.toScaledInt('.5', 3), 500);
    });

    test('accepts a trailing decimal point', () {
      expect(NumericInput.toScaledInt('250.', 3), 250000);
    });

    test('handles signs', () {
      expect(NumericInput.toScaledInt('-250.5', 3), -250500);
      expect(NumericInput.toScaledInt('+250.5', 3), 250500);
    });

    test('strips bidi control characters a Persian keyboard inserts', () {
      // ZWNJ, LRM, RLM and NBSP carry no numeric meaning. This is the one
      // deliberate divergence from the PHP implementation: the client is more
      // permissive, and every string PHP accepts still maps to the same
      // integer here.
      expect(NumericInput.toScaledInt('\u200E250.5\u200F', 3), 250500);
      expect(NumericInput.toScaledInt('250 000', 0), 250000);
    });

    test('rejects empty and malformed input', () {
      expect(() => NumericInput.toScaledInt('', 3), throwsFormatException);
      expect(() => NumericInput.toScaledInt('   ', 3), throwsFormatException);
      expect(() => NumericInput.toScaledInt('.', 3), throwsFormatException);
      expect(() => NumericInput.toScaledInt('abc', 3), throwsFormatException);
      expect(() => NumericInput.toScaledInt('1.2.3', 3), throwsFormatException);
      expect(() => NumericInput.toScaledInt('-', 3), throwsFormatException);
    });

    test('rejects values that cannot fit in a 64-bit integer', () {
      expect(
        () => NumericInput.toScaledInt('99999999999999999999999', 3),
        throwsFormatException,
      );
    });

    test('tryToScaledInt returns null instead of throwing', () {
      expect(NumericInput.tryToScaledInt('abc', 3), isNull);
      expect(NumericInput.tryToScaledInt('', 3), isNull);
      expect(NumericInput.tryToScaledInt('250.5', 3), 250500);
    });
  });

  group('fromScaledInt', () {
    test('renders with the full scale', () {
      expect(NumericInput.fromScaledInt(1247320, 3), '1247.320');
      expect(NumericInput.fromScaledInt(250000, 3), '250.000');
      expect(NumericInput.fromScaledInt(5, 3), '0.005');
      expect(NumericInput.fromScaledInt(0, 3), '0.000');
    });

    test('renders negatives', () {
      expect(NumericInput.fromScaledInt(-1, 3), '-0.001');
      expect(NumericInput.fromScaledInt(-1247320, 3), '-1247.320');
    });

    test('renders scale zero without a decimal point', () {
      expect(NumericInput.fromScaledInt(78480000, 0), '78480000');
      expect(NumericInput.fromScaledInt(-5, 0), '-5');
    });
  });

  group('group', () {
    test('inserts thousands separators in the integer part only', () {
      expect(NumericInput.group('1247.320'), '1,247.320');
      expect(NumericInput.group('19521900000'), '19,521,900,000');
      expect(NumericInput.group('999'), '999');
      expect(NumericInput.group('1000'), '1,000');
    });

    test('handles negatives', () {
      expect(NumericInput.group('-1247.320'), '-1,247.320');
    });
  });

  group('round trip', () {
    test('toScaledInt and fromScaledInt are inverse for in-scale values', () {
      const values = <int>[0, 1, 999, 1000, 250500, 1247320, 19521900000];

      for (final value in values) {
        final rendered = NumericInput.fromScaledInt(value, 3);
        expect(
          NumericInput.toScaledInt(rendered, 3),
          value,
          reason: 'round trip failed for $value via "$rendered"',
        );
      }
    });

    test('survives a trip through Persian digits and grouping', () {
      const value = 1247320;
      final display = NumericInput.toPersianDigits(
        NumericInput.group(NumericInput.fromScaledInt(value, 3)),
      );

      expect(display, '۱,۲۴۷.۳۲۰');
      expect(NumericInput.toScaledInt(display, 3), value);
    });
  });

  group('value object parsing', () {
    test('FineWeight from a grams string', () {
      expect(FineWeight.fromGramsString('۱,۲۴۷.۳۲۰').milligrams, 1247320);
      expect(FineWeight.tryFromGramsString('nonsense'), isNull);
      expect(FineWeight.tryFromGramsString('-5'), isNull);
    });

    test('Weight from a grams string', () {
      expect(Weight.fromGramsString('500.300').milligrams, 500300);
    });

    test('Weight from a mesghal string', () {
      // 1 mesghal = 4.6083 g = 4608.3 mg, floored to 4608.
      expect(Weight.fromMesghalString('1').milligrams, 4608);
      expect(Weight.fromMesghalString('10').milligrams, 46083);
    });

    test('Rial from a string', () {
      expect(Rial.fromString('۱۹,۵۲۱,۹۰۰,۰۰۰').amount, 19521900000);
      expect(Rial.tryFromString(''), isNull);
    });

    test('PricePerFineGram from a string', () {
      expect(PricePerFineGram.fromString('۷۸,۴۸۰,۰۰۰').rial, 78480000);
      expect(PricePerFineGram.tryFromString('-1'), isNull);
    });

    test('Purity from a string keeps one decimal', () {
      expect(Purity.fromString('995').value, 9950);
      expect(Purity.fromString('995.5').value, 9955);
      expect(Purity.tryFromString('1200'), isNull); // out of 0..10000
    });

    test('Purity rejects out-of-range scaled values', () {
      expect(() => Purity.fromScaled(10001), throwsArgumentError);
      expect(() => Purity.fromScaled(-1), throwsArgumentError);
    });
  });

  group('value object arithmetic and comparison', () {
    test('FineWeight adds and subtracts', () {
      final a = FineWeight.fromMilligrams(250000);
      final b = FineWeight.fromMilligrams(1000);

      expect((a + b).milligrams, 251000);
      expect((a - b).milligrams, 249000);
      expect(a > b, isTrue);
      expect(b < a, isTrue);
      expect(a.min(b).milligrams, 1000);
    });

    test('FineWeight refuses to go negative', () {
      expect(
        () => FineWeight.fromMilligrams(1) - FineWeight.fromMilligrams(2),
        throwsArgumentError,
      );
    });

    test('percentFloor never overshoots', () {
      final available = FineWeight.fromMilligrams(1047320);

      expect(available.percentFloor(100).milligrams, 1047320);
      expect(available.percentFloor(50).milligrams, 523660);
      // 25% of 1,047,320 is 261,830 exactly; a value that does not divide
      // evenly must round DOWN.
      expect(FineWeight.fromMilligrams(7).percentFloor(50).milligrams, 3);
      expect(available.percentFloor(0).milligrams, 0);
      expect(() => available.percentFloor(101), throwsArgumentError);
    });

    test('Rial is signed and supports unary minus', () {
      final amount = Rial.fromRial(-300000000);

      expect(amount.isNegative, isTrue);
      expect(amount.absolute.amount, 300000000);
      expect((-amount).amount, 300000000);
    });

    test('equality is by value', () {
      expect(
        FineWeight.fromMilligrams(1000) == FineWeight.fromMilligrams(1000),
        isTrue,
      );
      expect(Rial.fromRial(5) == Rial.fromRial(5), isTrue);
      expect(Purity.fromPpt(995) == Purity.fromScaled(9950), isTrue);
    });

    test('tick size validation', () {
      final price = PricePerFineGram.fromRial(78510000);

      expect(price.isMultipleOf(10000), isTrue);
      expect(price.isMultipleOf(7), isFalse);
      expect(
        PricePerFineGram.fromRial(78515000).roundDownToTick(10000).rial,
        78510000,
      );
    });
  });

  group('display strings', () {
    test('FineWeight renders grams with three decimals', () {
      expect(FineWeight.fromMilligrams(1247320).grams, '1247.320');
      expect(FineWeight.fromMilligrams(1247320).gramsFormatted, '1,247.320');
    });

    test('Weight renders mesghal and troy ounces for display only', () {
      final weight = Weight.fromMilligrams(46083);

      expect(weight.mesghal, '10.0000');
      // 31,103 mg is just under one troy ounce (31,103.4768 mg).
      expect(Weight.fromMilligrams(31103).troyOunces, '0.9999');
    });

    test('Rial renders grouped with a unit', () {
      expect(Rial.fromRial(19521900000).formatted, '19,521,900,000');
      expect(Rial.fromRial(19521900000).withUnit, '19,521,900,000 ریال');
    });
  });
}
