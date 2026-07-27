import 'package:gold_b2b/core/formatting/number_display.dart';
import 'package:gold_b2b/shared/extensions/datetime_extensions.dart';
import 'package:shamsi_date/shamsi_date.dart';

/// UTC instant -> Jalali display string.
///
/// Two invariants:
///
///   1. Every timestamp from the API is UTC (docs/05-api/01-conventions.md
///      §1.1) and every model keeps it that way. This class is the ONLY place
///      that converts to local time, and it does so explicitly on entry.
///   2. The server also sends `*_jalali` convenience fields. They are ignored.
///      §1.12 is explicit that display fields are a convenience and the
///      numeric value is authoritative; deriving the Jalali string locally
///      means the app renders consistently even when a response omits them
///      (`?include_display=false`) and cannot drift between screens.
final class JalaliFormatter {
  const JalaliFormatter._();

  static const List<String> monthNames = <String>[
    'فروردین',
    'اردیبهشت',
    'خرداد',
    'تیر',
    'مرداد',
    'شهریور',
    'مهر',
    'آبان',
    'آذر',
    'دی',
    'بهمن',
    'اسفند',
  ];

  static const List<String> weekdayNames = <String>[
    'شنبه',
    'یکشنبه',
    'دوشنبه',
    'سه‌شنبه',
    'چهارشنبه',
    'پنجشنبه',
    'جمعه',
  ];

  /// `"۱۴۰۴/۰۸/۰۵"`
  static String date(DateTime instant, {DigitStyle? style}) {
    final j = Jalali.fromDateTime(instant.toLocal());

    return NumberDisplay.apply(
      '${j.year}/${_two(j.month)}/${_two(j.day)}',
      style: style,
    );
  }

  /// `"۱۴:۳۰"`
  static String time(DateTime instant, {DigitStyle? style}) {
    final local = instant.toLocal();

    return NumberDisplay.apply(
      '${_two(local.hour)}:${_two(local.minute)}',
      style: style,
    );
  }

  /// `"۱۴۰۴/۰۸/۰۵ ۱۴:۳۰"`
  static String dateTime(DateTime instant, {DigitStyle? style}) =>
      '${date(instant, style: style)} ${time(instant, style: style)}';

  /// `"۱۴۰۴/۰۸/۰۵ ۱۴:۳۰:۳۳"` — for audit trails and event histories, where the
  /// second matters.
  static String dateTimeWithSeconds(DateTime instant, {DigitStyle? style}) {
    final local = instant.toLocal();

    return '${date(instant, style: style)} '
        '${NumberDisplay.apply(
      '${_two(local.hour)}:${_two(local.minute)}:${_two(local.second)}',
      style: style,
    )}';
  }

  /// `"۵ آبان ۱۴۰۴"`
  static String longDate(DateTime instant, {DigitStyle? style}) {
    final j = Jalali.fromDateTime(instant.toLocal());
    final month = monthNames[(j.month - 1).clamp(0, 11)];

    return NumberDisplay.apply('${j.day} $month ${j.year}', style: style);
  }

  /// `"پنجشنبه ۵ آبان"` — used as a day separator in the trade tape.
  static String weekdayAndDay(DateTime instant, {DigitStyle? style}) {
    final j = Jalali.fromDateTime(instant.toLocal());
    // shamsi_date numbers weekdays 1..7 starting at Saturday.
    final weekday = weekdayNames[(j.weekDay - 1).clamp(0, 6)];
    final month = monthNames[(j.month - 1).clamp(0, 11)];

    return NumberDisplay.apply('$weekday ${j.day} $month', style: style);
  }

  /// Relative where it helps, absolute where it does not.
  ///
  /// Within the last day, "۳ ساعت پیش" is what a trader wants. Beyond that,
  /// "۱۴۰۴/۰۸/۰۱" is, because "۱۲ روز پیش" forces mental arithmetic.
  static String smart(
    DateTime instant, {
    DateTime? now,
    DigitStyle? style,
  }) {
    final reference = (now ?? DateTime.now()).toUtc();
    final age = reference.difference(instant.toUtc());

    if (age.isNegative) {
      return dateTime(instant, style: style);
    }

    if (age.inHours < 24) {
      return NumberDisplay.apply(age.asPersianAgo, style: style);
    }

    return date(instant, style: style);
  }

  /// `"امروز ۱۷:۰۰"`, `"فردا ۰۹:۰۰"`, otherwise `"۱۴۰۴/۰۸/۰۶ ۰۹:۰۰"`.
  ///
  /// Settlement deadlines are almost always today or tomorrow, and naming the
  /// day removes an entire class of misreading.
  static String deadline(
    DateTime instant, {
    DateTime? now,
    DigitStyle? style,
  }) {
    final localInstant = instant.toLocal();
    final localNow = (now ?? DateTime.now()).toLocal();

    // Jalali and Gregorian days both begin at local midnight, so the calendar
    // day delta is the same either way — no need to convert to compute it.
    final todayMidnight =
        DateTime(localNow.year, localNow.month, localNow.day);
    final targetMidnight =
        DateTime(localInstant.year, localInstant.month, localInstant.day);

    final dayDelta = targetMidnight.difference(todayMidnight).inDays;

    final clock = time(instant, style: style);

    return switch (dayDelta) {
      0 => 'امروز $clock',
      1 => 'فردا $clock',
      -1 => 'دیروز $clock',
      _ => '${date(instant, style: style)} $clock',
    };
  }

  /// Staleness label for cached data: `"آخرین به‌روزرسانی: ۲ دقیقه پیش"`.
  static String lastUpdated(
    DateTime instant, {
    DateTime? now,
    DigitStyle? style,
  }) {
    final reference = (now ?? DateTime.now()).toUtc();
    final age = reference.difference(instant.toUtc());

    return 'آخرین به‌روزرسانی: '
        '${NumberDisplay.apply(age.asPersianAgo, style: style)}';
  }

  /// Countdown chip text: `"۳ ساعت مانده"` / `"مهلت گذشته"`.
  static String countdown(
    DateTime deadlineAt, {
    DateTime? now,
    DigitStyle? style,
  }) {
    final reference = (now ?? DateTime.now()).toUtc();

    return NumberDisplay.apply(
      deadlineAt.toUtc().remainingFrom(reference).asPersianCountdown,
      style: style,
    );
  }

  static String _two(int value) => value.toString().padLeft(2, '0');
}
