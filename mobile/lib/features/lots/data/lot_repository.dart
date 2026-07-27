import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';
import 'package:gold_b2b/shared/models/weight.dart';

/// docs/11-appendix/02-state-machines.md §2.5.
final class LotStatus {
  const LotStatus._();

  static const String underAssay = 'UNDER_ASSAY';
  static const String available = 'AVAILABLE';
  static const String reserved = 'RESERVED';
  static const String inSettlement = 'IN_SETTLEMENT';
  static const String inTransit = 'IN_TRANSIT';
  static const String onHold = 'ON_HOLD';
  static const String withdrawn = 'WITHDRAWN';
  static const String consumed = 'CONSUMED';

  static String label(String status) => switch (status) {
        underAssay => 'در ری‌گیری',
        available => 'آزاد',
        reserved => 'رزرو',
        inSettlement => 'در تسویه',
        inTransit => 'در حمل',
        onHold => 'متوقف',
        withdrawn => 'برداشت‌شده',
        consumed => 'مصرف‌شده',
        _ => status,
      };

  static StatusTone tone(String status) => switch (status) {
        available => StatusTone.positive,
        reserved || inSettlement || inTransit || underAssay =>
          StatusTone.warning,
        onHold => StatusTone.negative,
        _ => StatusTone.neutral,
      };
}

/// A physical gold lot.
///
/// Both weights are carried, and they are different TYPES: [grossWeight] is
/// what the scale says, [fineWeight] is `floor(gross * purity / 10000)` (F1).
/// The server sends both; the client re-derives nothing, but the type split
/// means a screen physically cannot put one where the other belongs.
final class GoldLot {
  const GoldLot({
    required this.id,
    required this.lotCode,
    required this.status,
    required this.grossWeight,
    required this.purity,
    required this.fineWeight,
    required this.isAssayCertified,
    this.serialNumber,
    this.hallmark,
    this.form,
    this.vaultName,
    this.vaultLocation,
    this.isInVault = true,
    this.marketValue,
    this.assayCertificateCode,
    this.assayLabName,
    this.assayMethod,
    this.assayedAt,
    this.reservedForReference,
    this.qrToken,
  });

  final int id;
  final String lotCode;
  final String status;

  final Weight grossWeight;
  final Purity purity;
  final FineWeight fineWeight;

  /// False means the purity is DECLARED, not certified. Such a lot cannot be
  /// traded on the market until it has been assayed
  /// (docs/07-mobile-flutter/02-screens.md §2.7).
  final bool isAssayCertified;

  final String? serialNumber;
  final String? hallmark;
  final String? form;

  final String? vaultName;
  final String? vaultLocation;

  /// False for "نزد خودم" — held by the owner rather than in a vault.
  final bool isInVault;

  final Rial? marketValue;

  final String? assayCertificateCode;
  final String? assayLabName;
  final String? assayMethod;
  final DateTime? assayedAt;

  /// e.g. `ORD-00044120` when the lot is reserved against an order.
  final String? reservedForReference;

  /// Token embedded in the lot's QR code, resolvable via `GET /verify/{token}`.
  final String? qrToken;

  bool get needsAssay => !isAssayCertified;

  bool get isTradable => status == LotStatus.available && isAssayCertified;

