import 'package:gold_b2b/shared/models/numeric_input.dart';

/// Persian digit helpers.
///
/// docs/07-mobile-flutter/01-architecture.md §1.11: user input may arrive with
/// Persian OR Arabic-Indic digits depending on the keyboard, and must always
/// be normalised before parsing.
extension PersianNumbers on String {
  /// For DISPLAY. Converts ASCII digits to Persian digits.
  String toPersianDigits() => NumericInput.toPersianDigits(this);

  /// For INPUT. Converts Persian and Arabic-Indic digits to ASCII and
  /// normalises the Arabic decimal/thousands separators.
  ///
  /// Never parse a user-entered number without calling this first — or, much
  /// better, hand the raw string straight to `NumericInput.toScaledInt`, which
  /// does it for you.
  String toAsciiDigits() => NumericInput.normalizeDigits(this);

  /// True when the string contains at least one non-ASCII digit.
  bool get hasNonAsciiDigits => this != toAsciiDigits();
}

extension StringPresentation on String {
  /// Chunk an IBAN for legibility: `IR120170...` -> `IR12 0170 0000 ...`.
  ///
  /// The settlement screens show a destination IBAN that the user will copy
  /// into a banking app by hand, so grouping is a correctness feature, not a
  /// cosmetic one.
  String get asIban {
    final compact = replaceAll(RegExp(r'\s+'), '').toUpperCase();
    final buffer = StringBuffer();

    for (var i = 0; i < compact.length; i += 4) {
      if (i > 0) {
        buffer.write(' ');
      }
      buffer.write(
        compact.substring(i, i + 4 > compact.length ? compact.length : i + 4),
      );
    }

    return buffer.toString();
  }

  /// Truncate for a single-line label, appending an ellipsis.
  String ellipsize(int maxLength) =>
      length <= maxLength ? this : '${substring(0, maxLength - 1)}…';
}

extension NullableStringPresentation on String? {
  bool get isNullOrBlank {
    final value = this;

    return value == null || value.trim().isEmpty;
  }
}
