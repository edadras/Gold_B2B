import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/features/counterparties/data/models/reputation.dart';
import 'package:gold_b2b/features/settlements/data/models/settlement.dart';
import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';

/// docs/11-appendix/02-state-machines.md §2.6.
final class RfqStatus {
  const RfqStatus._();

  static const String open = 'OPEN';
  static const String quoted = 'QUOTED';
  static const String partiallyAccepted = 'PARTIALLY_ACCEPTED';
  static const String accepted = 'ACCEPTED';
  static const String cancelled = 'CANCELLED';
  static const String expired = 'EXPIRED';

  static String label(String status) => switch (status) {
        open => 'باز',
        quoted => 'دارای پیشنهاد',
        partiallyAccepted => 'پذیرش جزئی',
        accepted => 'پذیرفته شده',
        cancelled => 'لغو شده',
        expired => 'منقضی',
        _ => status,
      };

  static StatusTone tone(String status) => switch (status) {
        accepted => StatusTone.positive,
        open || quoted || partiallyAccepted => StatusTone.warning,
        _ => StatusTone.neutral,
      };
}

/// A request for quote.
///
/// TODO(backend): §2.7 documents the `POST /rfqs` request body but not the
/// resource. Field names below follow the request plus the §2.6 mockup.
final class Rfq {
  const Rfq({
    required this.id,
    required this.rfqCode,
    required this.status,
    required this.side,
    required this.quantity,
    required this.allowPartial,
    required this.quoteCount,
    this.instrumentCode,
    this.minPurity,
    this.settlementType,
    this.deliveryType,
    this.recipientCount,
    this.expiresAt,
    this.isIncoming = false,
  });

  final int id;
  final String rfqCode;
  final String status;
  final String side;
  final FineWeight quantity;
  final bool allowPartial;
  final int quoteCount;

  final String? instrumentCode;
  final Purity? minPurity;
  final String? settlementType;
  final String? deliveryType;
  final int? recipientCount;
  final DateTime? expiresAt;

  /// True for the inbox: somebody else's RFQ that this organisation may quote.
  final bool isIncoming;

  bool get isOpen => status == RfqStatus.open || status == RfqStatus.quoted;

  String get sideLabel => side.toUpperCase() == 'BUY' ? 'خرید' : 'فروش';

  factory Rfq.fromJson(Map<String, dynamic> json) => Rfq(
        id: json.requireInt('id'),
        rfqCode: json.stringOr('rfq_code', ''),
        status: json.stringOr('status', RfqStatus.open),
        side: json.stringOr('side', 'BUY'),
        quantity: json.fineWeightOr('quantity_mg', FineWeight.zero),
        allowPartial: json.boolOr('allow_partial', fallback: false),
        quoteCount: json.intOr('quote_count', 0),
        instrumentCode: json.stringOrNull('instrument'),
        minPurity: json.purityOrNull('min_purity_x10'),
        settlementType: json.stringOrNull('settlement_type'),
        deliveryType: json.stringOrNull('delivery_type'),
        recipientCount: json.intOrNull('recipient_count'),
        expiresAt: json.dateTimeOrNull('expires_at'),
        isIncoming: json.boolOr('is_incoming', fallback: false),
      );
}

/// One dealer's answer to an RFQ.
///
/// The reputation block is the reason this screen exists at all: §2.6 shows
/// the cheapest quote NOT at the top, with a warning that the dealer's dispute
/// rate is above market average. Price alone is not the decision.
final class RfqQuote {
  const RfqQuote({
    required this.id,
    required this.status,
    required this.quantity,
    required this.price,
    this.counterparty,
    this.reputation,
    this.expiresAt,
    this.note,
  });

  final int id;

  /// `PENDING`, `ACCEPTED`, `REJECTED`, `WITHDRAWN`, `EXPIRED`.
  final String status;

  final FineWeight quantity;
  final PricePerFineGram price;
  final CounterpartyRef? counterparty;
  final ReputationSummary? reputation;
  final DateTime? expiresAt;
  final String? note;

  bool get isPending => status == 'PENDING';

  /// F5 — total value of this quote.
  Rial get total => price.valueOf(quantity);

  /// True when the quote covers less than the requested quantity.
  bool isPartialFor(FineWeight requested) => quantity < requested;

