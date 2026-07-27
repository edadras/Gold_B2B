import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/storage/secure_storage.dart';
import 'package:gold_b2b/features/settlements/data/models/netting_batch.dart';
import 'package:gold_b2b/features/settlements/data/models/settlement.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/rial.dart';

/// Settlement and netting (docs/05-api/02-endpoints.md §2.8 and §2.9).
class SettlementRepository {
  SettlementRepository({
    required ApiClient api,
    required SecureStorage storage,
  })  : _api = api,
        _storage = storage;

  final ApiClient _api;
  final SecureStorage _storage;

  /// `GET /settlements/pending` — the "نیاز به اقدام" queue.
  ///
  /// Cached read-only, like the balance: knowing you owe somebody twenty
  /// billion rial by five o'clock is exactly the thing you want to be able to
  /// see on a bad connection. Acting on it still requires a live connection.
  Future<List<Settlement>> fetchPending() async {
    try {
      final page = await _api.getList('/settlements/pending', Settlement.fromJson);
      await _storage.writeCache(
        CacheKeys.pendingSettlements,
        <String, dynamic>{'count': page.items.length},
      );

      return page.items;
    } on NetworkFailure {
      rethrow;
    }
  }

  Future<Paginated<Settlement>> fetchSettlements({
    List<String>? statuses,
    String? cursor,
    int limit = Ui.defaultPageSize,
  }) =>
      _api.getList(
        '/settlements',
        Settlement.fromJson,
        query: <String, dynamic>{
          'limit': limit,
          if (cursor != null) 'cursor': cursor,
          if (statuses != null && statuses.isNotEmpty)
            'filter[status]': statuses.join(','),
          'sort': 'deadline_at',
        },
      );

  Future<Settlement> fetchSettlement(int id) =>
      _api.getOne('/settlements/$id', Settlement.fromJson);

  Future<List<SettlementEvent>> fetchEvents(int id) async {
    final page =
        await _api.getList('/settlements/$id/events', SettlementEvent.fromJson);

    return page.items;
  }

  /// `POST /settlements/{id}/declare-payment` — 🔑.
  ///
  /// The screen warns, in the words of §2.5, that declaring a payment makes
  /// the declarer responsible for its accuracy and that a false declaration
  /// opens a dispute and costs reputation. That is why this call is behind a
  /// confirmation sheet and a second factor even though the endpoint is not
  /// marked ✍️.
  Future<Settlement> declarePayment({
    required int settlementId,
    required String paymentReference,
    required Rial amount,
    required DateTime paidAt,
    required IdempotencyKey idempotencyKey,
    int? receiptDocumentId,
    String? note,
  }) =>
      _api.postOne(
        '/settlements/$settlementId/declare-payment',
        Settlement.fromJson,
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          'payment_reference': paymentReference,
          'amount_rial': amount.amount,
          'paid_at': paidAt.toUtc().toIso8601String(),
          if (receiptDocumentId != null)
            'receipt_document_id': receiptDocumentId,
          if (note != null && note.isNotEmpty) 'note': note,
        },
      );

  /// `POST /settlements/{id}/confirm-payment` — 🔑 ✍️.
  ///
  /// Confirming receipt releases the gold. It is irreversible in the normal
  /// flow (only a dispute can undo it), which is why the endpoint is marked
  /// ✍️ and [totpCode] is forwarded when the second factor was a TOTP rather
  /// than biometrics.
  Future<Settlement> confirmPayment({
    required int settlementId,
    required IdempotencyKey idempotencyKey,
    String? totpCode,
    String? note,
  }) =>
      _api.postOne(
        '/settlements/$settlementId/confirm-payment',
        Settlement.fromJson,
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          // TODO(backend): the field name for the transaction-signing code is
          // not specified anywhere in docs/05-api. `transaction_code` in the
          // body is the client's assumption; if the server expects a header
          // (e.g. `X-Transaction-Sign`) this must change in ONE place — here.
          if (totpCode != null) 'transaction_code': totpCode,
          if (note != null && note.isNotEmpty) 'note': note,
        },
      );

  Future<Settlement> confirmDelivery({
    required int settlementId,
    required IdempotencyKey idempotencyKey,
    String? totpCode,
  }) =>
      _api.postOne(
        '/settlements/$settlementId/confirm-delivery',
        Settlement.fromJson,
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          if (totpCode != null) 'transaction_code': totpCode,
        },
      );

  Future<void> requestCancellation({
    required int settlementId,
    required IdempotencyKey idempotencyKey,
    required String reason,
  }) =>
      _api.postVoid(
        '/settlements/$settlementId/cancel',
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{'reason': reason},
      );

  /// Upload a payment receipt image and return the document id to attach to
  /// the declaration.
  ///
  /// TODO(backend): §2.2 has `POST /organization/documents` for uploads but
  /// does not describe a settlement-scoped receipt endpoint. This uses the
  /// organisation document endpoint with a `purpose` discriminator, which is
  /// the least surprising fit; confirm before release.
  Future<int> uploadReceipt({
    required int settlementId,
    required String filePath,
    void Function(int sent, int total)? onProgress,
  }) async {
    final form = FormData.fromMap(<String, dynamic>{
      'purpose': 'SETTLEMENT_RECEIPT',
      'settlement_id': settlementId,
      'file': await MultipartFile.fromFile(filePath),
    });

    return _api.upload(
      '/organization/documents',
      (json) => (json['id'] as num).toInt(),
      form: form,
      onProgress: onProgress,
    );
  }

  // --- netting (§2.9) ------------------------------------------------------

  Future<List<NettingBatch>> fetchNettingBatches() async {
    final page =
        await _api.getList('/netting-batches', NettingBatch.fromJson);

    return page.items;
  }

  Future<NettingBatch> fetchNettingBatch(int id) =>
      _api.getOne('/netting-batches/$id', NettingBatch.fromJson);

  /// `POST /netting-batches/{id}/accept` — 🔑 ✍️.
  Future<void> acceptNetting({
    required int batchId,
    required IdempotencyKey idempotencyKey,
    String? totpCode,
  }) =>
      _api.postVoid(
        '/netting-batches/$batchId/accept',
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          if (totpCode != null) 'transaction_code': totpCode,
        },
      );

  Future<void> rejectNetting({
    required int batchId,
    required IdempotencyKey idempotencyKey,
    String? reason,
  }) =>
      _api.postVoid(
        '/netting-batches/$batchId/reject',
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          if (reason != null && reason.isNotEmpty) 'reason': reason,
        },
      );
}

final settlementRepositoryProvider = Provider<SettlementRepository>(
  (ref) => SettlementRepository(
    api: ref.watch(apiClientProvider),
    storage: ref.watch(secureStorageProvider),
  ),
);
