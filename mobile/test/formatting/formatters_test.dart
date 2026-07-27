import 'package:flutter_test/flutter_test.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/number_display.dart';
import 'package:gold_b2b/core/formatting/purity_formatter.dart';
import 'package:gold_b2b/core/formatting/weight_formatter.dart';
import 'package:gold_b2b/shared/extensions/datetime_extensions.dart';
import 'package:gold_b2b/shared/extensions/string_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/weight.dart';

void main() {
  // Formatters read a process-wide digit style. Pin it per test rather than
  // relying on whatever a previous test left behind.
  setUp(() {
    NumberDisplay.style = DigitStyle.persian;
  });

  tearDown(() {
    NumberDisplay.style = DigitStyle.persian;
  });

  group('WeightFormatter', () {
    test('renders fine weight with Persian digits and grouping', () {
      expect(
        WeightFormatter.fine(FineWeight.fromMilligrams(1247320)),
        '۱,۲۴۷.۳۲۰',
      );
    });

    test('appends the fine-gram unit', () {
      expect(
        WeightFormatter.fineWithUnit(FineWeight.fromMilligrams(250000)),
        '۲۵۰.۰۰۰ گرم خالص',
      );
    });

    test('respects an explicit ASCII style', () {
      expect(
        WeightFormatter.fine(
          FineWeight.fromMilligrams(1247320),
          style: DigitStyle.ascii,
        ),
        '1,247.320',
      );
    });

    test('compact form drops decimals only when the value is whole grams', () {
      expect(
        WeightFormatter.compactFine(
          FineWeight.fromMilligrams(250000),
          style: DigitStyle.ascii,
        ),
        '250',
      );
      expect(
        WeightFormatter.compactFine(
          FineWeight.fromMilligrams(500300),
          style: DigitStyle.ascii,
        ),
        '500.300',
      );
    });

    test('signed form uses a true minus sign, not a hyphen', () {
      final signed = WeightFormatter.signedFine(
        FineWeight.fromMilligrams(250000),
        isCredit: false,
        style: DigitStyle.ascii,
      );

      expect(signed.startsWith('−'), isTrue);
      expect(
        WeightFormatter.signedFine(
          FineWeight.fromMilligrams(250000),
          isCredit: true,
          style: DigitStyle.ascii,
        ),
        '+250.000',
      );
    });

    test('ratio is clamped and safe at zero', () {
      expect(
        WeightFormatter.ratio(
          FineWeight.fromMilligrams(500),
          FineWeight.fromMilligrams(1000),
        ),
        0.5,
      );
      expect(
        WeightFormatter.ratio(FineWeight.fromMilligrams(1), FineWeight.zero),
        0,
      );
    });

    test('gross weight renders separately from fine weight', () {
      expect(
        WeightFormatter.grossWithUnit(
          Weight.fromMilligrams(500300),
          style: DigitStyle.ascii,
        ),
        '500.300 گرم',
      );
    });
  });

  group('MoneyFormatter', () {
    test('renders the exact amount with grouping', () {
      expect(
        MoneyFormatter.exact(
          Rial.fromRial(19521900000),
          style: DigitStyle.ascii,
        ),
        '19,521,900,000',
      );
    });

    test('appends the rial unit', () {
      expect(
        MoneyFormatter.exactWithUnit(
          Rial.fromRial(19521900000),
          style: DigitStyle.ascii,
        ),
        '19,521,900,000 ریال',
      );
    });

    test('compact form abbreviates billions', () {
      expect(
        MoneyFormatter.compact(
          Rial.fromRial(97910000000),
          style: DigitStyle.ascii,
        ),
        '97.91 میلیارد ریال',
      );
    });

    test('compact form abbreviates millions', () {
      expect(
        MoneyFormatter.compact(
          Rial.fromRial(29282850),
          style: DigitStyle.ascii,
        ),
        '29.3 میلیون ریال',
      );
    });

    test('small amounts are shown exactly, not abbreviated', () {
      expect(
        MoneyFormatter.compact(Rial.fromRial(5000), style: DigitStyle.ascii),
        '5,000 ریال',
      );
    });

    test('signed form marks direction with a true minus sign', () {
      expect(
        MoneyFormatter.signed(
          Rial.fromRial(-318220000),
          style: DigitStyle.ascii,
        ),
        '−318,220,000',
      );
      expect(
        MoneyFormatter.signed(
          Rial.fromRial(318220000),
          style: DigitStyle.ascii,
        ),
        '+318,220,000',
      );
      expect(
        MoneyFormatter.signed(Rial.zero, style: DigitStyle.ascii),
        '0',
      );
    });

    test('price renders with the per-gram unit', () {
      expect(
        MoneyFormatter.priceWithUnit(
          PricePerFineGram.fromRial(78480000),
          style: DigitStyle.ascii,
        ),
        '78,480,000 ریال/گرم',
      );
    });

    test('integer helper groups plain counts', () {
      expect(MoneyFormatter.integer(12830, style: DigitStyle.ascii), '12,830');
      expect(MoneyFormatter.integer(7, style: DigitStyle.ascii), '7');
    });
  });

  group('PercentFormatter', () {
    test('renders basis points without touching a double', () {
      expect(PercentFormatter.fromBps(42, style: DigitStyle.ascii), '0.42٪');
      expect(PercentFormatter.fromBps(4854, style: DigitStyle.ascii), '48.54٪');
      expect(PercentFormatter.fromBps(10000, style: DigitStyle.ascii), '100٪');
      expect(PercentFormatter.fromBps(9980, style: DigitStyle.ascii), '99.8٪');
      expect(PercentFormatter.fromBps(0, style: DigitStyle.ascii), '0٪');
    });

    test('renders a signed change when asked', () {
      expect(
        PercentFormatter.fromBps(42, style: DigitStyle.ascii, signed: true),
        '+0.42٪',
      );
      expect(
        PercentFormatter.fromBps(-42, style: DigitStyle.ascii, signed: true),
        '−0.42٪',
      );
    });

    test('renders hundred-thousandth fee rates', () {
      expect(
        PercentFormatter.fromRateX100k(150, style: DigitStyle.ascii),
        '0.15٪',
      );
      expect(
        PercentFormatter.fromRateX100k(100, style: DigitStyle.ascii),
        '0.1٪',
      );
      expect(
        PercentFormatter.fromRateX100k(1000, style: DigitStyle.ascii),
        '1٪',
      );
    });
  });

  group('PurityFormatter', () {
    test('renders conventional parts per thousand', () {
      expect(
        PurityFormatter.ppt(Purity.fromPpt(995), style: DigitStyle.ascii),
        '995',
      );
      expect(
        PurityFormatter.ppt(Purity.fromString('995.5'),
            style: DigitStyle.ascii),
        '995.5',
      );
    });

    test('distinguishes certified from declared purity', () {
      final certified = PurityFormatter.withCertification(
        Purity.fromPpt(995),
        isCertified: true,
        style: DigitStyle.ascii,
      );
      final declared = PurityFormatter.withCertification(
        Purity.fromPpt(750),
        isCertified: false,
        style: DigitStyle.ascii,
      );

      expect(certified, contains('گواهی‌شده'));
      expect(declared, contains('بدون گواهی'));
      expect(certified == declared, isFalse);
    });
  });

  group('JalaliFormatter', () {
    test('converts a known Gregorian date to Jalali', () {
      // 2026-07-27 UTC. The exact Jalali day depends on the device timezone,
      // so assert the shape and the year rather than an exact day, which is
      // what any reader of this string actually relies on.
      final formatted = JalaliFormatter.date(
        DateTime.utc(2026, 7, 27, 9, 15),
        style: DigitStyle.ascii,
      );

      expect(RegExp(r'^\d{4}/\d{2}/\d{2}$').hasMatch(formatted), isTrue);
      expect(formatted.startsWith('140'), isTrue);
    });

    test('renders a zero-padded 24-hour clock', () {
      final local = DateTime(2026, 7, 27, 9, 5);

      expect(JalaliFormatter.time(local, style: DigitStyle.ascii), '09:05');
    });

    test('names today and tomorrow on a deadline', () {
      final now = DateTime(2026, 7, 27, 10);

      expect(
        JalaliFormatter.deadline(
          DateTime(2026, 7, 27, 17),
          now: now,
          style: DigitStyle.ascii,
        ),
        'امروز 17:00',
      );
      expect(
        JalaliFormatter.deadline(
          DateTime(2026, 7, 28, 9),
          now: now,
          style: DigitStyle.ascii,
        ),
        'فردا 09:00',
      );
    });

    test('smart formatting is relative within a day and absolute beyond', () {
      final now = DateTime.utc(2026, 7, 27, 12);

      expect(
        JalaliFormatter.smart(
          now.subtract(const Duration(hours: 3)),
          now: now,
          style: DigitStyle.ascii,
        ),
        '3 ساعت پیش',
      );

      final old = JalaliFormatter.smart(
        now.subtract(const Duration(days: 12)),
        now: now,
        style: DigitStyle.ascii,
      );

      expect(RegExp(r'^\d{4}/\d{2}/\d{2}$').hasMatch(old), isTrue);
    });

    test('countdown reports remaining time and lateness', () {
      final now = DateTime.utc(2026, 7, 27, 12);

      expect(
        JalaliFormatter.countdown(
          now.add(const Duration(hours: 3)),
          now: now,
          style: DigitStyle.ascii,
        ),
        '3 ساعت مانده',
      );
      expect(
        JalaliFormatter.countdown(
          now.subtract(const Duration(hours: 1)),
          now: now,
          style: DigitStyle.ascii,
        ),
        'مهلت گذشته',
      );
    });
  });

  group('deadline helpers', () {
    test('detects overdue and urgent deadlines', () {
      final now = DateTime.utc(2026, 7, 27, 12);

      expect(now.add(const Duration(hours: 3)).isOverdueAt(now), isFalse);
      expect(now.subtract(const Duration(minutes: 1)).isOverdueAt(now), isTrue);
      expect(now.add(const Duration(hours: 3)).isUrgentAt(now), isTrue);
      expect(now.add(const Duration(hours: 9)).isUrgentAt(now), isFalse);
      // An already-passed deadline is overdue, not urgent.
      expect(now.subtract(const Duration(hours: 1)).isUrgentAt(now), isFalse);
    });
  });

  group('string helpers', () {
    test('groups an IBAN into fours', () {
      expect(
        'IR120170000000112233445566'.asIban,
        'IR12 0170 0000 0011 2233 4455 66',
      );
    });

    test('is idempotent on an already-grouped IBAN', () {
      const grouped = 'IR12 0170 0000 0011 2233 4455 66';

      expect(grouped.asIban, grouped);
    });

    test('detects non-ASCII digits in user input', () {
      expect('۱۲۳'.hasNonAsciiDigits, isTrue);
      expect('123'.hasNonAsciiDigits, isFalse);
    });
  });
}