  /// Percentage of the requested quantity this quote covers, in basis points.
  int coverageBpsOf(FineWeight requested) {
    if (requested.isZero) {
      return 0;
    }

    return (BigInt.from(quantity.milligrams) * BigInt.from(10000) ~/
            BigInt.from(requested.milligrams))
        .toInt();
  }

  factory RfqQuote.fromJson(Map<String, dynamic> json) {
    final counterparty = json.mapOrNull('counterparty');
    final reputation = json.mapOrNull('reputation');

    return RfqQuote(
      id: json.requireInt('id'),
      status: json.stringOr('status', 'PENDING'),
      quantity: json.fineWeightOr('quantity_mg', FineWeight.zero),
      price: json.priceOrNull('price_rial') ?? PricePerFineGram.zero,
      counterparty:
          counterparty == null ? null : CounterpartyRef.fromJson(counterparty),
      reputation: reputation == null
          ? null
          : ReputationSummary.fromJson(reputation),
      expiresAt: json.dateTimeOrNull('expires_at'),
      note: json.stringOrNull('note'),
    );
  }
}

/// RFQ (docs/05-api/02-endpoints.md §2.7).
class RfqRepository {
  RfqRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Paginated<Rfq>> fetchMine({String? cursor}) => _api.getList(
        '/rfqs',
        Rfq.fromJson,
        query: <String, dynamic>{
          if (cursor != null) 'cursor': cursor,
          'sort': '-created_at',
        },
      );

  Future<Paginated<Rfq>> fetchInbox({String? cursor}) => _api.getList(
        '/rfqs/inbox',
        Rfq.fromJson,
        query: <String, dynamic>{if (cursor != null) 'cursor': cursor},
      );

  Future<Rfq> fetchRfq(int id) => _api.getOne('/rfqs/$id', Rfq.fromJson);

  Future<List<RfqQuote>> fetchQuotes(int rfqId) async {
    final page = await _api.getList('/rfqs/$rfqId/quotes', RfqQuote.fromJson);

    return page.items;
  }

  Future<Rfq> create({
    required String instrumentCode,
    required String side,
    required FineWeight quantity,
    required String settlementType,
    required String deliveryType,
    required String visibility,
    required int expiresInMinutes,
    required bool allowPartial,
    required IdempotencyKey idempotencyKey,
    Purity? minPurity,
    List<int>? recipientOrganizationIds,
  }) =>
      _api.postOne(
        '/rfqs',
        Rfq.fromJson,
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          'instrument': instrumentCode,
          'side': side,
          'quantity_mg': quantity.milligrams,
          if (minPurity != null) 'min_purity_x10': minPurity.value,
          'settlement_type': settlementType,
          'delivery_type': deliveryType,
          'visibility': visibility,
          if (recipientOrganizationIds != null &&
              recipientOrganizationIds.isNotEmpty)
            'recipient_organization_ids': recipientOrganizationIds,
          'allow_partial': allowPartial,
          'expires_in_minutes': expiresInMinutes,
        },
      );

  Future<void> cancel(int rfqId) => _api.postVoid('/rfqs/$rfqId/cancel');

  /// Answer somebody else's RFQ.
  Future<RfqQuote> submitQuote({
    required int rfqId,
    required FineWeight quantity,
    required PricePerFineGram price,
    required IdempotencyKey idempotencyKey,
    String? note,
  }) =>
      _api.postOne(
        '/rfqs/$rfqId/quotes',
        RfqQuote.fromJson,
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          'quantity_mg': quantity.milligrams,
          'price_rial': price.rial,
          if (note != null && note.isNotEmpty) 'note': note,
        },
      );

  /// `POST /rfq-quotes/{id}/accept` — 🔑. Creates the trade.
  Future<void> acceptQuote({
    required int quoteId,
    required IdempotencyKey idempotencyKey,
  }) =>
      _api.postVoid(
        '/rfq-quotes/$quoteId/accept',
        idempotencyKey: idempotencyKey,
      );

  Future<void> withdrawQuote(int quoteId) =>
      _api.postVoid('/rfq-quotes/$quoteId/withdraw');
}

final rfqRepositoryProvider = Provider<RfqRepository>(
  (ref) => RfqRepository(api: ref.watch(apiClientProvider)),
);
