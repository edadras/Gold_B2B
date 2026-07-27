import 'package:flutter/material.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';

/// Material theme assembly.
///
/// Accessibility requirements from docs/07-mobile-flutter/02-screens.md §2.10
/// that are enforced here rather than left to each screen:
///
///   * minimum touch target 48x48 dp — [MaterialTapTargetSize.padded] plus
///     explicit minimum sizes on every button style;
///   * text scaling to 200% — nothing in the theme sets a fixed height on a
///     text container, and the type scale uses `height` multipliers rather
///     than hard line heights.
final class AppTheme {
  const AppTheme._();

  static ThemeData light() {
    final base = ThemeData.light(useMaterial3: true);

    return base.copyWith(
      colorScheme: base.colorScheme.copyWith(
        primary: AppColors.gold,
        onPrimary: Colors.white,
        secondary: AppColors.goldDark,
        error: AppColors.negative,
        surface: AppColors.surface,
        onSurface: AppColors.ink,
      ),
      scaffoldBackgroundColor: AppColors.surfaceAlt,
      textTheme: AppTypography.textTheme.apply(fontFamily: AppTypography.fontFamily),
      dividerColor: AppColors.divider,
      materialTapTargetSize: MaterialTapTargetSize.padded,
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.surface,
        foregroundColor: AppColors.ink,
        elevation: 0,
        centerTitle: true,
        titleTextStyle: AppTypography.titleMedium,
      ),
      cardTheme: CardTheme(
        color: AppColors.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: AppColors.divider),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(Ui.minTouchTarget),
          textStyle: AppTypography.titleMedium,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(Ui.minTouchTarget),
          textStyle: AppTypography.titleMedium,
          side: const BorderSide(color: AppColors.divider),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: const Size(Ui.minTouchTarget, Ui.minTouchTarget),
          textStyle: AppTypography.titleMedium,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surface,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.divider),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.divider),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.gold, width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.negative),
        ),
        labelStyle: AppTypography.label,
        hintStyle: AppTypography.bodyMuted,
        errorStyle: AppTypography.caption.copyWith(color: AppColors.negative),
      ),
      chipTheme: base.chipTheme.copyWith(
        labelStyle: AppTypography.label,
        side: const BorderSide(color: AppColors.divider),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: AppColors.surface,
        selectedItemColor: AppColors.goldDark,
        unselectedItemColor: AppColors.inkMuted,
        type: BottomNavigationBarType.fixed,
        selectedLabelStyle: AppTypography.label,
        unselectedLabelStyle: AppTypography.label,
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: AppColors.surface,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        contentTextStyle: AppTypography.body.copyWith(color: Colors.white),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
        ),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.gold,
      ),
    );
  }
}
