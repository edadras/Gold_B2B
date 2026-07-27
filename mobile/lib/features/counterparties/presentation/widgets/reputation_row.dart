import 'package:flutter/material.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/counterparties/data/models/reputation.dart';

/// "۱۲,۸۳۰ معامله · تسویه ۹۹.۸٪ · اختلاف ۰٪" plus any warning.
///
/// The exact line from docs/07-mobile-flutter/02-screens.md §2.6. It appears
/// under every quote and every OTC counterparty, because the whole premise of
/// a B2B gold platform is that who you deal with is part of the price.
class ReputationRow extends StatelessWidget {
  const ReputationRow({required this.reputation, this.dense = true, super.key});

  final ReputationSummary reputation;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final parts = <String>[
      '${MoneyFormatter.integer(reputation.totalTrades)} معامله',
      if (reputation.onTimeSettlementRateBps != null)
        'تسویه ${PercentFormatter.fromBps(reputation.onTimeSettlementRateBps!)}'
      else
        'بدون سابقه تسویه',
      if (reputation.disputeRateBps != null)
        'اختلاف ${PercentFormatter.fromBps(reputation.disputeRateBps!)}',
      if (reputation.isNewMember && reputation.memberSinceDays != null)
        'عضو جدید (${MoneyFormatter.integer(reputation.memberSinceDays!)} روز)',
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text(
          parts.join(' · '),
          style: dense ? AppTypography.caption : AppTypography.bodyMuted,
        ),
        if (reputation.hasElevatedDisputeRate) ...<Widget>[
          const SizedBox(height: 4),
          Row(
            children: <Widget>[
              const Icon(
                Icons.warning_amber_rounded,
                size: 14,
                color: AppColors.warning,
              ),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  'نرخ اختلاف بالاتر از میانگین بازار',
                  style: AppTypography.caption
                      .copyWith(color: AppColors.warning),
                ),
              ),
            ],
          ),
        ],
        if (reputation.isNewMember && !reputation.hasElevatedDisputeRate) ...<Widget>[
          const SizedBox(height: 4),
          Row(
            children: <Widget>[
              const Icon(
                Icons.info_outline,
                size: 14,
                color: AppColors.info,
              ),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  'سابقه معاملاتی کمی دارد',
                  style:
                      AppTypography.caption.copyWith(color: AppColors.info),
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }
}

/// The F19 composite score, 0..1000, as a labelled bar.
class ReputationScoreBar extends StatelessWidget {
  const ReputationScoreBar({required this.score, super.key});

  final int score;

  @override
  Widget build(BuildContext context) {
    // ALLOW_DOUBLE_DISPLAY_ONLY — bar width only.
    final ratio = (score / 1000).clamp(0.0, 1.0);

    final color = score >= 800
        ? AppColors.positive
        : score >= 500
            ? AppColors.warning
            : AppColors.negative;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Row(
          children: <Widget>[
            Text('امتیاز اعتبار', style: AppTypography.label),
            const Spacer(),
            Text(
              '${MoneyFormatter.integer(score)} / '
              '${MoneyFormatter.integer(1000)}',
              style: AppTypography.numericSmall,
            ),
          ],
        ),
        const SizedBox(height: 6),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: ratio,
            minHeight: 8,
            backgroundColor: AppColors.divider,
            valueColor: AlwaysStoppedAnimation<Color>(color),
          ),
        ),
      ],
    );
  }
}
