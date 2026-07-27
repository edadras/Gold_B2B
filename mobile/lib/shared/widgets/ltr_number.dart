import 'package:flutter/widgets.dart';

/// Renders numeric text left-to-right inside the RTL layout.
///
/// docs/07-mobile-flutter/02-screens.md §2.10:
///
///   > In an RTL environment, numbers must be rendered with
///   > `textDirection: LTR`, otherwise the order of digits and separators is
///   > scrambled.
///
/// Concretely: the string "۱,۲۴۷.۳۲۰" laid out RTL puts the neutral separators
/// on the wrong side of the digit runs, so "۱,۲۴۷.۳۲۰" can render as
/// "۳۲۰.۱,۲۴۷". The digits themselves are strongly LTR in the bidi algorithm,
/// but commas and full stops are neutral and take their direction from the
/// surrounding paragraph.
///
/// Use this — or the widgets built on it, `MoneyDisplay` and `WeightDisplay` —
/// for EVERY number in the app. A bare `Text` containing a formatted figure is
/// a bug.
class LtrNumber extends StatelessWidget {
  const LtrNumber(
    this.text, {
    this.style,
    this.textAlign,
    this.maxLines = 1,
    this.overflow = TextOverflow.ellipsis,
    this.semanticsLabel,
    super.key,
  });

  final String text;
  final TextStyle? style;
  final TextAlign? textAlign;
  final int? maxLines;
  final TextOverflow overflow;

  /// Screen-reader text. Defaults to [text], which is usually right, but a
  /// bare figure benefits from a unit: "۲۵۰ گرم خالص" rather than "۲۵۰".
  final String? semanticsLabel;

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.ltr,
        child: Text(
          text,
          style: style,
          textAlign: textAlign,
          maxLines: maxLines,
          overflow: overflow,
          semanticsLabel: semanticsLabel,
        ),
      );
}

/// A row whose label reads RTL and whose value reads LTR.
///
/// This is the shape of nearly every financial row in the app: a Persian
/// caption on the right and a number on the left.
class LabeledNumberRow extends StatelessWidget {
  const LabeledNumberRow({
    required this.label,
    required this.value,
    this.labelStyle,
    this.emphasis = false,
    this.trailing,
    super.key,
  });

  final String label;

  /// Already-formatted numeric text.
  final String value;

  final TextStyle? labelStyle;

  /// Renders the value in the emphasised numeric style — for the bottom line
  /// of a breakdown.
  final bool emphasis;

  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final theme = DefaultTextStyle.of(context).style;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text(
              label,
              style: labelStyle ?? theme,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          const SizedBox(width: 12),
          LtrNumber(
            value,
            style: emphasis
                ? theme.copyWith(fontWeight: FontWeight.w700)
                : theme,
          ),
          if (trailing != null) ...<Widget>[
            const SizedBox(width: 6),
            trailing!,
          ],
        ],
      ),
    );
  }
}
