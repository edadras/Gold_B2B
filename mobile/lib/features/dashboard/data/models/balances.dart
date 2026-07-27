import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/rial.dart';

/// Gold balance, split by bucket.
///
/// The buckets are the ledger's, not a UI invention:
/// docs/03-domain/03-ledger.md. `available` is the only one you can trade
/// against; the rest is committed. Showing only the total — which is what a
/// consumer app would do — is how a trader ends up placing an order against
/// gold that is already promised to somebody else.
final class GoldBalance {
  const GoldBalance({
    required this.available,
    required this.reserved,
    required this.inSettlement,
    required this.inDispute,
    required this.total,
    required this.marketValue,
  });

  /// Free to trade.
  final FineWeight available;

  /// Locked against open sell orders.
  final FineWeight reserved;

  /// Committed to a settlement that has not completed.
  final FineWeight inSettlement;

  /// Frozen by an open dispute.
  final FineWeight inDispute;

  final FineWeight total;

  /// Value of [total] at the current reference price. Indicative only — the
  /// server computed it at `as_of`, and the price has moved since.
  final Rial marketValue;

  bool get hasCommitments =>
      !reserved.isZero || !inSettlement.isZero || !inDispute.isZero;

  factory GoldBalance.fromJson(Map<String, dynamic> json) => GoldBalance(
        available: json.fineWeightOr('available_mg', FineWeight.zero),
        reserved: json.fineWeightOr('reserved_mg', FineWeight.zero),
        inSettlement: json.fineWeightOr('in_settlement_mg', FineWeight.zero),
        inDispute: json.fineWeightOr('in_dispute_mg', FineWeight.zero),
        total: json.fineWeightOr('total_mg', FineWeight.zero),
        marketValue: json.rialOr('market_value_rial', Rial.zero),
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'available_mg': available.milligrams,
        'reserved_mg': reserved.milligrams,
        'in_settlement_mg': inSettlement.milligrams,
        'in_dispute_mg': inDispute.milligrams,
        'total_mg': total.milligrams,
        'market_value_rial': marketValue.amount,
      };

  GoldBalance copyWith({
    FineWeight? available,
    FineWeight? reserved,
    FineWeight? inSettlement,
    FineWeight? inDispute,
    FineWeight? total,
    Rial? marketValue,
  }) =>
      GoldBalance(
        available: available ?? this.available,
        reserved: reserved ?? this.reserved,
        inSettlement: inSettlement ?? this.inSettlement,
        inDispute: inDispute ?? this.inDispute,
        total: total ?? this.total,
        marketValue: marketValue ?? this.marketValue,
      );
}

/// Rial balance, split by the same bucket scheme.
final class RialBalance {
  const RialBalance({
    required this.available,
    required this.reserved,
    required this.inSettlement,
    required this.inDispute,
    required this.payable,
    required this.net,
  });

  final Rial available;
  final Rial reserved;
  final Rial inSettlement;
  final Rial inDispute;

  /// The only bucket that may be negative — what the organisation owes
  /// (docs/11-appendix/01-formulas.md §1.11(7)).
  final Rial payable;

  final Rial net;

  factory RialBalance.fromJson(Map<String, dynamic> json) => RialBalance(
        available: json.rialOr('available', Rial.zero),
        reserved: json.rialOr('reserved', Rial.zero),
        inSettlement: json.rialOr('in_settlement', Rial.zero),
        inDispute: json.rialOr('in_dispute', Rial.zero),
        payable: json.rialOr('payable', Rial.zero),
        net: json.rialOr('net', Rial.zero),
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'available': available.amount,
        'reserved': reserved.amount,
        'in_settlement': inSettlement.amount,
        'in_dispute': inDispute.amount,
        'payable': payable.amount,
        'net': net.amount,
      };

  RialBalance copyWith({
    Rial? available,
    Rial? reserved,
    Rial? inSettlement,
    Rial? inDispute,
    Rial? payable,
    Rial? net,
  }) =>
      RialBalance(
        available: available ?? this.available,
        reserved: reserved ?? this.reserved,
        inSettlement: inSettlement ?? this.inSettlement,
        inDispute: inDispute ?? this.inDispute,
        payable: payable ?? this.payable,
        net: net ?? this.net,
      );
}

/// `GET /balances`.
final class BalanceSnapshot {
  const BalanceSnapshot({
    required this.gold,
    required this.rial,
    required this.asOf,
    this.isFromCache = false,
  });

  final GoldBalance gold;
  final RialBalance rial;

  /// Server timestamp. Every screen that shows a balance must also show how
  /// old it is (§1.9).
  final DateTime asOf;

  /// True when this came from the encrypted local cache because the network
  /// was unreachable. Drives the greyed treatment and the staleness label.
  final bool isFromCache;

  factory BalanceSnapshot.fromJson(Map<String, dynamic> json) =>
      BalanceSnapshot(
        gold: GoldBalance.fromJson(json.mapOrNull('gold') ?? const <String, dynamic>{}),
        rial: RialBalance.fromJson(json.mapOrNull('rial') ?? const <String, dynamic>{}),
        asOf: json.dateTimeOrNull('as_of') ?? DateTime.now().toUtc(),
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'gold': gold.toJson(),
        'rial': rial.toJson(),
        'as_of': asOf.toIso8601String(),
      };

  BalanceSnapshot copyWith({
    GoldBalance? gold,
    RialBalance? rial,
    DateTime? asOf,
    bool? isFromCache,
  }) =>
      BalanceSnapshot(
        gold: gold ?? this.gold,
        rial: rial ?? this.rial,
        asOf: asOf ?? this.asOf,
        isFromCache: isFromCache ?? this.isFromCache,
      );

  /// Apply a `balance.updated` realtime event.
  ///
  /// This is an OPTIMISTIC display update and nothing more. The authoritative
  /// value is whatever `GET /balances` last returned, and every reconnect
  /// re-reads it (docs/05-api/03-realtime-webhooks.md §3.4). A partial event
  /// that names only the changed buckets leaves the others alone.
  BalanceSnapshot applyRealtimeUpdate(Map<String, dynamic> data) {
    final assetType = data.stringOr('asset_type', '');

    if (assetType == 'GOLD') {
      return copyWith(
        gold: gold.copyWith(
          available: data.fineWeightOrNull('available_mg'),
          reserved: data.fineWeightOrNull('reserved_mg'),
          inSettlement: data.fineWeightOrNull('in_settlement_mg'),
          inDispute: data.fineWeightOrNull('in_dispute_mg'),
          total: data.fineWeightOrNull('total_mg'),
        ),
        asOf: DateTime.now().toUtc(),
        isFromCache: false,
      );
    }

    if (assetType == 'RIAL') {
      return copyWith(
        rial: rial.copyWith(
          available: data.rialOrNull('available'),
          reserved: data.rialOrNull('reserved'),
          inSettlement: data.rialOrNull('in_settlement'),
          inDispute: data.rialOrNull('in_dispute'),
          payable: data.rialOrNull('payable'),
          net: data.rialOrNull('net'),
        ),
        asOf: DateTime.now().toUtc(),
        isFromCache: false,
      );
    }

    return this;
  }
}
