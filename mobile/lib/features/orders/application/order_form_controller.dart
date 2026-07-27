import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/features/dashboard/application/balance_controller.dart';
import 'package:gold_b2b/features/market/application/market_providers.dart';
import 'package:gold_b2b/features/market/data/models/instrument.dart';
import 'package:gold_b2b/features/market/data/models/quote.dart';
import 'package:gold_b2b/features/orders/application/orders_controller.dart';
import 'package:gold_b2b/features/orders/data/models/order.dart';
import 'package:gold_b2b/features/orders/data/order_repository.dart';
import 'package:gold_b2b/features/settings/data/meta_repository.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/trade_valuation.dart';

/// Everything the order form needs to render itself.
///
/// The design rule here is that NOTHING is computed in the widget. The widget
/// reads `quantity`, `valuation`, `blockingProblem` and so on, and paints
/// them. That keeps every number on the screen traceable to one of the
/// formulas in docs/11-appendix/01-formulas.md, and makes the whole thing
/// testable without a Flutter binding.
final class OrderFormState {
  const OrderFormState({
    required this.instrumentCode,
    required this.side,
    required this.type,
    required this.quantityText,
    required this.priceText,
    required this.timeInForce,
    required this.idempotencyKey,
    this.isSubmitting = false,
    this.failure,
    this.placedOrder,
    this.hasTouchedPrice = false,
  });

  final String instrumentCode;
  final OrderSide side;
  final OrderType type;

  /// Raw text, exactly as typed — Persian digits and all. Parsing happens in
  /// [quantity]/[price] via `NumericInput`, never with `double.parse`.
  final String quantityText;
  final String priceText;

  final String timeInForce;

  /// Minted when the form is opened and held for the entire life of this
  /// intent.
  ///
  /// THIS IS THE CRITICAL FIELD. If the submit times out, the user taps "try
  /// again", and the SAME key goes back to the server, which replays the
  /// stored result rather than placing a second order at a price that has
  /// since moved. A fresh key here would be a duplicate-order bug that only
  /// shows up on a bad connection — i.e. exactly when it costs the most.
  ///
  /// It is replaced only after an order has been definitively accepted, by
  /// [resetForNextOrder].
  final IdempotencyKey idempotencyKey;

  final bool isSubmitting;
  final AppFailure? failure;

  /// Set once the server has accepted the order.
  final Order? placedOrder;

  /// Whether the user has edited the price field. Until they do, the form
  /// keeps snapping the price to the live best bid/ask; after they do, it
  /// stops moving under their fingers.
  final bool hasTouchedPrice;

  FineWeight? get quantity => FineWeight.tryFromGramsString(quantityText);

  PricePerFineGram? get price => PricePerFineGram.tryFromString(priceText);

  bool get isMarketOrder => type == OrderType.market;

  bool get isBuy => side == OrderSide.buy;

  OrderFormState copyWith({
    String? instrumentCode,
    OrderSide? side,
    OrderType? type,
    String? quantityText,
    String? priceText,
    String? timeInForce,
    IdempotencyKey? idempotencyKey,
    bool? isSubmitting,
    AppFailure? failure,
    Order? placedOrder,
    bool? hasTouchedPrice,
    bool clearFailure = false,
    bool clearPlacedOrder = false,
  }) =>
      OrderFormState(
        instrumentCode: instrumentCode ?? this.instrumentCode,
        side: side ?? this.side,
        type: type ?? this.type,
        quantityText: quantityText ?? this.quantityText,
        priceText: priceText ?? this.priceText,
        timeInForce: timeInForce ?? this.timeInForce,
        idempotencyKey: idempotencyKey ?? this.idempotencyKey,
        isSubmitting: isSubmitting ?? this.isSubmitting,
        failure: clearFailure ? null : (failure ?? this.failure),
        placedOrder:
            clearPlacedOrder ? null : (placedOrder ?? this.placedOrder),
        hasTouchedPrice: hasTouchedPrice ?? this.hasTouchedPrice,
      );
}

/// A reason the order cannot be submitted, or a warning the user must see.
final class OrderFormProblem {
  const OrderFormProblem(this.message, {this.blocking = true});

  final String message;

  /// Blocking problems disable the submit button. Non-blocking ones are
  /// warnings the user may knowingly proceed past — the fat-finger check is
  /// the main one, because there ARE legitimate reasons to place an order far
  /// from the market.
  final bool blocking;
}

