/// Deadline arithmetic.
///
/// Settlement deadlines drive the most consequential screens in the app
/// ("۳ ساعت مانده" on the dashboard), so the arithmetic lives in one place and
/// is done entirely in UTC. All API timestamps are UTC (§1.1); converting to
/// local time happens only at the moment of formatting.
extension DeadlineMath on DateTime {
  /// Time from [now] until this instant. Negative once the deadline is past.
  Duration remainingFrom(DateTime now) => difference(now);

  bool isOverdueAt(DateTime now) => isBefore(now);

  /// True when the deadline is inside [threshold] but has not yet passed —
  /// the condition that turns the countdown chip red.
  bool isUrgentAt(DateTime now, {Duration threshold = const Duration(hours: 4)}) {
    final remaining = difference(now);

    return !remaining.isNegative && remaining <= threshold;
  }
}

extension DurationPresentation on Duration {
  /// Coarse Persian rendering of a countdown: "۳ ساعت مانده", "۲۵ دقیقه
  /// مانده", "۲ روز مانده", or "مهلت گذشته" once negative.
  ///
  /// Deliberately coarse. A second-by-second countdown on a settlement worth
  /// twenty billion rial invites panic clicking, and the server's deadline is
  /// authoritative anyway.
  String get asPersianCountdown {
    if (isNegative) {
      return 'مهلت گذشته';
    }

    if (inMinutes < 1) {
      return 'کمتر از یک دقیقه مانده';
    }

    if (inMinutes < 60) {
      return '$inMinutes دقیقه مانده';
    }

    if (inHours < 24) {
      return '$inHours ساعت مانده';
    }

    return '$inDays روز مانده';
  }

  /// "۲ دقیقه پیش" — used by the staleness label on cached balances.
  String get asPersianAgo {
    if (inSeconds < 30) {
      return 'هم‌اکنون';
    }

    if (inMinutes < 1) {
      return 'چند ثانیه پیش';
    }

    if (inMinutes < 60) {
      return '$inMinutes دقیقه پیش';
    }

    if (inHours < 24) {
      return '$inHours ساعت پیش';
    }

    return '$inDays روز پیش';
  }
}