  factory GoldLot.fromJson(Map<String, dynamic> json) {
    final gross = json.grossWeight('gross_weight_mg');
    final purity = json.purityOrNull('purity_x10') ?? Purity.pure;

    return GoldLot(
      id: json.requireInt('id'),
      lotCode: json.stringOr('lot_code', ''),
      status: json.stringOr('status', LotStatus.available),
      grossWeight: gross,
      purity: purity,
      // Prefer the server's figure; fall back to F1 so a sparse response
      // still shows a correct fine weight rather than zero.
      fineWeight: json.fineWeightOrNull('fine_weight_mg') ??
          FineWeight.calculate(gross, purity),
      isAssayCertified: json.boolOr('is_assay_certified', fallback: false),
      serialNumber: json.stringOrNull('serial_number'),
      hallmark: json.stringOrNull('hallmark'),
      form: json.stringOrNull('form'),
      vaultName: json.stringOrNull('vault_name'),
      vaultLocation: json.stringOrNull('vault_location'),
      isInVault: json.boolOr('is_in_vault', fallback: true),
      marketValue: json.rialOrNull('market_value_rial'),
      assayCertificateCode: json.stringOrNull('assay_certificate_code'),
      assayLabName: json.stringOrNull('assay_lab_name'),
      assayMethod: json.stringOrNull('assay_method'),
      assayedAt: json.dateTimeOrNull('assayed_at'),
      reservedForReference: json.stringOrNull('reserved_for'),
      qrToken: json.stringOrNull('qr_token'),
    );
  }
}

/// One ancestor or descendant in a lot's lineage.
final class LineageNode {
  const LineageNode({
    required this.lotCode,
    required this.operation,
    required this.grossWeight,
    required this.purity,
    required this.depth,
    this.occurredAt,
  });

  final String lotCode;

  /// `SPLIT`, `MERGE`, `MELT`, …
  final String operation;

  final Weight grossWeight;
  final Purity purity;
  final int depth;
  final DateTime? occurredAt;

  factory LineageNode.fromJson(Map<String, dynamic> json) => LineageNode(
        lotCode: json.stringOr('lot_code', ''),
        operation: json.stringOr('operation', ''),
        grossWeight: json.grossWeight('gross_weight_mg'),
        purity: json.purityOrNull('purity_x10') ?? Purity.pure,
        depth: json.intOr('depth', 0),
        occurredAt: json.dateTimeOrNull('occurred_at'),
      );
}

/// A split/merge/melt operation and the fine weight lost to it.
final class LineageOperation {
  const LineageOperation({
    required this.type,
    required this.inputFine,
    required this.outputFine,
    required this.lossFine,
    this.occurredAt,
  });

  final String type;
  final FineWeight inputFine;
  final FineWeight outputFine;

  /// Fine milligrams that did not survive the operation — melt loss, assay
  /// sampling. Shown because it is the owner's gold that disappeared.
  final FineWeight lossFine;

  final DateTime? occurredAt;

  factory LineageOperation.fromJson(Map<String, dynamic> json) =>
      LineageOperation(
        type: json.stringOr('type', ''),
        inputFine: json.fineWeightOr('input_fine_mg', FineWeight.zero),
        outputFine: json.fineWeightOr('output_fine_mg', FineWeight.zero),
        lossFine: json.fineWeightOr('loss_fine_mg', FineWeight.zero),
        occurredAt: json.dateTimeOrNull('occurred_at'),
      );
}

/// `GET /lots/{id}/lineage`.
final class LotLineage {
  const LotLineage({
    required this.lotCode,
    required this.ancestors,
    required this.descendants,
    required this.operations,
  });

  final String lotCode;
  final List<LineageNode> ancestors;
  final List<LineageNode> descendants;
  final List<LineageOperation> operations;

  bool get isEmpty =>
      ancestors.isEmpty && descendants.isEmpty && operations.isEmpty;

  factory LotLineage.fromJson(Map<String, dynamic> json) => LotLineage(
        lotCode: json.mapOrNull('lot')?.stringOr('lot_code', '') ?? '',
        ancestors: json.listOf('ancestors', LineageNode.fromJson),
        descendants: json.listOf('descendants', LineageNode.fromJson),
        operations: json.listOf('operations', LineageOperation.fromJson),
      );
}

/// Result of scanning a lot's QR code.
///
/// `GET /verify/{qr_token}` is a PUBLIC endpoint: anyone holding the bar can
/// check that it is genuine. It deliberately does not reveal the owner
/// (§2.8: "این قطعه متعلق به شما نیست. اطلاعات مالک نمایش داده نمی‌شود").
final class LotVerification {
  const LotVerification({
    required this.isValid,
    required this.lotCode,
    required this.grossWeight,
    required this.purity,
    required this.fineWeight,
    required this.isAssayCertified,
    required this.isMine,
    this.status,
    this.assayLabName,
    this.assayedAt,
    this.custodyLabel,
    this.message,
  });