/// The fully derived view of the form: parsed inputs, validation, and the
/// live breakdown.
final class OrderFormView {
  const OrderFormView({
    required this.state,
    required this.instrument,
    required this.quote,
    required this.settings,
    required this.problems,
    required this.valuation,
    required this.requiredRial,
    required this.availableGold,
    required this.availableRial,
    required this.marketIsOpen,
  });

  final OrderFormState state;
  final Instrument? instrument;
  final Quote? quote;
  final PlatformSettings settings;

  final List<OrderFormProblem> problems;

  /// F5/F8/F9 — null until both quantity and an effective price are known.
  final TradeValuation? valuation;

  /// F10 — what a buy order will lock. Null for a sell.
  final Rial? requiredRial;

  final FineWeight? availableGold;
  final Rial? availableRial;
  final bool marketIsOpen;

  List<OrderFormProblem> get blockingProblems =>
      problems.where((problem) => problem.blocking).toList(growable: false);

  List<OrderFormProblem> get warnings =>
      problems.where((problem) => !problem.blocking).toList(growable: false);

  bool get canSubmit =>
      !state.isSubmitting &&
      marketIsOpen &&
      blockingProblems.isEmpty &&
      state.quantity != null &&
      (state.isMarketOrder || state.price != null);

  /// What the seller receives, or what the buyer pays — the bottom line of the
  /// breakdown panel and of the confirmation sheet.
  Rial? get bottomLine {
    final value = valuation;
    if (value == null) {
      return null;
    }

    return state.isBuy ? value.buyerNet : value.sellerNet;
  }

  String get bottomLineLabel =>
      state.isBuy ? 'پرداختی شما' : 'دریافتی شما';
}

/// Owns the order form.
class OrderFormController extends StateNotifier<OrderFormState> {
  OrderFormController({
    required OrderRepository repository,
    required String instrumentCode,
    required OrderSide side,
  })  : _repository = repository,
        super(
          OrderFormState(
            instrumentCode: instrumentCode,
            side: side,
            type: OrderType.limit,
            quantityText: '',
            priceText: '',
            timeInForce: 'DAY',
            // Minted here: opening the form IS the start of one intent.
            idempotencyKey: IdempotencyKey.mint(),
          ),
        );

  final OrderRepository _repository;

  void setSide(OrderSide side) {
    state = state.copyWith(side: side, clearFailure: true);
  }

  void setType(OrderType type) {
    state = state.copyWith(type: type, clearFailure: true);
  }

  void setTimeInForce(String timeInForce) {
    state = state.copyWith(timeInForce: timeInForce);
  }

  void setQuantityText(String text) {
    state = state.copyWith(quantityText: text, clearFailure: true);
  }

  void setPriceText(String text) {
    state = state.copyWith(
      priceText: text,
      hasTouchedPrice: true,
      clearFailure: true,
    );
  }

  /// Fill the price field from the book — the BID/ASK/MID buttons, and a tap
  /// on a depth ladder row.
  void applyPrice(PricePerFineGram price) {
    state = state.copyWith(
      priceText: price.rial.toString(),
      hasTouchedPrice: true,
      clearFailure: true,
    );
  }

  /// Seed the price from the live quote, but only while the user has not
  /// touched the field. A price that keeps changing under a typing user is
  /// how wrong orders get placed.
  void seedPriceIfUntouched(Quote quote) {
    if (state.hasTouchedPrice || state.priceText.isNotEmpty) {
      return;
    }

    final seed = state.isBuy ? quote.bestAsk : quote.bestBid;
    if (seed == null) {
      return;
    }

    state = state.copyWith(priceText: seed.rial.toString());
  }

  /// The 25% / 50% / 75% / MAX buttons.
  ///
  /// Rounds DOWN (`FineWeight.percentFloor`), so "MAX" can never propose more
  /// than is actually available.
  void applyQuantityPercent(int percent, FineWeight capacity) {
    final quantity = capacity.percentFloor(percent);
    state = state.copyWith(quantityText: quantity.grams, clearFailure: true);
  }

  void clearFailure() {
    state = state.copyWith(clearFailure: true);
  }

