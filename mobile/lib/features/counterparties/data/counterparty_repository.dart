import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/features/counterparties/data/models/reputation.dart';
import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/rial.dart';

/// A trading relationship.
///
/// TODO(backend): §2.11 documents the endpoints but not the resource. Field
/// names below are inferred; `net_balance_rial` is signed (positive = they owe
/// us) and `net_balance_gold_mg` likewise.
final class Counterparty {
  const Counterparty({
    required this.organizationId,
    required this.displayName,
    required this.verificationTier,
    required this.isTrusted,
    required this.isBlocked,
    this.reputation,
    this.netBalanceRial,
    this.netBalanceGoldMg,
    this.openSettlementCount = 0,
    this.creditLimitRial,
    this.creditUsedRial,
    this.lastTradedAt,
  });

  final int organizationId;
  final String displayName;
  final String verificationTier;

  /// Trusted counterparties can be offered OTC deals without extra approval.
  final bool isTrusted;

  final bool isBlocked;

  final ReputationSummary? reputation;

  /// Signed. Positive means they owe us.
  final Rial? netBalanceRial;
  final int? netBalanceGoldMg;

  final int openSettlementCount;
  final Rial? creditLimitRial;
  final Rial? creditUsedRial;
  final DateTime? lastTradedAt;

  FineWeight? get netBalanceGold {
    final value = netBalanceGoldMg;

    return value == null ? null : FineWeight.fromMilligrams(value.abs());
  }

  bool get goldBalanceFavoursUs => (netBalanceGoldMg ?? 0) > 0;

  /// F20-adjacent: how much of the credit line is consumed, in basis points.
  int? get creditUsageBps {
    final limit = creditLimitRial;
    final used = creditUsedRial;
    if (limit == null || used == null || limit.isZero) {
      return null;
    }

    return (BigInt.from(used.amount) * BigInt.from(10000) ~/
            BigInt.from(limit.amount))
        .toInt();
  }

  factory Counterparty.fromJson(Map<String, dynamic> json) {
    final reputation = json.mapOrNull('reputation');

    return Counterparty(
      organizationId:
          json.intOrNull('organization_id') ?? json.requireInt('id'),
      displayName: json.stringOr('display_name', ''),
      verificationTier: json.stringOr('verification_tier', 'BRONZE'),
      isTrusted: json.boolOr('is_trusted', fallback: false),
      isBlocked: json.boolOr('is_blocked', fallback: false),
      reputation: reputation == null
          ? null
          : ReputationSummary.fromJson(reputation),
      netBalanceRial: json.rialOrNull('net_balance_rial'),
      netBalanceGoldMg: json.intOrNull('net_balance_gold_mg'),
      openSettlementCount: json.intOr('open_settlement_count', 0),
      creditLimitRial: json.rialOrNull('credit_limit_rial'),
      creditUsedRial: json.rialOrNull('credit_used_rial'),
      lastTradedAt: json.dateTimeOrNull('last_traded_at'),
    );
  }
}

/// Counterparties and member search (docs/05-api/02-endpoints.md §2.11).
class CounterpartyRepository {
  CounterpartyRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Paginated<Counterparty>> fetchAll({String? cursor}) => _api.getList(
        '/counterparties',
        Counterparty.fromJson,
        query: <String, dynamic>{if (cursor != null) 'cursor': cursor},
      );

  Future<Counterparty> fetchOne(int organizationId) =>
      _api.getOne('/counterparties/$organizationId', Counterparty.fromJson);

  Future<ReputationSummary> fetchReputation(int organizationId) => _api.getOne(
        '/members/$organizationId/reputation',
        ReputationSummary.fromJson,
      );

  /// Member search, for choosing an OTC or RFQ recipient.
  Future<List<Counterparty>> searchMembers(String query) async {
    if (query.trim().length < 2) {
      return const <Counterparty>[];
    }

    final page = await _api.getList(
      '/members/search',
      Counterparty.fromJson,
      query: <String, dynamic>{'q': query.trim()},
    );

    return page.items;
  }

  Future<void> updateSettings({
    required int organizationId,
    bool? isTrusted,
    bool? isBlocked,
  }) =>
      _api.putOne(
        '/counterparties/$organizationId/settings',
        (json) => json,
        body: <String, dynamic>{
          if (isTrusted != null) 'is_trusted': isTrusted,
          if (isBlocked != null) 'is_blocked': isBlocked,
        },
      );
}

final counterpartyRepositoryProvider = Provider<CounterpartyRepository>(
  (ref) => CounterpartyRepository(api: ref.watch(apiClientProvider)),
);

final counterpartiesProvider = FutureProvider<Paginated<Counterparty>>(
  (ref) => ref.watch(counterpartyRepositoryProvider).fetchAll(),
);

final counterpartyProvider = FutureProvider.family<Counterparty, int>(
  (ref, id) => ref.watch(counterpartyRepositoryProvider).fetchOne(id),
);

final memberSearchProvider =
    FutureProvider.family<List<Counterparty>, String>(
  (ref, query) =>
      ref.watch(counterpartyRepositoryProvider).searchMembers(query),
);