  final bool isValid;
  final String lotCode;
  final Weight grossWeight;
  final Purity purity;
  final FineWeight fineWeight;
  final bool isAssayCertified;

  /// True when the scanned lot belongs to the scanning organisation.
  final bool isMine;

  final String? status;
  final String? assayLabName;
  final DateTime? assayedAt;
  final String? custodyLabel;
  final String? message;

  factory LotVerification.fromJson(Map<String, dynamic> json) {
    final gross = json.grossWeight('gross_weight_mg');
    final purity = json.purityOrNull('purity_x10') ?? Purity.pure;

    return LotVerification(
      isValid: json.boolOr('is_valid', fallback: true),
      lotCode: json.stringOr('lot_code', ''),
      grossWeight: gross,
      purity: purity,
      fineWeight: json.fineWeightOrNull('fine_weight_mg') ??
          FineWeight.calculate(gross, purity),
      isAssayCertified: json.boolOr('is_assay_certified', fallback: false),
      isMine: json.boolOr('is_mine', fallback: false),
      status: json.stringOrNull('status'),
      assayLabName: json.stringOrNull('assay_lab_name'),
      assayedAt: json.dateTimeOrNull('assayed_at'),
      custodyLabel: json.stringOrNull('custody_label'),
      message: json.stringOrNull('message'),
    );
  }
}

/// Lots and vault (docs/05-api/02-endpoints.md §2.10).
class LotRepository {
  LotRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Paginated<GoldLot>> fetchLots({String? cursor, String? status}) =>
      _api.getList(
        '/lots',
        GoldLot.fromJson,
        query: <String, dynamic>{
          if (cursor != null) 'cursor': cursor,
          if (status != null) 'filter[status]': status,
        },
      );

  Future<GoldLot> fetchLot(int id) => _api.getOne('/lots/$id', GoldLot.fromJson);

  Future<LotLineage> fetchLineage(int id) =>
      _api.getOne('/lots/$id/lineage', LotLineage.fromJson);

  /// Public authenticity check. Works without a session, which is the point:
  /// a counterparty inspecting a bar can verify it from their own phone.
  Future<LotVerification> verify(String qrToken) =>
      _api.getOne('/verify/$qrToken', LotVerification.fromJson);

  /// `POST /lots/{id}/send-to-assay` — 🔑.
  Future<void> requestAssay({
    required int lotId,
    required IdempotencyKey idempotencyKey,
  }) =>
      _api.postVoid(
        '/lots/$lotId/send-to-assay',
        idempotencyKey: idempotencyKey,
      );

  /// `POST /lots/{id}/split` — 🔑 ✍️.
  Future<void> requestSplit({
    required int lotId,
    required List<Weight> parts,
    required IdempotencyKey idempotencyKey,
    String? totpCode,
  }) =>
      _api.postVoid(
        '/lots/$lotId/split',
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          'parts': <Map<String, int>>[
            for (final part in parts)
              <String, int>{'gross_weight_mg': part.milligrams},
          ],
          if (totpCode != null) 'transaction_code': totpCode,
        },
      );

  /// `POST /vault/withdrawals` — 🔑 ✍️.
  Future<void> requestWithdrawal({
    required int lotId,
    required IdempotencyKey idempotencyKey,
    String? totpCode,
    String? note,
  }) =>
      _api.postVoid(
        '/vault/withdrawals',
        idempotencyKey: idempotencyKey,
        body: <String, dynamic>{
          'lot_id': lotId,
          if (totpCode != null) 'transaction_code': totpCode,
          if (note != null && note.isNotEmpty) 'note': note,
        },
      );
}

final lotRepositoryProvider = Provider<LotRepository>(
  (ref) => LotRepository(api: ref.watch(apiClientProvider)),
);