  /// Submit.
  ///
  /// Returns the accepted order, or null when it failed — in which case
  /// [OrderFormState.failure] carries the reason and the idempotency key is
  /// DELIBERATELY unchanged, so that pressing the button again is a retry of
  /// the same intent rather than a second order.
  Future<Order?> submit() async {
    final quantity = state.quantity;
    if (quantity == null) {
      return null;
    }

    final price = state.price;
    if (!state.isMarketOrder && price == null) {
      return null;
    }

    state = state.copyWith(isSubmitting: true, clearFailure: true);

    final request = PlaceOrderRequest(
      instrumentCode: state.instrumentCode,
      side: state.side,
      type: state.type,
      quantity: quantity,
      timeInForce: state.isMarketOrder ? 'IOC' : state.timeInForce,
      price: state.isMarketOrder ? null : price,
    );

    try {
      final order = await _repository.place(
        request,
        idempotencyKey: state.idempotencyKey,
      );

      if (mounted) {
        state = state.copyWith(isSubmitting: false, placedOrder: order);
      }

      return order;
    } on AppFailure catch (failure) {
      if (mounted) {
        state = state.copyWith(isSubmitting: false, failure: failure);
      }

      return null;
    }
  }

  /// Start a NEW intent. Call only after an order has been accepted.
  ///
  /// This is the one place a new idempotency key is minted after
  /// construction, and it is guarded behind "we know the previous order
  /// landed".
  void resetForNextOrder() {
    state = OrderFormState(
      instrumentCode: state.instrumentCode,
      side: state.side,
      type: state.type,
      quantityText: '',
      priceText: state.priceText,
      timeInForce: state.timeInForce,
      idempotencyKey: IdempotencyKey.mint(),
      hasTouchedPrice: state.hasTouchedPrice,
    );
  }
}

/// Identifies one order form instance.
final class OrderFormArgs {
  const OrderFormArgs({required this.instrumentCode, required this.side});

  final String instrumentCode;
  final OrderSide side;

  @override
  bool operator ==(Object other) =>
      other is OrderFormArgs &&
      other.instrumentCode == instrumentCode &&
      other.side == side;

  @override
  int get hashCode => Object.hash(instrumentCode, side);
}

final orderFormProvider = StateNotifierProvider.autoDispose
    .family<OrderFormController, OrderFormState, OrderFormArgs>(
  // autoDispose so that leaving the screen ends the intent and the next visit
  // mints a fresh idempotency key. Keeping the old key alive across an
  // abandoned form would mean a later, different order could collide with a
  // stale server-side record.
  (ref, args) {
    return OrderFormController(
      repository: ref.watch(orderRepositoryProvider),
      instrumentCode: args.instrumentCode,
      side: args.side,
    );
  },
);

/// The derived view. Everything the widget reads comes from here.
final orderFormViewProvider = Provider.autoDispose
    .family<OrderFormView, OrderFormArgs>((ref, args) {
  final state = ref.watch(orderFormProvider(args));
  final instrument =
      ref.watch(instrumentProvider(args.instrumentCode)).valueOrNull;
  final quote = ref.watch(quoteProvider(args.instrumentCode)).valueOrNull;
  final session =
      ref.watch(marketSessionProvider(args.instrumentCode)).valueOrNull;
  final settings =
      ref.watch(platformSettingsProvider).valueOrNull ??
          PlatformSettings.fallback;

  final availableGold = ref.watch(availableGoldProvider);
  final availableRial = ref.watch(availableRialProvider);

  return buildOrderFormView(
    state: state,
    instrument: instrument,
    quote: quote,
    settings: settings,
    availableGold: availableGold,
    availableRial: availableRial,
    marketIsOpen: session?.isOpen ?? true,
  );
});

