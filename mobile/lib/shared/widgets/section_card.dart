import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/shared/extensions/datetime_extensions.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';

/// Standard bordered card used throughout the app.
class SectionCard extends StatelessWidget {
  const SectionCard({
    required this.child,
    this.title,
    this.trailing,
    this.padding = const EdgeInsets.all(16),
    this.onTap,
    this.borderColor,
    super.key,
  });

  final Widget child;
  final String? title;
  final Widget? trailing;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? borderColor;

  @override
  Widget build(BuildContext context) {
    final content = Padding(
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if (title != null || trailing != null) ...<Widget>[
            Row(
              children: <Widget>[
                if (title != null)
                  Expanded(
                    child: Text(title!, style: AppTypography.label),
                  )
                else
                  const Spacer(),
                if (trailing != null) trailing!,
              ],
            ),
            const SizedBox(height: 10),
          ],
          child,
        ],
      ),
    );

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: borderColor ?? AppColors.divider),
      ),
      clipBehavior: Clip.antiAlias,
      child: onTap == null
          ? content
          : InkWell(onTap: onTap, child: content),
    );
  }
}

/// Label on the right, value on the left, value never wraps.
class KeyValueRow extends StatelessWidget {
  const KeyValueRow({
    required this.label,
    required this.value,
    this.valueColor,
    this.emphasis = false,
    this.copyable = false,
    super.key,
  });

  final String label;

  /// A widget rather than a string, so callers pass `MoneyDisplay` and
  /// `WeightDisplay` and cannot accidentally render a raw figure.
  final Widget value;

  final Color? valueColor;
  final bool emphasis;

  /// Adds a copy button. Used for IBANs, payment references and lot codes —
  /// anything the user will retype into another app, where a typo costs money.
  final bool copyable;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 7),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Expanded(
              child: Text(
                label,
                style: emphasis
                    ? AppTypography.titleMedium
                    : AppTypography.bodyMuted,
              ),
            ),
            const SizedBox(width: 12),
            Flexible(child: value),
          ],
        ),
      );
}

/// A code the user will copy: order code, settlement code, IBAN, reference.
class CopyableCode extends StatelessWidget {
  const CopyableCode(this.value, {this.label, super.key});

  final String value;
  final String? label;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Flexible(
            child: LtrNumber(
              value,
              style: AppTypography.code,
              maxLines: 2,
              overflow: TextOverflow.visible,
            ),
          ),
          const SizedBox(width: 6),
          IconButton(
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: value));
              await HapticFeedback.selectionClick();
            },
            icon: const Icon(Icons.copy_rounded, size: 16),
            tooltip: 'کپی${label == null ? '' : ' $label'}',
            visualDensity: VisualDensity.compact,
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
          ),
        ],
      );
}

/// Deadline chip. Red inside the urgency window, grey otherwise, and always
/// carrying a clock icon so urgency is not conveyed by colour alone.
class CountdownChip extends StatelessWidget {
  const CountdownChip({
    required this.deadline,
    required this.now,
    this.urgentWithin = const Duration(hours: 4),
    super.key,
  });

  final DateTime deadline;
  final DateTime now;
  final Duration urgentWithin;

  @override
  Widget build(BuildContext context) {
    final overdue = deadline.isOverdueAt(now);
    final urgent = deadline.isUrgentAt(now, threshold: urgentWithin);

    final color = overdue
        ? AppColors.negative
        : urgent
            ? AppColors.warning
            : AppColors.inkMuted;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(
          overdue ? Icons.warning_amber_rounded : Icons.schedule,
          size: 14,
          color: color,
        ),
        const SizedBox(width: 4),
        Text(
          JalaliFormatter.countdown(deadline, now: now),
          style: AppTypography.label.copyWith(color: color),
        ),
      ],
    );
  }
}

/// Full-width horizontal rule matching the card border colour.
class ThinDivider extends StatelessWidget {
  const ThinDivider({this.vertical = 12, super.key});

  final double vertical;

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.symmetric(vertical: vertical),
        child: const Divider(height: 1, thickness: 1, color: AppColors.divider),
      );
}
