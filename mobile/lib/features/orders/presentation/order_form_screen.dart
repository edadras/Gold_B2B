import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/weight_formatter.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/market/application/market_providers.dart';
import 'package:gold_b2b/features/market/presentation/widgets/depth_ladder.dart';
import 'package:gold_b2b/features/market/presentation/widgets/quote_header.dart';
import 'package:gold_b2b/features/orders/application/order_form_controller.dart';
import 'package:gold_b2b/features/orders/application/orders_controller.dart';
import 'package:gold_b2b/features/orders/data/models/order.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/widgets/confirm_sheet.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/error_view.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// docs/07-mobile-flutter/02-screens.md §2.3.
///
/// The UX rules from that section, and where each is implemented:
///
///  1. live calculation on every keystroke — `orderFormViewProvider`;
///  2. local validation before submit — `buildOrderFormView`;
///  3. explicit warning past ~5% from market — the same, non-blocking;
///  4. submit disabled until valid — [OrderFormView.canSubmit];
///  5. final confirmation sheet plus biometric — [_submit];
///  6. immediate feedback after placing — the snackbar and the open-orders
///     prepend in [_submit];
///  7. form disabled with an explicit message when the market is closed —
///     the banner at the top of [build].
class OrderFormScreen extends ConsumerStatefulWidget {
  const OrderFormScreen({
    required this.instrumentCode,
    required this.side,
    super.key,
  });

  final String instrumentCode;
  final OrderSide side;

  @override
  ConsumerState<OrderFormScreen> createState() => _OrderFormScreenState();
}

class _OrderFormScreenState extends ConsumerState<OrderFormScreen> {
  final TextEditingController _quantity = TextEditingController();
  final TextEditingController _price = TextEditingController();

  OrderFormArgs get _args =>
      OrderFormArgs(instrumentCode: widget.instrumentCode, side: widget.side);

  @override
  void dispose() {
    _quantity.dispose();
    _price.dispose();
    super.dispose();
  }

