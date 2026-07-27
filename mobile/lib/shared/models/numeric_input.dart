/// Decimal string <-> scaled integer conversion that never touches `double`.
///
/// Dart port of `App\Modules\Shared\ValueObjects\NumericInput`. This is the
/// ONLY sanctioned bridge between text a user typed and the integers the rest
/// of the app computes with. Nothing else in this codebase may call
/// `double.parse`, `num.parse` or `NumberFormat.parse` on a money or weight
/// field.
///
/// Deliberate divergence from the PHP version: [toScaledInt] additionally
/// strips bidirectional control characters (ZWNJ, LRM, RLM, NBSP) that Persian
/// and Arabic soft keyboards inject around digits. This only makes the client
/// *more* permissive — every string PHP accepts maps to the same integer here,
/// and the client sends integers over the wire, so the two implementations can
/// never disagree about a value.
library;

final class NumericInput {
  const NumericInput._();

  static const List<String> _persianDigits = <String>[
    '۰', '۱', '۲', '۳', '۴',
    '۵', '۶', '۷', '۸', '۹',
  ];

  static const List<String> _arabicDigits = <String>[
    '٠', '١', '٢', '٣', '٤',
    '٥', '٦', '٧', '٨', '٩',
  ];

  static const List<String> _asciiDigits = <String>[
    '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
  ];

  /// U+066B ARABIC DECIMAL SEPARATOR.
  static const String _arabicDecimalSeparator = '٫';

  /// U+060C ARABIC COMMA.
  static const String _arabicComma = '،';

  /// U+066C ARABIC THOUSANDS SEPARATOR.
  static const String _arabicThousandsSeparator = '٬';

  static final RegExp _numericPattern = RegExp(r'^\d*(?:\.\d*)?$');
  static final RegExp _groupPattern = RegExp(r'(\d)(?=(\d{3})+$)');
  static final RegExp _leadingZeros = RegExp(r'^0+');

  /// Characters that carry no numeric meaning but routinely arrive with
  /// pasted or IME-composed Persian input.
  static const List<String> _invisibleCharacters = <String>[
    '\u200C', // ZERO WIDTH NON-JOINER
    '\u200E', // LEFT-TO-RIGHT MARK
    '\u200F', // RIGHT-TO-LEFT MARK
    '\u202A', // LEFT-TO-RIGHT EMBEDDING
    '\u202B', // RIGHT-TO-LEFT EMBEDDING
    '\u202C', // POP DIRECTIONAL FORMATTING
    '\u00A0', // NO-BREAK SPACE
  ];

  /// Normalise Persian/Arabic digits and separators to plain ASCII.
  static String normalizeDigits(String input) {
    var output = input;

    for (var i = 0; i < 10; i++) {
      output = output
          .replaceAll(_persianDigits[i], _asciiDigits[i])
          .replaceAll(_arabicDigits[i], _asciiDigits[i]);
    }

    return output
        .replaceAll(_arabicDecimalSeparator, '.')
        .replaceAll(_arabicComma, ',')
        .replaceAll(_arabicThousandsSeparator, ',');
  }

  /// `"1,247.32"` with scale 3 -> `1247320`.
  ///
  /// Decimal places beyond [scale] are TRUNCATED, never rounded up, so a user
  /// cannot conjure weight they do not have by typing more digits.
  ///
  /// Throws [FormatException] for malformed or out-of-range input.
  static int toScaledInt(String input, int scale) {
    if (scale < 0) {
      throw ArgumentError.value(scale, 'scale', 'Scale cannot be negative');
    }

    var normalized = normalizeDigits(input);
    for (final invisible in _invisibleCharacters) {
      normalized = normalized.replaceAll(invisible, '');
    }
    normalized = normalized
        .trim()
        .replaceAll(',', '')
        .replaceAll(' ', '')
        .replaceAll('_', '');

    if (normalized.isEmpty) {
      throw const FormatException('Numeric input is empty');
    }

    var negative = false;
    if (normalized.startsWith('-')) {
      negative = true;
      normalized = normalized.substring(1);
    } else if (normalized.startsWith('+')) {
      normalized = normalized.substring(1);
    }

    if (normalized.isEmpty ||
        normalized == '.' ||
        !_numericPattern.hasMatch(normalized)) {
      throw FormatException('Invalid numeric input: $input');
    }

    final dot = normalized.indexOf('.');
    final wholeRaw = dot == -1 ? normalized : normalized.substring(0, dot);
    final fractionRaw = dot == -1 ? '' : normalized.substring(dot + 1);

    final whole = wholeRaw.isEmpty ? '0' : wholeRaw;
    final fraction = fractionRaw.padRight(scale, '0').substring(0, scale);

    final combined = (whole + fraction).replaceFirst(_leadingZeros, '');
    if (combined.isEmpty) {
      return 0;
    }

    // 9223372036854775807 has 19 digits; anything longer cannot fit, and
    // int.tryParse's out-of-range behaviour differs between the VM and web.
    if (combined.length > 19) {
      throw FormatException('Numeric input out of range: $input');
    }

    final value = int.tryParse(combined);
    if (value == null) {
      throw FormatException('Numeric input out of range: $input');
    }

    return negative ? -value : value;
  }

  /// Non-throwing form for live form validation, where a half-typed value like
  /// `"250."` is expected rather than exceptional.
  static int? tryToScaledInt(String input, int scale) {
    try {
      return toScaledInt(input, scale);
    } on FormatException {
      return null;
    } on ArgumentError {
      return null;
    }
  }

  /// `1247320` with scale 3 -> `"1247.320"`.
  static String fromScaledInt(int value, int scale) {
    final negative = value < 0;
    final sign = negative ? '-' : '';
    var digits = value.abs().toString();

    if (scale == 0) {
      return '$sign$digits';
    }

    digits = digits.padLeft(scale + 1, '0');
    final whole = digits.substring(0, digits.length - scale);
    final fraction = digits.substring(digits.length - scale);

    return '$sign$whole.$fraction';
  }

  /// Insert thousands separators into the integer part of a decimal string.
  ///
  /// `"1247.320"` -> `"1,247.320"`.
  static String group(String decimal) {
    final negative = decimal.startsWith('-');
    final body = negative ? decimal.substring(1) : decimal;

    final dot = body.indexOf('.');
    final whole = dot == -1 ? body : body.substring(0, dot);
    final fraction = dot == -1 ? null : body.substring(dot + 1);

    final grouped = whole.replaceAllMapped(
      _groupPattern,
      (match) => '${match[1]},',
    );

    return '${negative ? '-' : ''}$grouped${fraction == null ? '' : '.$fraction'}';
  }

  /// Convert ASCII digits to Persian digits for display.
  ///
  /// Display only — never feed the result back into [toScaledInt] without
  /// going through [normalizeDigits] first (which [toScaledInt] does).
  static String toPersianDigits(String input) {
    var output = input;
    for (var i = 0; i < 10; i++) {
      output = output.replaceAll(_asciiDigits[i], _persianDigits[i]);
    }

    return output;
  }

  /// Convert Persian/Arabic digits back to ASCII. Alias of [normalizeDigits],
  /// named for the call sites that read as "sanitise this user input".
  static String toAsciiDigits(String input) => normalizeDigits(input);
}
