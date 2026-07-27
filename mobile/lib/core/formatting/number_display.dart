import 'package:gold_b2b/shared/models/numeric_input.dart';

/// Whether numbers render with Persian or ASCII digits.
///
/// Persian digits are the default and what the screen mockups show. ASCII is
/// offered as an accessibility/preference escape hatch in settings, because
/// some traders read long rial figures faster in ASCII and because copying a
/// Persian-digit tracking number into a banking app does not work.
enum DigitStyle { persian, ascii }

/// The one place that decides how a formatted numeric string is finally
/// rendered.
///
/// Every formatter in this directory produces ASCII first — via
/// `NumericInput`, which is the same code the value objects use — and then
/// passes the result through [apply]. That ordering matters: digit conversion
/// is a presentation step applied to an already-correct string, never a step
/// in producing the number.
final class NumberDisplay {
  const NumberDisplay._();

  /// Mutable process-wide default, set once from the settings controller.
  ///
  /// A global rather than an inherited widget on purpose: formatters are
  /// called from `toString`-like getters on plain models that have no
  /// BuildContext, and threading a context through every one of them would be
  /// far worse than one setting that changes about once per install.
  static DigitStyle style = DigitStyle.persian;

  static String apply(String ascii, {DigitStyle? style}) =>
      (style ?? NumberDisplay.style) == DigitStyle.persian
          ? NumericInput.toPersianDigits(ascii)
          : ascii;

  /// Persian percent sign.
  static const String percentSign = '٪';
}