  /// Keep the text controllers in step with the state when the state was
  /// changed from somewhere other than the keyboard — a depth-ladder tap, a
  /// BID/ASK button, a percentage button.
  void _syncControllers(OrderFormState state) {
    if (_quantity.text != state.quantityText) {
      _quantity.value = TextEditingValue(
        text: state.quantityText,
        selection: TextSelection.collapsed(offset: state.quantityText.length),
      );
    }

    if (_price.text != state.priceText) {
      _price.value = TextEditingValue(
        text: state.priceText,
        selection: TextSelection.collapsed(offset: state.priceText.length),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final view = ref.watch(orderFormViewProvider(_args));
    final controller = ref.read(orderFormProvider(_args).notifier);
    final quote = ref.watch(quoteProvider(widget.instrumentCode)).valueOrNull;

    // Seed the price from the book on first paint only.
    if (quote != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        controller.seedPriceIfUntouched(quote);
      });
    }

    _syncControllers(view.state);

    final isBuy = view.state.isBuy;
    final accent = isBuy ? AppColors.buy : AppColors.sell;

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(
          title: Text(
            '${isBuy ? 'خرید' : 'فروش'} — ${widget.instrumentCode}',
          ),
        ),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            if (quote != null) QuoteHeader(quote: quote),
            if (!view.marketIsOpen) const _MarketClosedNotice(),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: <Widget>[
                  _OrderTypeSelector(
                    type: view.state.type,
                    onChanged: controller.setType,
                  ),
                  const SizedBox(height: 20),
                  _QuantityField(
                    controller: _quantity,
                    view: view,
                    onChanged: controller.setQuantityText,
                    onPercent: (percent) {
                      final capacity = _capacityFor(view);
                      if (capacity != null) {
                        controller.applyQuantityPercent(percent, capacity);
                      }
                    },
                  ),
                  const SizedBox(height: 20),
                  if (!view.state.isMarketOrder)
                    _PriceField(
                      controller: _price,
                      view: view,
                      onChanged: controller.setPriceText,
                      onApply: controller.applyPrice,
                    )
                  else
                    const _MarketOrderNotice(),
                  const SizedBox(height: 20),
                  if (!view.state.isMarketOrder)
                    _TimeInForceField(
                      value: view.state.timeInForce,
                      onChanged: controller.setTimeInForce,
                    ),
                  const SizedBox(height: 20),
                  _Breakdown(view: view),
                  const SizedBox(height: 16),
                  for (final warning in view.warnings)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: _WarningRow(message: warning.message),
                    ),
                  for (final problem in view.blockingProblems)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: _ProblemRow(message: problem.message),
                    ),
                  if (view.state.failure != null) ...<Widget>[
                    const SizedBox(height: 8),
                    InlineError(
                      failure: view.state.failure!,
                      onRetry: view.state.failure!.isRetryable
                          // Retrying reuses the SAME idempotency key held in
                          // OrderFormState, so this cannot become a second
                          // order.
                          ? _submit
                          : null,
                    ),
                  ],
                  const SizedBox(height: 16),
                  ExpansionTile(
                    title: Text('عمق بازار', style: AppTypography.titleMedium),
                    tilePadding: EdgeInsets.zero,
                    children: <Widget>[
                      ref
                          .watch(depthProvider(widget.instrumentCode))
                          .maybeWhen(
                            data: (depth) => DepthLadder(
                              depth: depth,
                              maxLevels: 5,
                              onSelectPrice: controller.applyPrice,
                            ),
                            orElse: () => const SizedBox(height: 80),
                          ),
                    ],
                  ),
                  const SizedBox(height: 80),
                ],
              ),
            ),
          ],
        ),
        bottomNavigationBar: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: OfflineActionGuard(
              builder: (context, online) => FilledButton(
                style: FilledButton.styleFrom(backgroundColor: accent),
                onPressed:
                    (view.canSubmit && online) ? _submit : null,
                child: view.state.isSubmitting
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : Text('ثبت سفارش ${isBuy ? 'خرید' : 'فروش'}'),
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// What "MAX" means: available gold for a sell; for a buy it is not
  /// expressible in gold without a price, so the percentage buttons act on
  /// the quantity the available rial could buy at the current price.
  FineWeight? _capacityFor(OrderFormView view) {
    if (!view.state.isBuy) {
      return view.availableGold;
    }

    final price = view.state.price ?? view.quote?.bestAsk;
    final rial = view.availableRial;
    if (price == null || price.isZero || rial == null || !rial.isPositive) {
      return null;
    }

    // Inverse of F5, rounded down: how many fine milligrams the available
    // rial covers at this price. Deliberately ignores the fee, so the
    // suggestion is never larger than what the balance check will allow.
    return FineWeight.fromMilligrams(
      (BigInt.from(rial.amount) * BigInt.from(1000) ~/ BigInt.from(price.rial))
          .toInt(),
    );
  }

  Future<void> _submit() async {
    final view = ref.read(orderFormViewProvider(_args));
    final controller = ref.read(orderFormProvider(_args).notifier);
    final valuation = view.valuation;

    if (!view.canSubmit || valuation == null) {
      return;
    }

    final isBuy = view.state.isBuy;

    final result = await confirmFinancialAction(
      context: context,
      ref: ref,
      title: 'تأیید سفارش ${isBuy ? 'خرید' : 'فروش'}',
      amount: isBuy ? valuation.buyerNet : valuation.sellerNet,
      confirmLabel: 'تأیید',
      warning: isBuy
          ? 'مبلغ ${MoneyFormatter.exact(view.requiredRial ?? valuation.buyerNet)}'
              ' ریال از موجودی شما قفل خواهد شد.'
          : '${WeightFormatter.fine(valuation.fineWeight)} گرم از موجودی '
              'طلای شما قفل خواهد شد.',
      lines: <ConfirmationLine>[
        ConfirmationLine(
          label: 'ابزار',
          value: Text(view.state.instrumentCode, style: AppTypography.code),
        ),
        ConfirmationLine(
          label: 'نوع',
          value: Text(view.state.type.label, style: AppTypography.body),
        ),
        ConfirmationLine(
          label: 'مقدار',
          value: WeightDisplay(valuation.fineWeight),
        ),
        ConfirmationLine(
          label: view.state.isMarketOrder
              ? 'قیمت تخمینی (بدترین حالت)'
              : 'قیمت هر گرم',
          value: PriceDisplay(valuation.pricePerFineGram, showUnit: true),
        ),
        moneyLine('مبلغ ناخالص', valuation.grossAmount),
        moneyLine(
          'کارمزد '
          '(${PercentFormatter.fromRateX100k(isBuy ? view.settings.buyerFee.rateX100k : view.settings.sellerFee.rateX100k)})',
          isBuy ? valuation.buyerFee : valuation.sellerFee,
        ),
        if (!valuation.totalTax.isZero)
          moneyLine('مالیات', isBuy ? valuation.buyerTax : valuation.sellerTax),
        moneyLine(
          view.bottomLineLabel,
          isBuy ? valuation.buyerNet : valuation.sellerNet,
          emphasis: true,
          dividerAbove: true,
        ),
      ],
    );

    if (!result.confirmed || !mounted) {
      return;
    }

    // TODO(backend): `POST /orders` is marked 🔑 but not ✍️ in
    // docs/05-api/02-endpoints.md §2.5, so no transaction-signing code is
    // sent here. If order placement later requires ✍️, forward
    // `result.totpCode` — the confirmation flow already collects it when
    // biometrics are unavailable. The header name is not specified anywhere
    // in the API docs and must be agreed before that can be wired up.
    final order = await controller.submit();

    if (!mounted) {
      return;
    }

    if (order == null) {
      // The failure is already in state and rendered by InlineError, with a
      // retry that reuses the same idempotency key.
      await HapticFeedback.heavyImpact();

      return;
    }

    await HapticFeedback.mediumImpact();

    // §2.3 rule 6: immediate feedback, even before the order executes.
    ref.read(openOrdersProvider.notifier).prepend(order);
    controller.resetForNextOrder();

    if (!mounted) {
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          'سفارش ${order.orderCode} ثبت شد '
          '(${OrderStatus.label(order.status)}).',
        ),
      ),
    );

    Navigator.of(context).maybePop();
  }
}