/// Pure function so the whole of the form's behaviour can be unit tested
/// without Riverpod, Flutter, or a network.
///
/// Validation order matters: parse problems first (they make everything else
/// meaningless), then instrument rules, then balance, then the fat-finger
/// warning last because it is the only non-blocking one.
OrderFormView buildOrderFormView({
  required OrderFormState state,
  required Instrument? instrument,
  required Quote? quote,
  required PlatformSettings settings,
  required FineWeight? availableGold,
  required Rial? availableRial,
  required bool marketIsOpen,
}) {
  const calculator = TradeValueCalculator();
  final problems = <OrderFormProblem>[];

  if (!marketIsOpen) {
    problems.add(
      const OrderFormProblem(
        'بازار در حال حاضر بسته است. امکان ثبت سفارش وجود ندارد.',
      ),
    );
  }

  if (instrument != null && !instrument.isActive) {
    problems.add(const OrderFormProblem('این ابزار فعال نیست.'));
  }

  final quantity = state.quantity;
  if (state.quantityText.trim().isEmpty) {
    problems.add(const OrderFormProblem('مقدار را وارد کنید.'));
  } else if (quantity == null) {
    problems.add(const OrderFormProblem('مقدار واردشده معتبر نیست.'));
  } else if (instrument != null) {
    final message = instrument.validateQuantity(quantity);
    if (message != null) {
      problems.add(OrderFormProblem(message));
    }
  }

  // The price actually used for the estimate. A LIMIT order uses what the
  // user typed. A MARKET order is estimated at the WORST price it could get
  // (F10) — showing the mid would systematically understate what a market buy
  // costs, which is precisely the number the user is relying on.
  PricePerFineGram? effectivePrice;
  if (state.isMarketOrder) {
    final touchPrice = state.isBuy ? quote?.bestAsk : quote?.bestBid;
    final slippage = instrument?.maxSlippageBps ?? 50;

    effectivePrice = touchPrice == null
        ? null
        : state.isBuy
            ? touchPrice.worseForBuyer(slippage)
            : touchPrice.worseForSeller(slippage);

    if (touchPrice == null) {
      problems.add(
        const OrderFormProblem(
          'در این سمت بازار سفارشی وجود ندارد؛ سفارش بازار قابل ثبت نیست.',
        ),
      );
    }
  } else {
    final typed = state.price;
    if (state.priceText.trim().isEmpty) {
      problems.add(const OrderFormProblem('قیمت را وارد کنید.'));
    } else if (typed == null) {
      problems.add(const OrderFormProblem('قیمت واردشده معتبر نیست.'));
    } else if (instrument != null) {
      final message = instrument.validatePrice(typed);
      if (message != null) {
        problems.add(OrderFormProblem(message));
      }
    }

    effectivePrice = typed;
  }

  TradeValuation? valuation;
  Rial? requiredRial;

  if (quantity != null && effectivePrice != null && !effectivePrice.isZero) {
    valuation = calculator.value(
      fineWeight: quantity,
      price: effectivePrice,
      buyerFee: settings.buyerFee,
      sellerFee: settings.sellerFee,
      tax: settings.tax,
    );

    if (state.isBuy) {
      // F10 — gross + fee + tax, computed exactly as the server will.
      requiredRial = calculator.buyerRequirement(
        quantity: quantity,
        price: effectivePrice,
        buyerFee: settings.buyerFee,
        tax: settings.tax,
      );
    }
  }

  // --- balance checks ----------------------------------------------------
  //
  // These mirror F10/F11. They are a courtesy: the server re-checks and will
  // answer INSUFFICIENT_GOLD / INSUFFICIENT_RIAL. Doing it here means the
  // user finds out before they have gone through the confirmation sheet and
  // a biometric prompt.

  if (quantity != null && !state.isBuy && availableGold != null) {
    // F11 — a seller reserves exactly the fine weight sold; the seller fee
    // comes out of rial at settlement, not out of gold.
    if (quantity > availableGold) {
      problems.add(
        OrderFormProblem(
          'موجودی طلای در دسترس کافی نیست. '
          'در دسترس: ${availableGold.gramsFormatted} گرم',
        ),
      );
    }
  }

  if (requiredRial != null && state.isBuy && availableRial != null) {
    if (requiredRial > availableRial) {
      problems.add(
        OrderFormProblem(
          'موجودی ریالی در دسترس کافی نیست. '
          'مورد نیاز: ${requiredRial.formatted} ریال',
        ),
      );
    }
  }

  // --- fat finger ---------------------------------------------------------
  //
  // §2.3 rule 3: warn explicitly when the price is more than ~5% away from
  // the market. Non-blocking on purpose — a dealer placing a deliberate
  // far-from-market resting order is a normal thing to do, and blocking it
  // would be wrong. F24 is the same arithmetic the circuit breaker uses.

  if (!state.isMarketOrder) {
    final typed = state.price;
    final reference = quote?.referencePrice;

    if (typed != null && reference != null && !reference.isZero) {
      final deviation = typed.deviationBpsFrom(reference);
      final threshold = instrument?.priceDeviationWarningBps ?? 500;

      if (deviation != null && deviation > threshold) {
        problems.add(
          OrderFormProblem(
            'قیمت واردشده حدود ${PercentFormatter.fromBps(deviation)} '
            'با قیمت بازار فاصله دارد. پیش از تأیید، عدد را بررسی کنید.',
            blocking: false,
          ),
        );
      }
    }
  }

  return OrderFormView(
    state: state,
    instrument: instrument,
    quote: quote,
    settings: settings,
    problems: problems,
    valuation: valuation,
    requiredRial: requiredRial,
    availableGold: availableGold,
    availableRial: availableRial,
    marketIsOpen: marketIsOpen,
  );
}
