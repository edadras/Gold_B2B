import 'package:flutter/material.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';

/// Colours and glyphs for [StatusTone].
///
/// Each feature maps its own status strings onto a tone, so the badge does not
/// have to know about every state machine in the system — and, crucially, so
/// that an enum value the client has never heard of (which §1.11 explicitly
/// allows the server to add) renders as a neutral badge carrying the server's
/// own label, rather than crashing or vanishing.
extension StatusToneColors on StatusTone {
  Color get foreground => switch (this) {
        StatusTone.neutral => AppColors.inkMuted,
        StatusTone.positive => AppColors.positive,
        StatusTone.warning => AppColors.warning,
        StatusTone.negative => AppColors.negative,
        StatusTone.info => AppColors.info,
      };

  Color get background => switch (this) {
        StatusTone.neutral => AppColors.surfaceAlt,
        StatusTone.positive => AppColors.positiveSurface,
        StatusTone.warning => AppColors.warningSurface,
        StatusTone.negative => AppColors.negativeSurface,
        StatusTone.info => AppColors.infoSurface,
      };

  /// A glyph so the tone survives greyscale and colour blindness.
  IconData get icon => switch (this) {
        StatusTone.neutral => Icons.remove_circle_outline,
        StatusTone.positive => Icons.check_circle_outline,
        StatusTone.warning => Icons.schedule,
        StatusTone.negative => Icons.error_outline,
        StatusTone.info => Icons.info_outline,
      };
}

class StatusBadge extends StatelessWidget {
  const StatusBadge({
    required this.label,
    this.tone = StatusTone.neutral,
    this.showIcon = true,
    this.dense = false,
    super.key,
  });

  /// Persian label. Prefer the server's own label from `GET /meta/enums` over
  /// a hard-coded translation, so a newly added status still reads correctly.
  final String label;

  final StatusTone tone;
  final bool showIcon;
  final bool dense;

  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.symmetric(
          horizontal: dense ? 6 : 10,
          vertical: dense ? 2 : 4,
        ),
        decoration: BoxDecoration(
          color: tone.background,
          borderRadius: BorderRadius.circular(999),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (showIcon) ...<Widget>[
              Icon(tone.icon, size: dense ? 12 : 14, color: tone.foreground),
              const SizedBox(width: 4),
            ],
            Text(
              label,
              style: (dense ? AppTypography.caption : AppTypography.label)
                  .copyWith(color: tone.foreground),
            ),
          ],
        ),
      );
}

/// Verification tier of a counterparty: 🥇 GOLD, 🥈 SILVER, and so on.
///
/// Shown next to every counterparty name in the app, because who you are
/// settling with is a risk decision.
class TierBadge extends StatelessWidget {
  const TierBadge(this.tier, {this.showLabel = false, super.key});

  final String? tier;
  final bool showLabel;

  static const Map<String, String> _labels = <String, String>{
    'PLATINUM': 'پلاتین',
    'GOLD': 'طلایی',
    'SILVER': 'نقره‌ای',
    'BRONZE': 'برنزی',
  };

  @override
  Widget build(BuildContext context) {
    final badge = AppColors.tierBadge(tier);
    final label = _labels[tier];

    return Semantics(
      label: label == null ? 'سطح تأیید نامشخص' : 'سطح تأیید $label',
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(badge, style: const TextStyle(fontSize: 14)),
          if (showLabel && label != null) ...<Widget>[
            const SizedBox(width: 4),
            Text(
              label,
              style: AppTypography.label
                  .copyWith(color: AppColors.forTier(tier)),
            ),
          ],
        ],
      ),
    );
  }
}

/// BUY / SELL pill.
///
/// Both the word and the colour are present. See `AppColors` for why the
/// side colours are separate roles from the up/down colours.
class SideBadge extends StatelessWidget {
  const SideBadge(this.side, {this.dense = false, super.key});

  /// `"BUY"` or `"SELL"` as the API sends it.
  final String side;

  final bool dense;

  @override
  Widget build(BuildContext context) {
    final isBuy = side.toUpperCase() == 'BUY';

    return StatusBadge(
      label: isBuy ? 'خرید' : 'فروش',
      tone: isBuy ? StatusTone.positive : StatusTone.negative,
      showIcon: false,
      dense: dense,
    );
  }
}