class _OrderTypeSelector extends StatelessWidget {
  const _OrderTypeSelector({required this.type, required this.onChanged});

  final OrderType type;
  final ValueChanged<OrderType> onChanged;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text('نوع سفارش', style: AppTypography.label),
          const SizedBox(height: 8),
          SegmentedButton<OrderType>(
            segments: const <ButtonSegment<OrderType>>[
              ButtonSegment<OrderType>(
                value: OrderType.limit,
                label: Text('محدود'),
              ),
              ButtonSegment<OrderType>(
                value: OrderType.market,
                label: Text('بازار'),
              ),
            ],
            selected: <OrderType>{type},
            onSelectionChanged: (selection) => onChanged(selection.first),
          ),
        ],
      );
}

class _QuantityField extends StatelessWidget {
  const _QuantityField({
    required this.controller,
    required this.view,
    required this.onChanged,
    required this.onPercent,
  });

  final TextEditingController controller;
  final OrderFormView view;
  final ValueChanged<String> onChanged;
  final ValueChanged<int> onPercent;

  @override
  Widget build(BuildContext context) {
    final available = view.state.isBuy ? null : view.availableGold;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text('مقدار (گرم خالص)', style: AppTypography.label),
        const SizedBox(height: 8),
        TextField(
          controller: controller,
          // Persian keyboards are handled by NumericInput, so the field itself
          // accepts free text rather than fighting the IME with an input
          // formatter that would reject ۱۲۳ outright.
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          textDirection: TextDirection.ltr,
          textAlign: TextAlign.left,
          style: AppTypography.numeric,
          decoration: const InputDecoration(suffixText: 'گرم'),
          onChanged: onChanged,
        ),
        const SizedBox(height: 8),
        Row(
          children: <Widget>[
            for (final percent in const <int>[25, 50, 75, 100])
              Padding(
                padding: const EdgeInsetsDirectional.only(end: 8),
                child: OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size(56, 36),
                    padding: const EdgeInsets.symmetric(horizontal: 10),
                  ),
                  onPressed: () => onPercent(percent),
                  child: Text(
                    percent == 100
                        ? 'حداکثر'
                        : PercentFormatter.fromBps(percent * 100),
                  ),
                ),
              ),
          ],
        ),
        if (available != null) ...<Widget>[
          const SizedBox(height: 6),
          Row(
            children: <Widget>[
              Text('در دسترس: ', style: AppTypography.caption),
              LtrNumber(
                WeightFormatter.fine(available),
                style: AppTypography.numericSmall,
              ),
              Text(' گرم', style: AppTypography.caption),
            ],
          ),
        ],
      ],
    );
  }
}

class _PriceField extends StatelessWidget {
  const _PriceField({
    required this.controller,
    required this.view,
    required this.onChanged,
    required this.onApply,
  });

  final TextEditingController controller;
  final OrderFormView view;
  final ValueChanged<String> onChanged;
  final void Function(PricePerFineGram price) onApply;

  @override
  Widget build(BuildContext context) {
    final quote = view.quote;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Text('قیمت هر گرم (ریال)', style: AppTypography.label),
        const SizedBox(height: 8),
        TextField(
          controller: controller,
          keyboardType: TextInputType.number,
          textDirection: TextDirection.ltr,
          textAlign: TextAlign.left,
          style: AppTypography.numeric,
          decoration: const InputDecoration(suffixText: 'ریال'),
          onChanged: onChanged,
        ),
        const SizedBox(height: 8),
        Row(
          children: <Widget>[
            if (quote?.bestBid != null)
              Padding(
                padding: const EdgeInsetsDirectional.only(end: 8),
                child: OutlinedButton(
                  onPressed: () => onApply(quote!.bestBid!),
                  style: OutlinedButton.styleFrom(minimumSize: const Size(64, 36)),
                  child: const Text('BID'),
                ),
              ),
            if (quote?.bestAsk != null)
              Padding(
                padding: const EdgeInsetsDirectional.only(end: 8),
                child: OutlinedButton(
                  onPressed: () => onApply(quote!.bestAsk!),
                  style: OutlinedButton.styleFrom(minimumSize: const Size(64, 36)),
                  child: const Text('ASK'),
                ),
              ),
            if (quote?.midPrice != null)
              OutlinedButton(
                onPressed: () => onApply(quote!.midPrice!),
                style: OutlinedButton.styleFrom(minimumSize: const Size(64, 36)),
                child: const Text('MID'),
              ),
          ],
        ),
      ],
    );
  }
}

