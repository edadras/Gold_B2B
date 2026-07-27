import 'package:flutter_test/flutter_test.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/features/market/data/models/instrument.dart';
import 'package:gold_b2b/features/market/data/models/quote.dart';
import 'package:gold_b2b/features/orders/application/order_form_controller.dart';
import 'package:gold_b2b/features/orders/data/models/order.dart';
import 'package:gold_b2b/features/orders/data/order_repository.dart';
import 'package:gold_b2b/features/settings/data/meta_repository.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/rial.dart';

/// `buildOrderFormView` is a pure function precisely so the whole of the order
/// form's behaviour can be tested without Riverpod, Flutter or a network.
/// Everything the user sees before tapping "confirm" is decided here.
void main() {
  final instrument = Instrument.fromJson(<String, dynamic>{
    'code': 'GOLD-995-T0',
    'display_name': 'GOLD-995-T0',
    'purity_x10': 9950,
    'settlement_type': 'T0',
    'is_active': true,
    'tick_size_rial': 10000,
    'min_quantity_mg': 50000, // 50 g
    'max_quantity_mg': 10000000, // 10 kg
    'max_slippage_bps': 50,
    'price_deviation_warning_bps': 500, // 5%
  });

  final quote = Quote.fromJson(<String, dynamic>{
    'instrument': 'GOLD-995-T0',
    'best_bid': 78420000,
    'best_ask': 78480000,
    'last_price': 78450000,
    'best_bid_qty_mg': 300000,
    'best_ask_qty_mg': 350000,
    'day_change_bps': 42,
    'timestamp': '2026-07-27T09:15:33.412Z',
  });

  const settings = PlatformSettings.fallback; // 0.15% buyer, 0.10% seller

  OrderFormState stateOf({
    OrderSide side = OrderSide.sell,
    OrderType type = OrderType.limit,
    String quantity = '',
    String price = '',
  }) =>
      OrderFormState(
        instrumentCode: 'GOLD-995-T0',
        side: side,
        type: type,
        quantityText: quantity,
        priceText: price,
        timeInForce: 'DAY',
        idempotencyKey: IdempotencyKey.mint(),
      );

  OrderFormView viewOf(
    OrderFormState state, {
    FineWeight? availableGold,
    Rial? availableRial,
    bool marketIsOpen = true,
    Quote? withQuote,
  }) =>
      buildOrderFormView(
        state: state,
        instrument: instrument,
        quote: withQuote ?? quote,
        settings: settings,
        availableGold: availableGold,
        availableRial: availableRial,
        marketIsOpen: marketIsOpen,
      );

  group('parsing', () {
    test('accepts Persian digits in both fields', () {
      final state = stateOf(quantity: '۳۰۰.۰۰۰', price: '۷۸,۵۰۰,۰۰۰');

      expect(state.quantity?.milligrams, 300000);
      expect(state.price?.rial, 78500000);
    });

    test('a half-typed value yields null rather than throwing', () {
      expect(stateOf(quantity: '250.').quantity?.milligrams, 250000);
      expect(stateOf(quantity: 'abc').quantity, isNull);
      expect(stateOf(price: '').price, isNull);
    });
  });

  group('validation', () {
    test('blocks an empty form', () {
      final view = viewOf(stateOf());

      expect(view.canSubmit, isFalse);
      expect(view.blockingProblems, isNotEmpty);
    });

    test('blocks a quantity below the instrument minimum', () {
      final view = viewOf(stateOf(quantity: '10', price: '78500000'));

      expect(view.canSubmit, isFalse);
      expect(
        view.blockingProblems.any((p) => p.message.contains('حداقل')),
        isTrue,
      );
    });

    test('blocks a quantity above the instrument maximum', () {
      final view = viewOf(stateOf(quantity: '20000', price: '78500000'));

      expect(
        view.blockingProblems.any((p) => p.message.contains('حداکثر')),
        isTrue,
      );
    });

    test('blocks a price that is not a multiple of the tick size', () {
      final view = viewOf(stateOf(quantity: '300', price: '78500001'));

      expect(view.canSubmit, isFalse);
      expect(
        view.blockingProblems.any((p) => p.message.contains('مضرب')),
        isTrue,
      );
    });

    test('blocks when the market is closed', () {
      final view = viewOf(
        stateOf(quantity: '300', price: '78500000'),
        availableGold: FineWeight.fromMilligrams(1000000),
        marketIsOpen: false,
      );

      expect(view.canSubmit, isFalse);
      expect(view.marketIsOpen, isFalse);
    });

    test('accepts a well-formed sell order', () {
      final view = viewOf(
        stateOf(quantity: '300', price: '78500000'),
        availableGold: FineWeight.fromMilligrams(1047320),
      );

      expect(view.blockingProblems, isEmpty);
      expect(view.canSubmit, isTrue);
    });
  });

  group('F11 — sell reserves exactly the fine weight, no fee in gold', () {
    test('blocks a sell larger than the available gold', () {
      final view = viewOf(
        stateOf(quantity: '300', price: '78500000'),
        availableGold: FineWeight.fromMilligrams(299999),
      );

      expect(view.canSubmit, isFalse);
      expect(
        view.blockingProblems.any((p) => p.message.contains('طلای در دسترس')),
        isTrue,
      );
    });

    test('allows a sell of exactly the available gold', () {
      // If the seller fee were (wrongly) taken out of gold, selling the whole
      // balance would be blocked. It is not: F11 says the reservation is
      // exactly the quantity.
      final view = viewOf(
        stateOf(quantity: '300', price: '78500000'),
        availableGold: FineWeight.fromMilligrams(300000),
      );

      expect(view.blockingProblems, isEmpty);
    });
  });

  group('F10 — buy reservation includes the fee', () {
    test('computes gross + fee', () {
      final view = viewOf(
        stateOf(side: OrderSide.buy, quantity: '300', price: '78500000'),
        availableRial: Rial.fromRial(999999999999),
      );

      // gross = floor(300,000 x 78,500,000 / 1,000) = 23,550,000,000
      // fee   = ceil(23,550,000,000 x 150 / 100,000) =     35,325,000
      expect(view.valuation!.grossAmount.amount, 23550000000);
      expect(view.valuation!.buyerFee.amount, 35325000);
      expect(view.requiredRial!.amount, 23585325000);
    });

    test('blocks a buy the rial balance cannot cover once the fee is added',
        () {
      // Exactly the gross amount, one rial short of gross + fee.
      final view = viewOf(
        stateOf(side: OrderSide.buy, quantity: '300', price: '78500000'),
        availableRial: Rial.fromRial(23550000000),
      );

      expect(view.canSubmit, isFalse);
      expect(
        view.blockingProblems.any((p) => p.message.contains('ریالی')),
        isTrue,
      );
    });

    test('allows a buy covered exactly by gross + fee', () {
      final view = viewOf(
        stateOf(side: OrderSide.buy, quantity: '300', price: '78500000'),
        availableRial: Rial.fromRial(23585325000),
      );

      expect(view.blockingProblems, isEmpty);
    });

    test('a MARKET buy is estimated at the WORST price, not the touch', () {
      final view = viewOf(
        stateOf(side: OrderSide.buy, type: OrderType.market, quantity: '300'),
        availableRial: Rial.fromRial(999999999999),
      );

      // best ask 78,480,000 worsened by 50 bps -> 78,872,400
      expect(view.valuation!.pricePerFineGram.rial, 78872400);
      expect(
        view.valuation!.grossAmount.amount,
        greaterThan(
          // strictly more than pricing at the touch would give
          78480000 * 300000 ~/ 1000,
        ),
      );
    });

    test('a MARKET order is blocked when that side of the book is empty', () {
      final emptyBook = Quote.fromJson(<String, dynamic>{
        'instrument': 'GOLD-995-T0',
        'best_bid': 78420000,
        'day_change_bps': 0,
        'timestamp': '2026-07-27T09:15:33.412Z',
      });

      final view = viewOf(
        stateOf(side: OrderSide.buy, type: OrderType.market, quantity: '300'),
        availableRial: Rial.fromRial(999999999999),
        withQuote: emptyBook,
      );

      expect(view.canSubmit, isFalse);
    });
  });

  group('the breakdown matches the §2.3 mockup', () {
    test('sell of 300 g at 78,500,000 nets 23,526,450,000 rial', () {
      final view = viewOf(
        stateOf(quantity: '300', price: '78500000'),
        availableGold: FineWeight.fromMilligrams(1047320),
      );

      final valuation = view.valuation!;

      expect(valuation.grossAmount.amount, 23550000000);
      // Seller fee at 0.10%: ceil(23,550,000,000 x 100 / 100,000)
      expect(valuation.sellerFee.amount, 23550000);
      expect(valuation.sellerNet.amount, 23526450000);
      expect(view.bottomLine!.amount, 23526450000);
      expect(view.bottomLineLabel, 'دریافتی شما');
    });

    test('the buyer bottom line is what they pay', () {
      final view = viewOf(
        stateOf(side: OrderSide.buy, quantity: '300', price: '78500000'),
        availableRial: Rial.fromRial(999999999999),
      );

      expect(view.bottomLine!.amount, 23585325000);
      expect(view.bottomLineLabel, 'پرداختی شما');
    });
  });

  group('fat-finger warning (§2.3 rule 3)', () {
    test('warns but does NOT block a price far from the market', () {
      // Reference is the mid, 78,450,000. A price of 90,000,000 is ~14.7%
      // away, well past the 5% threshold.
      final view = viewOf(
        stateOf(quantity: '300', price: '90000000'),
        availableGold: FineWeight.fromMilligrams(1047320),
      );

      expect(view.warnings, isNotEmpty);
      expect(view.blockingProblems, isEmpty);
      // A dealer placing a deliberate far-from-market resting order is doing
      // something normal; the form must not prevent it.
      expect(view.canSubmit, isTrue);
    });

    test('does not warn for a price near the market', () {
      final view = viewOf(
        stateOf(quantity: '300', price: '78500000'),
        availableGold: FineWeight.fromMilligrams(1047320),
      );

      expect(view.warnings, isEmpty);
    });

    test('does not warn on a MARKET order, which has no typed price', () {
      final view = viewOf(
        stateOf(side: OrderSide.buy, type: OrderType.market, quantity: '300'),
        availableRial: Rial.fromRial(999999999999),
      );

      expect(view.warnings, isEmpty);
    });
  });

  group('idempotency key lifetime', () {
    test('a key survives an edit to the form', () {
      final controller = _fakeController();
      final original = controller.state.idempotencyKey;

      controller
        ..setQuantityText('300')
        ..setPriceText('78500000')
        ..setSide(OrderSide.buy)
        ..setType(OrderType.market);

      // Same intent, still being composed: the key must not move.
      expect(controller.state.idempotencyKey, same(original));
      expect(controller.state.idempotencyKey.value, original.value);
    });

    test('resetForNextOrder mints a NEW key', () {
      final controller = _fakeController();
      final original = controller.state.idempotencyKey;

      controller.resetForNextOrder();

      expect(controller.state.idempotencyKey.value, isNot(original.value));
      // And the quantity is cleared so the next order starts empty.
      expect(controller.state.quantityText, '');
    });

    test('keys are unique and look like RFC 4122 v4', () {
      final keys = <String>{
        for (var i = 0; i < 500; i++) IdempotencyKey.mint().value,
      };

      expect(keys.length, 500);

      final pattern = RegExp(
        r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-'
        r'[0-9a-f]{12}$',
      );

      for (final key in keys) {
        expect(pattern.hasMatch(key), isTrue, reason: key);
      }
    });

    test('a key knows when it has outlived the server-side window', () {
      final fresh = IdempotencyKey.mint();
      final now = DateTime.now().toUtc();

      expect(fresh.isExpiredAt(now), isFalse);
      expect(
        fresh.isExpiredAt(now.add(const Duration(hours: 23))),
        isFalse,
      );
      expect(fresh.isExpiredAt(now.add(const Duration(hours: 25))), isTrue);
    });
  });

  group('quick-fill percentages never overshoot', () {
    test('MAX proposes exactly the available balance', () {
      final controller = _fakeController();
      final available = FineWeight.fromMilligrams(1047320);

      controller.applyQuantityPercent(100, available);

      expect(controller.state.quantity?.milligrams, 1047320);
    });

    test('percentages round DOWN', () {
      final controller = _fakeController();

      controller.applyQuantityPercent(50, FineWeight.fromMilligrams(7));

      expect(controller.state.quantity?.milligrams, 3);
    });
  });
}

/// A controller whose repository is never reached: every test here exercises
/// state transitions, not the network.
OrderFormController _fakeController() => OrderFormController(
      repository: _FakeOrderRepository(),
      instrumentCode: 'GOLD-995-T0',
      side: OrderSide.sell,
    );

/// Rather than pull in a mocking package for a single collaborator that is
/// never called, this forwards everything to `noSuchMethod` and throws — so a
/// test that accidentally reaches the network fails loudly instead of hanging.
class _FakeOrderRepository implements OrderRepository {
  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnsupportedError(
        'The order form tests must not touch the network '
        '(called ${invocation.memberName}).',
      );
}
