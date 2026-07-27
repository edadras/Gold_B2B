import 'dart:ui' show FontFeature;

import 'package:flutter/material.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';

/// Type scale.
///
/// The one rule that is not negotiable: any text style used to render a NUMBER
/// in a column carries [FontFeature.tabularFigures]. Proportional digits make
/// "۷۸,۴۲۰,۰۰۰" and "۷۸,۴۸۰,۰۰۰" different widths, so a depth ladder or a
/// ledger column visibly wobbles and the eye can no longer compare magnitudes
/// by length. See [numeric] and [numericLarge].
final class AppTypography {
  const AppTypography._();

  static const String fontFamily = 'Vazirmatn';

  static const List<FontFeature> _tabular = <FontFeature>[
    FontFeature.tabularFigures(),
  ];

  static const TextStyle displayLarge = TextStyle(
    fontFamily: fontFamily,
    fontSize: 32,
    fontWeight: FontWeight.w700,
    height: 1.3,
    color: AppColors.ink,
  );

  static const TextStyle titleLarge = TextStyle(
    fontFamily: fontFamily,
    fontSize: 20,
    fontWeight: FontWeight.w700,
    height: 1.4,
    color: AppColors.ink,
  );

  static const TextStyle titleMedium = TextStyle(
    fontFamily: fontFamily,
    fontSize: 16,
    fontWeight: FontWeight.w500,
    height: 1.5,
    color: AppColors.ink,
  );

  static const TextStyle body = TextStyle(
    fontFamily: fontFamily,
    fontSize: 14,
    fontWeight: FontWeight.w400,
    height: 1.6,
    color: AppColors.ink,
  );

  static const TextStyle bodyMuted = TextStyle(
    fontFamily: fontFamily,
    fontSize: 14,
    fontWeight: FontWeight.w400,
    height: 1.6,
    color: AppColors.inkMuted,
  );

  static const TextStyle caption = TextStyle(
    fontFamily: fontFamily,
    fontSize: 12,
    fontWeight: FontWeight.w400,
    height: 1.5,
    color: AppColors.inkMuted,
  );

  static const TextStyle label = TextStyle(
    fontFamily: fontFamily,
    fontSize: 12,
    fontWeight: FontWeight.w500,
    height: 1.4,
    color: AppColors.inkMuted,
  );

  // --- numeric styles ------------------------------------------------------

  /// Default for any figure in a list, table or ladder.
  static const TextStyle numeric = TextStyle(
    fontFamily: fontFamily,
    fontSize: 14,
    fontWeight: FontWeight.w500,
    height: 1.4,
    color: AppColors.ink,
    fontFeatures: _tabular,
  );

  /// Headline figures: the balance card, the confirmation sheet total.
  static const TextStyle numericLarge = TextStyle(
    fontFamily: fontFamily,
    fontSize: 26,
    fontWeight: FontWeight.w700,
    height: 1.25,
    color: AppColors.ink,
    fontFeatures: _tabular,
  );

  /// Secondary figures: breakdown rows, sub-balances.
  static const TextStyle numericSmall = TextStyle(
    fontFamily: fontFamily,
    fontSize: 12,
    fontWeight: FontWeight.w500,
    height: 1.4,
    color: AppColors.inkMuted,
    fontFeatures: _tabular,
  );

  /// Monospaced-feel style for identifiers that users read digit by digit:
  /// order codes, settlement codes, IBANs, payment references.
  static const TextStyle code = TextStyle(
    fontFamily: fontFamily,
    fontSize: 13,
    fontWeight: FontWeight.w500,
    height: 1.5,
    letterSpacing: 0.5,
    color: AppColors.ink,
    fontFeatures: _tabular,
  );

  static TextTheme get textTheme => const TextTheme(
        displayLarge: displayLarge,
        titleLarge: titleLarge,
        titleMedium: titleMedium,
        bodyMedium: body,
        bodySmall: caption,
        labelMedium: label,
      );
}
