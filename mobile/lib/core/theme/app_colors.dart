import 'package:flutter/material.dart';

/// Semantic colour roles.
///
/// docs/07-mobile-flutter/02-screens.md §2.10 defines the meanings:
///
///   green  buy, increase, success, active
///   red    sell, decrease, error, critical
///   amber  warning, pending
///   blue   information, neutral
///   grey   disabled, stale data
///
/// It also carries a warning worth repeating here, because it is the kind of
/// thing that gets designed away and then costs somebody real money:
///
///   > In the Iranian gold market, green/red for buy/sell may not match user
///   > expectation. Test with real users before shipping.
///
/// That is why [buy] and [sell] are separate roles from [positive] and
/// [negative] even though they currently resolve to the same values. When user
/// testing says to swap the trade-side colours, exactly two constants change
/// and no price-movement indicator moves with them.
///
/// Colour is also never the ONLY carrier of meaning: every side is labelled,
/// every status has an icon, and every price move has an arrow glyph.
final class AppColors {
  const AppColors._();

  // --- brand -------------------------------------------------------------
  static const Color gold = Color(0xFFC9A227);
  static const Color goldDark = Color(0xFF8C6D1F);
  static const Color goldLight = Color(0xFFF2DE9B);

  // --- semantic ----------------------------------------------------------
  static const Color positive = Color(0xFF1B873F);
  static const Color positiveSurface = Color(0xFFE7F5EC);
  static const Color negative = Color(0xFFC62828);
  static const Color negativeSurface = Color(0xFFFDECEA);
  static const Color warning = Color(0xFFE8A700);
  static const Color warningSurface = Color(0xFFFFF6E0);
  static const Color info = Color(0xFF1565C0);
  static const Color infoSurface = Color(0xFFE8F1FB);

  /// Buy side. See the class comment before changing.
  static const Color buy = positive;
  static const Color buySurface = positiveSurface;

  /// Sell side. See the class comment before changing.
  static const Color sell = negative;
  static const Color sellSurface = negativeSurface;

  // --- neutral -----------------------------------------------------------
  static const Color ink = Color(0xFF1A1A1A);
  static const Color inkMuted = Color(0xFF5F6368);
  static const Color inkDisabled = Color(0xFF9AA0A6);
  static const Color divider = Color(0xFFE3E5E8);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color surfaceAlt = Color(0xFFF6F7F9);
  static const Color scrim = Color(0x66000000);

  /// Applied to any figure that is being served from cache. Paired with an
  /// explicit "last updated" label — greying a number alone is not enough of
  /// a signal that it may be wrong.
  static const Color stale = inkDisabled;

  // --- verification tiers -------------------------------------------------
  static const Color tierBronze = Color(0xFFA97142);
  static const Color tierSilver = Color(0xFF9AA5B1);
  static const Color tierGold = Color(0xFFC9A227);
  static const Color tierPlatinum = Color(0xFF6C7BA6);

  static Color forTier(String? tier) => switch (tier) {
        'PLATINUM' => tierPlatinum,
        'GOLD' => tierGold,
        'SILVER' => tierSilver,
        'BRONZE' => tierBronze,
        _ => inkDisabled,
      };

  /// Emoji badge used in the mockups alongside the tier colour, so tier is
  /// legible without relying on hue.
  static String tierBadge(String? tier) => switch (tier) {
        'PLATINUM' => '🏅',
        'GOLD' => '🥇',
        'SILVER' => '🥈',
        'BRONZE' => '🥉',
        _ => '•',
      };
}
