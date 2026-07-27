import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/int_math.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';

/// Top of book for one instrument.
///
/// Arrives both from `GET /market/quotes/{code}` and from the `quote.updated`
/// realtime event, which carry the same field names — so one parser serves
/// both and there is no chance of the two paths disagreeing.
final class Quote {
  const Quote({
    required this.instrumentCode,
    required this.bestBid,
    required this.bestAsk,
    required this.lastPrice,
    required this.dayChangeBps,
    required this.timestamp,
    this.bestBidQuantity = FineWeight.zero,
    this.bestAskQuantity = FineWeight.zero,
  });

  final String instrumentCode;

  /// Null when there is no resting order on that side.
  final PricePerFineGram? bestBid;
  final PricePerFineGram? bestAsk;
  final PricePerFineGram? lastPrice;

  final FineWeight bestBidQuantity;
  final FineWeight bestAskQuantity;

  /// Change since the session open, in basis points. Positive is up.
  final int dayChangeBps;

  final DateTime timestamp;

  bool get hasTwoSidedMarket => bestBid != null && bestAsk != null;

  /// F23 — `spread = best_ask − best_bid`.
  PricePerFineGram? get spread {
    final bid = bestBid;
    final ask = bestAsk;
    if (bid == null || ask == null || ask < bid) {
      return null;
    }

    return PricePerFineGram.fromRial(ask.rial - bid.rial);
  }

  /// F23 — `mid = floor((best_ask + best_bid) / 2)`.
  PricePerFineGram? get midPrice {
    final bid = bestBid;
    final ask = bestAsk;
    if (bid == null || ask == null) {
      return null;
    }

    return PricePerFineGram.fromRial((bid.rial + ask.rial) ~/ 2);
  }

  /// F23 — `spread_bps = floor(spread * 10000 / mid)`.
  int? get spreadBps {
    final spreadValue = spread;
    final mid = midPrice;
    if (spreadValue == null || mid == null || mid.isZero) {
      return null;
    }

    return IntMath.mulDivFloor(
      spreadValue.rial,
      PricePerFineGram.bpsScale,
      mid.rial,
    );
  }

  /// The reference the order form uses for the fat-finger check: the mid when
  /// there is a two-sided market, otherwise the last traded price.
  PricePerFineGram? get referencePrice => midPrice ?? lastPrice;

  factory Quote.fromJson(Map<String, dynamic> json) => Quote(
        instrumentCode: json.stringOr('instrument', ''),
        bestBid: json.priceOrNull('best_bid'),
        bestAsk: json.priceOrNull('best_ask'),
        lastPrice: json.priceOrNull('last_price'),
        bestBidQuantity:
            json.fineWeightOr('best_bid_qty_mg', FineWeight.zero),
        bestAskQuantity:
            json.fineWeightOr('best_ask_qty_mg', FineWeight.zero),
        dayChangeBps: json.intOr('day_change_bps', 0),
        timestamp: json.dateTimeOrNull('timestamp') ??
            json.dateTimeOrNull('as_of') ??
            DateTime.now().toUtc(),
      );
}

/// One price level of the depth ladder.
final class DepthLevel {
  const DepthLevel({
    required this.price,
    required this.quantity,
    required this.orderCount,
  });

  final PricePerFineGram price;
  final FineWeight quantity;
  final int orderCount;

  factory DepthLevel.fromJson(Map<String, dynamic> json) => DepthLevel(
        price: json.price('price_rial'),
        quantity: json.fineWeight('quantity_mg'),
        orderCount: json.intOr('order_count', 0),
      );

  /// The realtime `depth.updated` event sends levels as positional arrays
  /// `[price, quantity, order_count]` rather than objects, to keep the frames
  /// small (§3.5).
  static DepthLevel? fromArray(Object? raw) {
    if (raw is! List || raw.length < 2) {
      return null;
    }

    final price = raw[0];
    final quantity = raw[1];
    if (price is! num || quantity is! num) {
      return null;
    }

    return DepthLevel(
      price: PricePerFineGram.fromRial(price.toInt()),
      quantity: FineWeight.fromMilligrams(quantity.toInt()),
      orderCount: raw.length > 2 && raw[2] is num
          ? (raw[2] as num).toInt()
          : 0,
    );
  }
}

/// `GET /market/depth/{code}` and the `depth.updated` event.
final class MarketDepth {
  const MarketDepth({
    required this.instrumentCode,
    required this.bids,
    required this.asks,
    required this.timestamp,
  });

  final String instrumentCode;

  /// Highest price first.
  final List<DepthLevel> bids;

  /// Lowest price first.
  final List<DepthLevel> asks;

  final DateTime timestamp;

  /// An empty book. Not a `const` field because [timestamp] is a `DateTime`,
  /// and an empty book that claims to be from app-launch time would show a
  /// misleading "as of" in the ladder header.
  factory MarketDepth.emptyFor(String instrumentCode) => MarketDepth(
        instrumentCode: instrumentCode,
        bids: const <DepthLevel>[],
        asks: const <DepthLevel>[],
        timestamp: DateTime.now().toUtc(),
      );

  bool get isEmpty => bids.isEmpty && asks.isEmpty;

  /// Largest quantity at any single level, for scaling the depth bars.
  FineWeight get maxLevelQuantity {
    var largest = FineWeight.zero;
    for (final level in <DepthLevel>[...bids, ...asks]) {
      if (level.quantity > largest) {
        largest = level.quantity;
      }
    }

    return largest;
  }

  factory MarketDepth.fromJson(Map<String, dynamic> json) => MarketDepth(
        instrumentCode: json.stringOr('instrument', ''),
        bids: json.listOf('bids', DepthLevel.fromJson),
        asks: json.listOf('asks', DepthLevel.fromJson),
        timestamp: json.dateTimeOrNull('as_of') ??
            json.dateTimeOrNull('timestamp') ??
            DateTime.now().toUtc(),
      );

  /// Parser for the compact realtime form.
  factory MarketDepth.fromRealtime(Map<String, dynamic> json) {
    List<DepthLevel> parseSide(String key) {
      final raw = json[key];
      if (raw is! List) {
        return const <DepthLevel>[];
      }

      return raw
          .map(DepthLevel.fromArray)
          .whereType<DepthLevel>()
          .toList(growable: false);
    }

    return MarketDepth(
      instrumentCode: json.stringOr('instrument', ''),
      bids: parseSide('bids'),
      asks: parseSide('asks'),
      timestamp: json.dateTimeOrNull('timestamp') ?? DateTime.now().toUtc(),
    );
  }
}

/// One print on the trade tape. Counterparty identity is deliberately absent:
/// the public market channel never carries anything that could identify a
/// participant (§3.3).
final class PublicTrade {
  const PublicTrade({
    required this.price,
    required this.quantity,
    required this.takerSide,
    required this.executedAt,
  });

  final PricePerFineGram price;
  final FineWeight quantity;

  /// `BUY` when the aggressor bought.
  final String takerSide;

  final DateTime executedAt;

  bool get takerBought => takerSide.toUpperCase() == 'BUY';

  factory PublicTrade.fromJson(Map<String, dynamic> json) => PublicTrade(
        price: json.price('price_rial'),
        quantity: json.fineWeight('quantity_mg'),
        takerSide: json.stringOr('taker_side', 'BUY'),
        executedAt:
            json.dateTimeOrNull('executed_at') ?? DateTime.now().toUtc(),
      );
}
