import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';

enum OrderSide { buy, sell }

extension OrderSideX on OrderSide {
  String get wire => this == OrderSide.buy ? 'BUY' : 'SELL';

  String get label => this == OrderSide.buy ? 'خرید' : 'فروش';

  static OrderSide parse(String value) =>
      value.toUpperCase() == 'SELL' ? OrderSide.sell : OrderSide.buy;
}

enum OrderType { limit, market }

extension OrderTypeX on OrderType {
  String get wire => this == OrderType.limit ? 'LIMIT' : 'MARKET';

  String get label => this == OrderType.limit ? 'محدود' : 'بازار';

  static OrderType parse(String value) =>
      value.toUpperCase() == 'MARKET' ? OrderType.market : OrderType.limit;
}

/// docs/11-appendix/02-state-machines.md §2.3.
final class OrderStatus {
  const OrderStatus._();

  static const String pending = 'PENDING';
  static const String open = 'OPEN';
  static const String partiallyFilled = 'PARTIALLY_FILLED';
  static const String filled = 'FILLED';
  static const String cancelled = 'CANCELLED';
  static const String expired = 'EXPIRED';
  static const String rejected = 'REJECTED';

  /// Client-only, while a cancel request is in flight.
  static const String cancelling = 'CANCELLING';

  static const Set<String> openStates = <String>{
    pending,
    open,
    partiallyFilled,
    cancelling,
  };

  static String label(String status) => switch (status) {
        pending => 'در حال ثبت',
        open => 'باز',
        partiallyFilled => 'اجرای جزئی',
        filled => 'اجرا شده',
        cancelled => 'لغو شده',
        expired => 'منقضی',
        rejected => 'رد شده',
        cancelling => 'در حال لغو',
        // A status the client has not been taught (§1.11 permits new enum
        // values without a version bump). Show it raw rather than hiding the
        // order or crashing.
        _ => status,
      };

  static StatusTone tone(String status) => switch (status) {
        filled => StatusTone.positive,
        open || partiallyFilled => StatusTone.info,
        pending || cancelling => StatusTone.warning,
        cancelled || expired => StatusTone.neutral,
        rejected => StatusTone.negative,
        _ => StatusTone.neutral,
      };
}

/// One execution against an order.
final class Fill {
  const Fill({
    required this.tradeCode,
    required this.quantity,
    required this.price,
    required this.executedAt,
  });

  final String tradeCode;
  final FineWeight quantity;
  final PricePerFineGram price;
  final DateTime executedAt;

  factory Fill.fromJson(Map<String, dynamic> json) => Fill(
        tradeCode: json.stringOr('trade_code', ''),
        quantity: json.fineWeightOr('quantity_mg', FineWeight.zero),
        price: json.priceOrNull('price_rial') ?? PricePerFineGram.zero,
        executedAt:
            json.dateTimeOrNull('executed_at') ?? DateTime.now().toUtc(),
      );
}

final class Order {
  const Order({
    required this.id,
    required this.orderCode,
    required this.instrumentCode,
    required this.side,
    required this.type,
    required this.timeInForce,
    required this.quantity,
    required this.filled,
    required this.remaining,
    required this.status,
    required this.placedAt,
    this.price,
    this.reservedRial,
    this.expiresAt,
    this.fills = const <Fill>[],
    this.rejectionReason,
  });

  final int id;
  final String orderCode;
  final String instrumentCode;
  final OrderSide side;
  final OrderType type;

  /// `DAY`, `GTC`, `IOC`, `FOK`.
  final String timeInForce;

  final FineWeight quantity;
  final FineWeight filled;
  final FineWeight remaining;

  /// Null for a MARKET order.
  final PricePerFineGram? price;

  final String status;

  /// What the ledger locked when the order was accepted (F10 for a buy).
  final Rial? reservedRial;

  final DateTime placedAt;
  final DateTime? expiresAt;
  final List<Fill> fills;
  final String? rejectionReason;

  bool get isOpen => OrderStatus.openStates.contains(status);

  bool get isCancellable =>
      status == OrderStatus.open || status == OrderStatus.partiallyFilled;

  bool get isPartiallyFilled => !filled.isZero && !remaining.isZero;

  /// 0..1, for the fill progress bar.
  double fillRatio() { // ALLOW_DOUBLE_DISPLAY_ONLY
    if (quantity.isZero) {
      return 0;
    }

    return (filled.milligrams / quantity.milligrams).clamp(0.0, 1.0);
  }

