import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/settlements/application/settlements_controller.dart';
import 'package:gold_b2b/features/settlements/data/models/settlement.dart';
import 'package:gold_b2b/shared/extensions/string_extensions.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/confirm_sheet.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/error_view.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';

/// docs/07-mobile-flutter/02-screens.md §2.5 "اعلام پرداخت".
///
/// The amount is NOT editable. The settlement says what is owed, and letting
/// the user type a different figure would either be a mistake or a dispute
/// waiting to happen. If the amount transferred differs, that is a dispute,
/// not a declaration.
class DeclarePaymentScreen extends ConsumerStatefulWidget {
  const DeclarePaymentScreen({required this.settlementId, super.key});

  final int settlementId;

  @override
  ConsumerState<DeclarePaymentScreen> createState() =>
      _DeclarePaymentScreenState();
}

class _DeclarePaymentScreenState extends ConsumerState<DeclarePaymentScreen> {
  final TextEditingController _reference = TextEditingController();
  final TextEditingController _note = TextEditingController();

  DateTime _paidAt = DateTime.now();
  int? _receiptDocumentId;
  String? _referenceError;

  @override
  void dispose() {
    _reference.dispose();
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final settlement = ref.watch(settlementDetailProvider(widget.settlementId));
    final action = ref.watch(settlementActionsProvider);

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(title: const Text('اعلام پرداخت')),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            Expanded(
              child: AsyncValueView<Settlement>(
                value: settlement,
                onRetry: () => ref
                    .invalidate(settlementDetailProvider(widget.settlementId)),
                data: (value) => _form(value, action),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _form(Settlement settlement, AsyncValue<void> action) => ListView(
        padding: const EdgeInsets.all(16),
        children: <Widget>[
          SectionCard(
            title: 'مبلغ قابل پرداخت',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                MoneyDisplay(settlement.amount, emphasis: MoneyEmphasis.large),
                if (settlement.destinationAccount != null) ...<Widget>[
                  const ThinDivider(),
                  Text('به حساب', style: AppTypography.label),
                  const SizedBox(height: 6),
                  CopyableCode(
                    settlement.destinationAccount!.iban.asIban,
                    label: 'شماره شبا',
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${settlement.destinationAccount!.holderName} — '
                    '${settlement.destinationAccount!.bankName}',
                    style: AppTypography.bodyMuted,
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 20),
          Text('شماره پیگیری *', style: AppTypography.label),
          const SizedBox(height: 8),
          TextField(
            controller: _reference,
            keyboardType: TextInputType.text,
            textDirection: TextDirection.ltr,
            textAlign: TextAlign.left,
            style: AppTypography.code,
            decoration: InputDecoration(errorText: _referenceError),
            onChanged: (_) => setState(() => _referenceError = null),
          ),
          const SizedBox(height: 20),
          Text('زمان پرداخت', style: AppTypography.label),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _pickDateTime,
            icon: const Icon(Icons.event),
            label: Text(JalaliFormatter.dateTime(_paidAt)),
          ),
          const SizedBox(height: 20),
          Text('رسید (اختیاری اما توصیه‌شده)', style: AppTypography.label),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _attachReceipt,
            icon: const Icon(Icons.attach_file),
            label: Text(
              _receiptDocumentId == null
                  ? 'آپلود تصویر رسید'
                  : 'رسید پیوست شد',
            ),
          ),
          const SizedBox(height: 20),
          Text('توضیح (اختیاری)', style: AppTypography.label),
          const SizedBox(height: 8),
          TextField(
            controller: _note,
            maxLines: 2,
            decoration: const InputDecoration(
              hintText: 'مثلاً: واریز پایا از حساب ملت',
            ),
          ),
          const SizedBox(height: 20),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppColors.warningSurface,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                const Icon(Icons.warning_amber_rounded,
                    size: 18, color: AppColors.warning),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'با اعلام پرداخت، مسئولیت صحت اطلاعات بر عهده شماست. '
                    'اعلام نادرست منجر به ثبت اختلاف و کاهش اعتبار می‌شود.',
                    style: AppTypography.caption
                        .copyWith(color: AppColors.warning),
                  ),
                ),
              ],
            ),
          ),
          if (action.hasError) ...<Widget>[
            const SizedBox(height: 16),
            InlineError(
              failure: asAppFailure(action.error!),
              // Retrying reuses the idempotency key held by
              // SettlementActionsController, so a payment cannot be declared
              // twice.
              onRetry: () => _submit(settlement),
            ),
          ],
          const SizedBox(height: 24),
          OfflineActionGuard(
            builder: (context, online) => FilledButton.icon(
              onPressed: (online && !action.isLoading)
                  ? () => _submit(settlement)
                  : null,
              icon: const Icon(Icons.lock_outline, size: 18),
              label: const Text('تأیید و اعلام پرداخت'),
            ),
          ),
          const SizedBox(height: 32),
        ],
      );

  Future<void> _pickDateTime() async {
    // TODO(mobile-ux): this is the Gregorian Material picker. Persian users
    // expect a Jalali picker, and shamsi_date does not ship a widget. Either
    // add a Jalali picker package or build one; until then the SELECTED value
    // is displayed back in Jalali by JalaliFormatter, so the user can at
    // least verify what they picked.
    final date = await showDatePicker(
      context: context,
      initialDate: _paidAt,
      firstDate: DateTime.now().subtract(const Duration(days: 30)),
      lastDate: DateTime.now().add(const Duration(days: 1)),
    );

    if (date == null || !mounted) {
      return;
    }

    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(_paidAt),
    );

    if (!mounted) {
      return;
    }

    setState(() {
      _paidAt = DateTime(
        date.year,
        date.month,
        date.day,
        time?.hour ?? _paidAt.hour,
        time?.minute ?? _paidAt.minute,
      );
    });
  }

  Future<void> _attachReceipt() async {
    // TODO(mobile-platform): receipt capture needs an image source. The
    // dependency list for this project does not include image_picker, and
    // mobile_scanner is a QR reader, not a camera-roll picker. A developer
    // must add image_picker (or file_selector), obtain a file path, and call
    // `SettlementRepository.uploadReceipt`, which is already implemented and
    // returns the document id to assign to `_receiptDocumentId`.
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('آپلود رسید در این نسخه فعال نیست.'),
      ),
    );
  }

  Future<void> _submit(Settlement settlement) async {
    final reference =
        NumericInput.normalizeDigits(_reference.text).trim();

    if (reference.isEmpty) {
      setState(() => _referenceError = 'شماره پیگیری را وارد کنید.');

      return;
    }

    final result = await confirmFinancialAction(
      context: context,
      ref: ref,
      title: 'اعلام پرداخت',
      amount: settlement.amount,
      confirmLabel: 'اعلام پرداخت',
      warning: 'با اعلام پرداخت، مسئولیت صحت این اطلاعات بر عهده شماست.',
      lines: <ConfirmationLine>[
        ConfirmationLine(
          label: 'تسویه',
          value: Text(settlement.settlementCode, style: AppTypography.code),
        ),
        ConfirmationLine(
          label: 'شماره پیگیری',
          value: Text(reference, style: AppTypography.code),
        ),
        ConfirmationLine(
          label: 'زمان پرداخت',
          value: Text(
            JalaliFormatter.dateTime(_paidAt),
            style: AppTypography.numeric,
          ),
        ),
        moneyLine('مبلغ', settlement.amount, emphasis: true, dividerAbove: true),
      ],
    );

    if (!result.confirmed || !mounted) {
      return;
    }

    final ok =
        await ref.read(settlementActionsProvider.notifier).declarePayment(
              settlementId: settlement.id,
              paymentReference: reference,
              amount: settlement.amount,
              paidAt: _paidAt,
              receiptDocumentId: _receiptDocumentId,
              note: _note.text.trim(),
            );

    if (!mounted) {
      return;
    }

    if (ok) {
      ref.invalidate(settlementDetailProvider(settlement.id));
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('پرداخت اعلام شد.')),
      );
      Navigator.of(context).maybePop();
    }
  }
}
