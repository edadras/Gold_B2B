import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/market/application/market_providers.dart';
import 'package:gold_b2b/features/market/data/models/instrument.dart';
import 'package:gold_b2b/features/rfq/application/rfq_controller.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/error_view.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';

/// Create an RFQ.
///
/// Not put behind the biometric confirmation sheet: an RFQ is a request for
/// prices, not a commitment to trade. The binding step is accepting a quote,
/// and that one is fully confirmed.
class RfqCreateScreen extends ConsumerStatefulWidget {
  const RfqCreateScreen({super.key});

  @override
  ConsumerState<RfqCreateScreen> createState() => _RfqCreateScreenState();
}

class _RfqCreateScreenState extends ConsumerState<RfqCreateScreen> {
  final TextEditingController _quantity = TextEditingController();
  final TextEditingController _purity = TextEditingController(text: '995');

  String _side = 'BUY';
  String _settlementType = 'T0';
  String _deliveryType = 'VAULT_TRANSFER';
  String _visibility = 'ALL';
  int _expiresInMinutes = 15;
  bool _allowPartial = true;
  String? _error;

  @override
  void dispose() {
    _quantity.dispose();
    _purity.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final instruments = ref.watch(instrumentsProvider).valueOrNull;
    final selected = ref.watch(selectedInstrumentProvider);
    final action = ref.watch(rfqActionsProvider);

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(title: const Text('درخواست قیمت جدید')),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: <Widget>[
                  Text('ابزار', style: AppTypography.label),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: selected,
                    items: <DropdownMenuItem<String>>[
                      for (final instrument
                          in instruments ?? const <Instrument>[])
                        DropdownMenuItem<String>(
                          value: instrument.code,
                          child: Text(instrument.code),
                        ),
                    ],
                    onChanged: (value) {
                      if (value != null) {
                        ref.read(selectedInstrumentProvider.notifier).state =
                            value;
                      }
                    },
                  ),
                  const SizedBox(height: 20),
                  Text('سمت', style: AppTypography.label),
                  const SizedBox(height: 8),
                  SegmentedButton<String>(
                    segments: const <ButtonSegment<String>>[
                      ButtonSegment<String>(value: 'BUY', label: Text('خرید')),
                      ButtonSegment<String>(value: 'SELL', label: Text('فروش')),
                    ],
                    selected: <String>{_side},
                    onSelectionChanged: (values) =>
                        setState(() => _side = values.first),
                  ),
                  const SizedBox(height: 20),
                  Text('مقدار (گرم خالص)', style: AppTypography.label),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _quantity,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    textDirection: TextDirection.ltr,
                    textAlign: TextAlign.left,
                    style: AppTypography.numeric,
                    onChanged: (_) => setState(() => _error = null),
                  ),
                  const SizedBox(height: 20),
                  Text('حداقل عیار', style: AppTypography.label),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _purity,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    textDirection: TextDirection.ltr,
                    textAlign: TextAlign.left,
                    style: AppTypography.numeric,
                  ),
                  const SizedBox(height: 20),
                  Text('نوع تسویه', style: AppTypography.label),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: _settlementType,
                    items: const <DropdownMenuItem<String>>[
                      DropdownMenuItem<String>(
                        value: 'T0',
                        child: Text('همان روز (T0)'),
                      ),
                      DropdownMenuItem<String>(
                        value: 'T1',
                        child: Text('روز بعد (T1)'),
                      ),
                      DropdownMenuItem<String>(
                        value: 'T2',
                        child: Text('دو روز بعد (T2)'),
                      ),
                    ],
                    onChanged: (value) =>
                        setState(() => _settlementType = value ?? 'T0'),
                  ),
                  const SizedBox(height: 20),
                  Text('نوع تحویل', style: AppTypography.label),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: _deliveryType,
                    items: const <DropdownMenuItem<String>>[
                      DropdownMenuItem<String>(
                        value: 'VAULT_TRANSFER',
                        child: Text('انتقال در خزانه'),
                      ),
                      DropdownMenuItem<String>(
                        value: 'PHYSICAL',
                        child: Text('تحویل فیزیکی'),
                      ),
                    ],
                    onChanged: (value) => setState(
                      () => _deliveryType = value ?? 'VAULT_TRANSFER',
                    ),
                  ),
                  const SizedBox(height: 20),
                  Text('گیرندگان', style: AppTypography.label),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: _visibility,
                    items: const <DropdownMenuItem<String>>[
                      DropdownMenuItem<String>(
                        value: 'ALL',
                        child: Text('همه اعضای واجد شرایط'),
                      ),
                      DropdownMenuItem<String>(
                        value: 'SELECTED',
                        child: Text('فقط طرف‌حساب‌های من'),
                      ),
                    ],
                    onChanged: (value) =>
                        setState(() => _visibility = value ?? 'ALL'),
                  ),
                  if (_visibility == 'SELECTED') ...<Widget>[
                    const SizedBox(height: 8),
                    SectionCard(
                      child: Text(
                        // TODO(mobile-ux): selecting specific recipients needs
                        // the member picker built on `GET /members/search`.
                        // Until it exists, choosing "فقط طرف‌حساب‌های من" sends
                        // no explicit recipient list and the server applies its
                        // own default for SELECTED visibility.
                        'انتخاب دستی گیرندگان در این نسخه در دسترس نیست؛ '
                        'درخواست برای طرف‌حساب‌های ثبت‌شده شما ارسال می‌شود.',
                        style: AppTypography.caption,
                      ),
                    ),
                  ],
                  const SizedBox(height: 20),
                  Text('مهلت پاسخ', style: AppTypography.label),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<int>(
                    value: _expiresInMinutes,
                    items: const <DropdownMenuItem<int>>[
                      DropdownMenuItem<int>(value: 5, child: Text('۵ دقیقه')),
                      DropdownMenuItem<int>(value: 15, child: Text('۱۵ دقیقه')),
                      DropdownMenuItem<int>(value: 30, child: Text('۳۰ دقیقه')),
                      DropdownMenuItem<int>(value: 60, child: Text('۱ ساعت')),
                    ],
                    onChanged: (value) =>
                        setState(() => _expiresInMinutes = value ?? 15),
                  ),
                  const SizedBox(height: 12),
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    value: _allowPartial,
                    onChanged: (value) =>
                        setState(() => _allowPartial = value),
                    title: const Text('پذیرش پیشنهاد جزئی'),
                    subtitle: Text(
                      'اگر فعال باشد، فروشندگان می‌توانند بخشی از مقدار '
                      'درخواستی را پیشنهاد دهند.',
                      style: AppTypography.caption,
                    ),
                  ),
                  if (_error != null) ...<Widget>[
                    const SizedBox(height: 12),
                    Text(
                      _error!,
                      style: AppTypography.caption
                          .copyWith(color: Colors.red),
                    ),
                  ],
                  if (action.hasError) ...<Widget>[
                    const SizedBox(height: 12),
                    InlineError(failure: asAppFailure(action.error!)),
                  ],
                  const SizedBox(height: 24),
                  OfflineActionGuard(
                    builder: (context, online) => FilledButton(
                      onPressed:
                          (online && !action.isLoading) ? _submit : null,
                      child: const Text('ارسال درخواست'),
                    ),
                  ),
                  const SizedBox(height: 32),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _submit() async {
    final quantity = FineWeight.tryFromGramsString(_quantity.text);
    if (quantity == null || quantity.isZero) {
      setState(() => _error = 'مقدار واردشده معتبر نیست.');

      return;
    }

    final purity = Purity.tryFromString(_purity.text);

    final rfq = await ref.read(rfqActionsProvider.notifier).create(
          instrumentCode: ref.read(selectedInstrumentProvider),
          side: _side,
          quantity: quantity,
          settlementType: _settlementType,
          deliveryType: _deliveryType,
          visibility: _visibility,
          expiresInMinutes: _expiresInMinutes,
          allowPartial: _allowPartial,
          minPurity: purity,
        );

    if (!mounted || rfq == null) {
      return;
    }

    context.pushReplacement('/rfq/${rfq.id}');
  }
}
