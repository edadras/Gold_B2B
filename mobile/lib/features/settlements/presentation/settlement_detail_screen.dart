import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/settlements/application/settlements_controller.dart';
import 'package:gold_b2b/features/settlements/data/models/settlement.dart';
import 'package:gold_b2b/shared/extensions/string_extensions.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/confirm_sheet.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// One settlement, with whatever action is currently the user's to take.
class SettlementDetailScreen extends ConsumerWidget {
  const SettlementDetailScreen({required this.settlementId, super.key});

  final int settlementId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final settlement = ref.watch(settlementDetailProvider(settlementId));
    final now = ref.watch(clockProvider).valueOrNull ?? DateTime.now();

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(title: const Text('جزئیات تسویه')),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            Expanded(
              child: AsyncValueView<Settlement>(
                value: settlement,
                onRetry: () =>
                    ref.invalidate(settlementDetailProvider(settlementId)),
                data: (value) => _Body(settlement: value, now: now),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.settlement, required this.now});

  final Settlement settlement;
  final DateTime now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final events = ref.watch(settlementEventsProvider(settlement.id));

    return ListView(
      padding: const EdgeInsets.all(16),
      children: <Widget>[
        SectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Text(settlement.settlementCode, style: AppTypography.code),
                  const Spacer(),
                  StatusBadge(
                    label: SettlementStatus.label(settlement.status),
                    tone: SettlementStatus.tone(settlement.status),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Text(settlement.summaryLine, style: AppTypography.titleMedium),
              const ThinDivider(),
              KeyValueRow(
                label: 'مبلغ',
                value: MoneyDisplay(settlement.amount),
              ),
              KeyValueRow(
                label: 'مقدار',
                value: WeightDisplay(settlement.fineWeight),
              ),
              if (settlement.pricePerGram != null)
                KeyValueRow(
                  label: 'قیمت هر گرم',
                  value: PriceDisplay(settlement.pricePerGram!, showUnit: true),
                ),
              if (settlement.deadlineAt != null)
                KeyValueRow(
                  label: 'مهلت',
                  value: Text(
                    JalaliFormatter.deadline(settlement.deadlineAt!, now: now),
                    style: AppTypography.numeric,
                  ),
                ),
              if (settlement.counterparty != null)
                KeyValueRow(
                  label: 'طرف مقابل',
                  value: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      TierBadge(settlement.counterparty!.verificationTier),
                      const SizedBox(width: 4),
                      Flexible(
                        child: Text(
                          settlement.counterparty!.displayName,
                          style: AppTypography.body,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
        if (settlement.destinationAccount != null) ...<Widget>[
          const SizedBox(height: 12),
          SectionCard(
            title: 'اطلاعات حساب مقصد',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                // Grouped and copyable: the user is about to retype this into
                // a banking app, and an IBAN typo sends twenty billion rial
                // to a stranger.
                CopyableCode(
                  settlement.destinationAccount!.iban.asIban,
                  label: 'شماره شبا',
                ),
                const SizedBox(height: 6),
                Text(
                  '${settlement.destinationAccount!.bankName} — '
                  '${settlement.destinationAccount!.holderName}',
                  style: AppTypography.bodyMuted,
                ),
              ],
            ),
          ),
        ],
        if (settlement.paymentReference != null) ...<Widget>[
          const SizedBox(height: 12),
          SectionCard(
            title: 'پرداخت اعلام‌شده',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                CopyableCode(
                  settlement.paymentReference!,
                  label: 'شماره پیگیری',
                ),
                if (settlement.paymentDeclaredAt != null) ...<Widget>[
                  const SizedBox(height: 6),
                  Text(
                    JalaliFormatter.dateTime(settlement.paymentDeclaredAt!),
                    style: AppTypography.caption,
                  ),
                ],
              ],
            ),
          ),
        ],
        const SizedBox(height: 12),
        _Actions(settlement: settlement),
        const SizedBox(height: 20),
        Text('تاریخچه', style: AppTypography.titleMedium),
        const SizedBox(height: 8),
        AsyncValueView<List<SettlementEvent>>(
          value: events,
          onRetry: () => ref.invalidate(settlementEventsProvider(settlement.id)),
          data: (items) => Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              for (final event in items)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    children: <Widget>[
                      const Icon(
                        Icons.circle,
                        size: 8,
                        color: AppColors.inkDisabled,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          '${SettlementStatus.label(event.fromStatus)} ← '
                          '${SettlementStatus.label(event.toStatus)}',
                          style: AppTypography.caption,
                        ),
                      ),
                      Text(
                        JalaliFormatter.dateTime(event.occurredAt),
                        style: AppTypography.caption,
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 32),
      ],
    );
  }
}

class _Actions extends ConsumerWidget {
  const _Actions({required this.settlement});