  /// Average execution price across [fills], computed with F22 (VWAP) so that
  /// it agrees with the server's own figure to the rial.
  PricePerFineGram? get averageFillPrice {
    if (fills.isEmpty) {
      return null;
    }

    var numerator = BigInt.zero;
    var denominator = BigInt.zero;

    for (final fill in fills) {
      numerator += BigInt.from(fill.price.rial) *
          BigInt.from(fill.quantity.milligrams);
      denominator += BigInt.from(fill.quantity.milligrams);
    }

    if (denominator == BigInt.zero) {
      return null;
    }

    return PricePerFineGram.fromRial((numerator ~/ denominator).toInt());
  }

  factory Order.fromJson(Map<String, dynamic> json) {
    final quantity = json.fineWeightOr('quantity_mg', FineWeight.zero);
    final filled = json.fineWeightOr('filled_mg', FineWeight.zero);

    return Order(
      id: json.requireInt('id'),
      orderCode: json.stringOr('order_code', ''),
      instrumentCode: json.stringOr('instrument', ''),
      side: OrderSideX.parse(json.stringOr('side', 'BUY')),
      type: OrderTypeX.parse(json.stringOr('type', 'LIMIT')),
      timeInForce: json.stringOr('time_in_force', 'DAY'),
      quantity: quantity,
      filled: filled,
      // Derive rather than trust, when the server omits it: remaining is what
      // a cancel releases, and an inconsistent value would misstate that.
      remaining: json.fineWeightOrNull('remaining_mg') ?? (quantity - filled),
      price: json.priceOrNull('price_rial'),
      status: json.stringOr('status', OrderStatus.open),
      reservedRial: json.rialOrNull('reserved_rial'),
      placedAt: json.dateTimeOrNull('placed_at') ?? DateTime.now().toUtc(),
      expiresAt: json.dateTimeOrNull('expires_at'),
      fills: json.listOf('fills', Fill.fromJson),
      rejectionReason: json.stringOrNull('rejection_reason'),
    );
  }

  Order copyWith({
    String? status,
    FineWeight? filled,
    FineWeight? remaining,
    List<Fill>? fills,
  }) =>
      Order(
        id: id,
        orderCode: orderCode,
        instrumentCode: instrumentCode,
        side: side,
        type: type,
        timeInForce: timeInForce,
        quantity: quantity,
        filled: filled ?? this.filled,
        remaining: remaining ?? this.remaining,
        price: price,
        status: status ?? this.status,
        reservedRial: reservedRial,
        placedAt: placedAt,
        expiresAt: expiresAt,
        fills: fills ?? this.fills,
        rejectionReason: rejectionReason,
      );

  /// Apply an `order.updated` realtime event (§3.3).
  ///
  /// Only the fields the event actually carries are touched; a partial update
  /// must not blank out the price or the reservation.
  Order applyRealtimeUpdate(Map<String, dynamic> data) {
    final lastFill = data.mapOrNull('last_fill');

    return copyWith(
      status: data.stringOrNull('status'),
      filled: data.fineWeightOrNull('filled_mg'),
      remaining: data.fineWeightOrNull('remaining_mg'),
      fills: lastFill == null
          ? null
          : <Fill>[...fills, Fill.fromJson(lastFill)],
    );
  }
}

/// The body of `POST /orders`.
///
/// Deliberately carries value objects rather than ints, so it is impossible to
/// build one with a gross weight where a fine weight belongs, or a price in
/// the quantity field. [toJson] is the single place the conversion to the
/// wire's integers happens.
final class PlaceOrderRequest {
  const PlaceOrderRequest({
    required this.instrumentCode,
    required this.side,
    required this.type,
    required this.quantity,
    required this.timeInForce,
    this.price,
    this.maxSlippageBps,
  });

  final String instrumentCode;
  final OrderSide side;
  final OrderType type;
  final FineWeight quantity;
  final String timeInForce;

  /// Required for LIMIT, must be absent for MARKET.
  final PricePerFineGram? price;

  final int? maxSlippageBps;

  Map<String, dynamic> toJson() => <String, dynamic>{
        'instrument': instrumentCode,
        'side': side.wire,
        'type': type.wire,
        'time_in_force': timeInForce,
        'quantity_mg': quantity.milligrams,
        if (price != null) 'price_rial': price!.rial,
        if (maxSlippageBps != null) 'max_slippage_bps': maxSlippageBps,
      };
}
