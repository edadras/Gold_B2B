import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/formatting/purity_formatter.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/lots/application/lots_controller.dart';
import 'package:gold_b2b/features/lots/data/lot_repository.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// Result of a QR scan (docs/07-mobile-flutter/02-screens.md §2.8).
///
/// Deliberately NOT wrapped in `SecureScreen`. This is the one financial-ish
/// screen a user is expected to show to somebody else — "here, look, the bar
/// is genuine" — and it contains no balances, no ownership and no
/// counterparty identity. Blocking the screenshot would make it less useful
/// without protecting anything.
class VerifyResultScreen extends ConsumerWidget {
  const VerifyResultScreen({required this.qrToken, super.key});

  final String qrToken;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final verification = ref.watch(lotVerificationProvider(qrToken));

    return Scaffold(
      appBar: AppBar(title: const Text('تأیید اصالت')),
      body: AsyncValueView<LotVerification>(
        value: verification,
        onRetry: () => ref.invalidate(lotVerificationProvider(qrToken)),
        data: (result) => ListView(
          padding: const EdgeInsets.all(16),
          children: <Widget>[
            Row(
              children: <Widget>[
                Icon(
                  result.isValid ? Icons.verified : Icons.dangerous,
                  size: 28,
                  color: result.isValid
                      ? AppColors.positive
                      : AppColors.negative,
                ),
                const SizedBox(width: 8),
                Text(
                  result.isValid ? 'تأیید اصالت' : 'تأیید نشد',
                  style: AppTypography.titleLarge.copyWith(
                    color: result.isValid
                        ? AppColors.positive
                        : AppColors.negative,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            if (!result.isValid)
              SectionCard(
                borderColor: AppColors.negative,
                child: Text(
                  result.message ??
                      'این کد در سامانه ثبت نشده است. با احتیاط اقدام کنید '
                          'و از فروشنده توضیح بخواهید.',
                  style:
                      AppTypography.body.copyWith(color: AppColors.negative),
                ),
              )
            else
              SectionCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Text(result.lotCode, style: AppTypography.titleMedium),
                    const ThinDivider(),
                    KeyValueRow(
                      label: 'وزن',
                      value: GrossWeightDisplay(result.grossWeight),
                    ),
                    KeyValueRow(
                      label: 'عیار',
                      value: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[
                          Text(
                            PurityFormatter.ppt(result.purity),
                            style: AppTypography.numeric,
                          ),
                          const SizedBox(width: 4),
                          Icon(
                            result.isAssayCertified
                                ? Icons.verified
                                : Icons.help_outline,
                            size: 16,
                            color: result.isAssayCertified
                                ? AppColors.positive
                                : AppColors.warning,
                          ),
                        ],
                      ),
                    ),
                    KeyValueRow(
                      label: 'خالص',
                      value: WeightDisplay(result.fineWeight),
                    ),
                    if (result.assayLabName != null)
                      KeyValueRow(
                        label: 'آزمایشگاه',
                        value: Text(
                          <String?>[
                            result.assayLabName,
                            result.assayedAt == null
                                ? null
                                : JalaliFormatter.date(result.assayedAt!),
                          ].whereType<String>().join(' · '),
                          style: AppTypography.body,
                        ),
                      ),
                    if (result.custodyLabel != null)
                      KeyValueRow(
                        label: 'وضعیت',
                        value: Text(
                          result.custodyLabel!,
                          style: AppTypography.body,
                        ),
                      ),
                  ],
                ),
              ),
            const SizedBox(height: 16),
            if (result.isValid && !result.isMine)
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppColors.infoSurface,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    const Icon(Icons.info_outline,
                        size: 18, color: AppColors.info),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'این قطعه متعلق به شما نیست. '
                        'اطلاعات مالک نمایش داده نمی‌شود.',
                        style: AppTypography.caption
                            .copyWith(color: AppColors.info),
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 24),
            OutlinedButton.icon(
              onPressed: () => context.pushReplacement('/lots/scan'),
              icon: const Icon(Icons.qr_code_scanner),
              label: const Text('اسکن دوباره'),
            ),
          ],
        ),
      ),
    );
  }
}