class _TimeInForceField extends StatelessWidget {
  const _TimeInForceField({required this.value, required this.onChanged});

  final String value;
  final ValueChanged<String> onChanged;

  static const Map<String, String> _labels = <String, String>{
    'DAY': 'تا پایان روز',
    'GTC': 'تا زمان لغو',
    'IOC': 'اجرای فوری یا لغو',
    'FOK': 'اجرای کامل یا لغو',
  };

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text('اعتبار', style: AppTypography.label),
          const SizedBox(height: 8),
          DropdownButtonFormField<String>(
            value: _labels.containsKey(value) ? value : 'DAY',
            items: _labels.entries
                .map(
                  (entry) => DropdownMenuItem<String>(
                    value: entry.key,
                    child: Text(entry.value),
                  ),
                )
                .toList(growable: false),
            onChanged: (selected) {
              if (selected != null) {
                onChanged(selected);
              }
            },
          ),
        ],
      );
}

/// The live breakdown panel from the §2.3 mockup.
class _Breakdown extends StatelessWidget {
  const _Breakdown({required this.view});

  final OrderFormView view;

  @override
  Widget build(BuildContext context) {
    final valuation = view.valuation;

    if (valuation == null) {
      return SectionCard(
        child: Text(
          'برای مشاهده محاسبه، مقدار و قیمت را وارد کنید.',
          style: AppTypography.bodyMuted,
        ),
      );
    }

    final isBuy = view.state.isBuy;
    final feeRate = isBuy
        ? view.settings.buyerFee.rateX100k
        : view.settings.sellerFee.rateX100k;

    return SectionCard(
      child: Column(
        children: <Widget>[
          KeyValueRow(
            label: 'مبلغ ناخالص',
            value: MoneyDisplay(
              valuation.grossAmount,
              emphasis: MoneyEmphasis.small,
            ),
          ),
          KeyValueRow(
            label: 'کارمزد (${PercentFormatter.fromRateX100k(feeRate)})',
            value: MoneyDisplay(
              isBuy ? valuation.buyerFee : valuation.sellerFee,
              emphasis: MoneyEmphasis.small,
            ),
          ),
          if (!valuation.totalTax.isZero)
            KeyValueRow(
              label: 'مالیات',
              value: MoneyDisplay(
                isBuy ? valuation.buyerTax : valuation.sellerTax,
                emphasis: MoneyEmphasis.small,
              ),
            ),
          const ThinDivider(vertical: 6),
          KeyValueRow(
            label: view.bottomLineLabel,
            emphasis: true,
            value: MoneyDisplay(
              isBuy ? valuation.buyerNet : valuation.sellerNet,
            ),
          ),
        ],
      ),
    );
  }
}

class _MarketOrderNotice extends StatelessWidget {
  const _MarketOrderNotice();

  @override
  Widget build(BuildContext context) => SectionCard(
        borderColor: AppColors.warning,
        child: Row(
          children: <Widget>[
            const Icon(
              Icons.info_outline,
              size: 18,
              color: AppColors.warning,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'سفارش بازار با بهترین قیمت موجود اجرا می‌شود. '
                'محاسبه زیر بر اساس بدترین قیمت قابل قبول (با احتساب '
                'حداکثر لغزش) انجام شده است.',
                style: AppTypography.caption,
              ),
            ),
          ],
        ),
      );
}

class _MarketClosedNotice extends StatelessWidget {
  const _MarketClosedNotice();

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        color: AppColors.warningSurface,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: <Widget>[
            const Icon(Icons.do_not_disturb_on_outlined,
                size: 16, color: AppColors.warning),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'بازار بسته است. امکان ثبت سفارش وجود ندارد.',
                style:
                    AppTypography.caption.copyWith(color: AppColors.warning),
              ),
            ),
          ],
        ),
      );
}

class _WarningRow extends StatelessWidget {
  const _WarningRow({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const Icon(Icons.warning_amber_rounded,
              size: 16, color: AppColors.warning),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: AppTypography.caption.copyWith(color: AppColors.warning),
            ),
          ),
        ],
      );
}

class _ProblemRow extends StatelessWidget {
  const _ProblemRow({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const Icon(Icons.block, size: 16, color: AppColors.negative),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: AppTypography.caption.copyWith(color: AppColors.negative),
            ),
          ),
        ],
      );
}
