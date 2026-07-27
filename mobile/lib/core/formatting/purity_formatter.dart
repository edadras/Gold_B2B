import 'package:gold_b2b/core/formatting/number_display.dart';
import 'package:gold_b2b/shared/models/purity.dart';

/// Purity -> display string.
///
/// The storage scale is ten-thousandths but the market speaks in parts per
/// thousand, so 9950 is shown as "۹۹۵". Fractional purities keep their one
/// decimal ("۹۹۵.۵"); everything else drops it. That mapping lives on
/// [Purity.toPpt] and this class only decorates it.
final class PurityFormatter {
  const PurityFormatter._();

  static const String label = 'عیار';

  /// `"۹۹۵"` or `"۹۹۵.۵"`.
  static String ppt(Purity purity, {DigitStyle? style}) =>
      NumberDisplay.apply(purity.toPpt(), style: style);

  /// `"عیار ۹۹۵"`.
  static String withLabel(Purity purity, {DigitStyle? style}) =>
      '$label ${ppt(purity, style: style)}';

  /// `"عیار ۹۹۵ ✅ گواهی‌شده"` / `"عیار ۷۵۰ (بدون گواهی)"`.
  ///
  /// An uncertified purity is a DECLARED purity: the owner says so and nobody
  /// has checked. The screens must never render the two the same way, because
  /// a declared 750 lot cannot be traded on the market at all until it has
  /// been assayed (docs/07-mobile-flutter/02-screens.md §2.7).
  static String withCertification(
    Purity purity, {
    required bool isCertified,
    DigitStyle? style,
  }) =>
      isCertified
          ? '${withLabel(purity, style: style)} گواهی‌شده'
          : '${withLabel(purity, style: style)} (بدون گواهی)';

  static const String certifiedLabel = 'گواهی‌شده';
  static const String declaredLabel = 'اعلامی';
}