  final Settlement settlement;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!settlement.requiresMyAction) {
      return SectionCard(
        child: Row(
          children: <Widget>[
            const Icon(Icons.hourglass_empty,
                size: 18, color: AppColors.inkMuted),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'در حال حاضر اقدامی از سمت شما لازم نیست.',
                style: AppTypography.bodyMuted,
              ),
            ),
          ],
        ),
      );
    }

    return OfflineActionGuard(
      builder: (context, online) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          switch (settlement.action) {
            SettlementAction.declarePayment => FilledButton.icon(
                onPressed: online
                    ? () => context
                        .push('/settlements/${settlement.id}/declare-payment')
                    : null,
                icon: const Icon(Icons.payments_outlined),
                label: const Text('اعلام پرداخت'),
              ),
            SettlementAction.confirmPayment => FilledButton.icon(
                onPressed: online ? () => _confirmPayment(context, ref) : null,
                icon: const Icon(Icons.verified_outlined),
                label: const Text('تأیید دریافت وجه'),
              ),
            SettlementAction.confirmDelivery => FilledButton.icon(
                onPressed: online ? () => _confirmDelivery(context, ref) : null,
                icon: const Icon(Icons.inventory_2_outlined),
                label: const Text('تأیید تحویل طلا'),
              ),
            SettlementAction.none => const SizedBox.shrink(),
          },
          if (settlement.action == SettlementAction.confirmPayment) ...<Widget>[
            const SizedBox(height: 8),
            OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.negative,
              ),
              onPressed: online ? () => _dispute(context) : null,
              icon: const Icon(Icons.gavel_outlined),
              label: const Text('اعتراض'),
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _confirmPayment(BuildContext context, WidgetRef ref) async {
    final result = await confirmFinancialAction(
      context: context,
      ref: ref,
      title: 'تأیید دریافت وجه',
      amount: settlement.amount,
      confirmLabel: 'تأیید دریافت',
      warning: 'با تأیید دریافت، طلا به طرف مقابل منتقل می‌شود و این '
          'عملیات جز از طریق ثبت اختلاف قابل برگشت نیست. '
          'پیش از تأیید، واریز را در حساب بانکی خود ببینید.',
      lines: <ConfirmationLine>[
        ConfirmationLine(
          label: 'تسویه',
          value: Text(settlement.settlementCode, style: AppTypography.code),
        ),
        ConfirmationLine(
          label: 'طرف مقابل',
          value: Text(
            settlement.counterpartyName ?? '',
            style: AppTypography.body,
          ),
        ),
        if (settlement.paymentReference != null)
          ConfirmationLine(
            label: 'شماره پیگیری',
            value:
                Text(settlement.paymentReference!, style: AppTypography.code),
          ),
        ConfirmationLine(
          label: 'مقدار طلا',
          value: WeightDisplay(settlement.fineWeight),
        ),
        moneyLine('مبلغ', settlement.amount, emphasis: true, dividerAbove: true),
      ],
    );

    if (!result.confirmed || !context.mounted) {
      return;
    }

    final ok = await ref.read(settlementActionsProvider.notifier).confirmPayment(
          settlementId: settlement.id,
          totpCode: result.totpCode,
        );

    if (!context.mounted) {
      return;
    }

    ref.invalidate(settlementDetailProvider(settlement.id));

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(ok ? 'دریافت وجه تأیید شد.' : 'تأیید انجام نشد.'),
      ),
    );
  }

  Future<void> _confirmDelivery(BuildContext context, WidgetRef ref) async {
    final result = await confirmFinancialAction(
      context: context,
      ref: ref,
      title: 'تأیید تحویل طلا',
      amount: settlement.amount,
      confirmLabel: 'تأیید تحویل',
      warning: 'با تأیید تحویل، مالکیت طلا منتقل می‌شود.',
      lines: <ConfirmationLine>[
        ConfirmationLine(
          label: 'تسویه',
          value: Text(settlement.settlementCode, style: AppTypography.code),
        ),
        ConfirmationLine(
          label: 'مقدار',
          value: WeightDisplay(settlement.fineWeight),
        ),
      ],
    );

    if (!result.confirmed || !context.mounted) {
      return;
    }

    final ok =
        await ref.read(settlementActionsProvider.notifier).confirmDelivery(
              settlementId: settlement.id,
              totpCode: result.totpCode,
            );

    if (!context.mounted) {
      return;
    }

    ref.invalidate(settlementDetailProvider(settlement.id));

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(ok ? 'تحویل تأیید شد.' : 'تأیید انجام نشد.')),
    );
  }

  Future<void> _dispute(BuildContext context) async {
    // Disputes are read-and-notify only on mobile
    // (docs/07-mobile-flutter/01-architecture.md §1.1: "dispute handling is
    // done on the web; mobile only notifies and displays").
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('ثبت اختلاف'),
        content: const Text(
          'ثبت و پیگیری اختلاف در پنل وب انجام می‌شود. '
          'برای ثبت اعتراض روی این تسویه، از طریق مرورگر وارد پنل شوید.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: const Text('باشه'),
          ),
        ],
      ),
    );
  }
}
