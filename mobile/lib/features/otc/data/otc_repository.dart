import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/features/settlements/data/models/settlement.dart';
import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';

/// docs/11-appendix/02-state-machines.md §2.7.
final class OtcOfferStatus {
  const OtcOfferStatus._();

  static const String pending = 'PENDING';
  static const String countered = 'COUNTERED';
  static const String accepted = 'ACCEPTED';
  static const String rejected = 'REJECTED';
  static const String cancelled = 'CANCELLED';
  static const String expired = 'EXPIRED';

  static String label(String status) => switch (status) {
        pending => 'در انتظار پاسخ',
        countered => 'پیشنهاد متقابل',
        accepted => 'پذیرفته شده',
        rejected => 'رد شده',
        cancelled => 'لغو شده',
        expired => 'منقضی',
        _ => status,
      };

  static StatusTone tone(String status) => switch (status) {
        accepted => StatusTone.positive,
        pending || countered => StatusTone.warning,
        rejected || cancelled || expired => StatusTone.neutral,
        _ => StatusTone.neutral,
      };
}

/// A bilateral offer.
///
/// TODO(backend): §2.6 documents the request body of `POST /otc-offers` but
/// not the resource returned. The fields below mirror the request plus the
/// obvious additions (id, code, status, direction, counterparty, expiry). The
/// `direction` field — whether this offer was received or sent — is the
/// client's invention; if the API does not provide it, it can be derived by
/// comparing `counterparty.id` with the current organisation id, and
/// [OtcOffer.fromJson] falls back to exactly that when the field is missing.
final class OtcOffer {
  const OtcOffer({
    required this.id,
    required this.offerCode,
    required this.status,
    required this.side,
    required this.quantity,
    required this.price,
    required this.isIncoming,
    this.instrumentCode,
    this.counterparty,
    this.settlementType,
    this.deliveryType,
    this.expiresAt,
    this.note,
    this.counterCount = 0,
  });

  final int id;
  final String offerCode;
  final String status;

  /// The side FROM THE OFFERER'S point of view, as the API models it.
  final String side;

  final FineWeight quantity;
  final PricePerFineGram price;

  /// True when this organisation received the offer and must respond.
  final bool isIncoming;

  final String? instrumentCode;
  final CounterpartyRef? counterparty;
  final String? settlementType;
  final String? deliveryType;
  final DateTime? expiresAt;
  final String? note;

  /// The state machine caps counters at five.
  final int counterCount;

  bool get isActionable =>
      isIncoming &&
      (status == OtcOfferStatus.pending || status == OtcOfferStatus.countered);

  bool get canCounter => isActionable && counterCount < 5;

  /// F5 — the gross rial value of this offer, so the list can show a total
  /// without a second round trip.
  Rial get grossAmount => price.valueOf(quantity);

  String get sideLabel => side.toUpperCase() == 'BUY' ? 'خرید' : 'فروش';

  factory OtcOffer.fromJson(Map<String, dynamic> json) {
    final counterparty = json.mapOrNull('counterparty');

    return OtcOffer(
      id: json.requireInt('id'),
      offerCode: json.stringOr('offer_code', ''),
      status: json.stringOr('status', OtcOfferStatus.pending),
      side: json.stringOr('side', 'BUY'),
      quantity: json.fineWeightOr('quantity_mg', FineWeight.zero),
      price: json.priceOrNull('price_rial') ?? PricePerFineGram.zero,
      isIncoming: json.boolOr('is_incoming', fallback: true),
      instrumentCode: json.stringOrNull('instrument'),
      counterparty:
          counterparty == null ? null : CounterpartyRef.fromJson(counterparty),
      settlementType: json.stringOrNull('settlement_type'),
      deliveryType: json.stringOrNull('delivery_type'),
      expiresAt: json.dateTimeOrNull('expires_at'),
      note: json.stringOrNull('note'),
      counterCount: json.intOr('counter_count', 0),
    );
  }
}

/// OTC offers (docs/05-api/02-endpoints.md §2.6).
class OtcRepository {
  OtcRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Paginated<OtcOffer>> fetchOffers({
    bool? incoming,
    String? cursor,
  }) =>
      _api.getList(
        '/otc-offers',
        OtcOffer.fromJson,
        query: <String, dynamic>{
          if (incoming != null)
            'filter[direction]': incoming ? 'INCOMING' : 'OUTGOING',
          if (cursor != null) 'cursor': cursor,
          'sort': '-created_at',
        },
      );

  Future<OtcOffer> fetchOffer(int id) =>
      _api.getOne('/otc-offers/$id', OtcOffer.fromJson);

  Future<OtcOffer> create({
    required int counterpartyOrganizationId,
    required String instrumentCode,
    required String side,
    required FineWeight quantity,
    required PricePerFineGram price,
    required String settlementType,
    required String deliveryType,
    required int expiresInMinutes,
    required IdempotencyKey idempotencyKey,
    String? note,
  }) =>
      _api.postOne(
        '/otc-offers',
        OtcOffer.fromJson,
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          'counterparty_organization_id': counterpartyOrganizationId,
          'instrument': instrumentCode,
          'side': side,
          'quantity_mg': quantity.milligrams,
          'price_rial': price.rial,
          'settlement_type': settlementType,
          'delivery_type': deliveryType,
          'expires_in_minutes': expiresInMinutes,
          if (note != null && note.isNotEmpty) 'note': note,
        },
      );

  /// `POST /otc-offers/{id}/accept` — 🔑.
  ///
  /// Accepting creates a trade and a settlement obligation immediately, which
  /// is why the caller puts it behind the confirmation sheet.
  Future<OtcOffer> accept({
    required int offerId,
    required IdempotencyKey idempotencyKey,
  }) =>
      _api.postOne(
        '/otc-offers/$offerId/accept',
        OtcOffer.fromJson,
        idempotencyKey: idempotencyKey,
      );

  Future<OtcOffer> counter({
    required int offerId,
    required PricePerFineGram price,
    required FineWeight quantity,
    required IdempotencyKey idempotencyKey,
    String? note,
  }) =>
      _api.postOne(
        '/otc-offers/$offerId/counter',
        OtcOffer.fromJson,
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          'price_rial': price.rial,
          'quantity_mg': quantity.milligrams,
          if (note != null && note.isNotEmpty) 'note': note,
        },
      );

  Future<void> reject(int offerId) =>
      _api.postVoid('/otc-offers/$offerId/reject');

  Future<void> cancel(int offerId) =>
      _api.postVoid('/otc-offers/$offerId/cancel');
}

final otcRepositoryProvider = Provider<OtcRepository>(
  (ref) => OtcRepository(api: ref.watch(apiClientProvider)),
);
