import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';

/// One gross obligation that the netting run would replace.
final class NettingObligation {
  const NettingObligation({
    required this.counterpartyName,
    required this.weight,
    required this.isDebit,
  });

  final String counterpartyName;
  final FineWeight weight;

  /// True when this organisation owes the counterparty.
  final bool isDebit;

  String get directionLabel => isDebit ? 'بدهکار' : 'بستانکار';

  factory NettingObligation.fromJson(Map<String, dynamic> json) =>
      NettingObligation(
        counterpartyName: json.stringOr('counterparty_name', ''),
        weight: json.fineWeightOr('fine_weight_mg', FineWeight.zero),
        isDebit: json.stringOr('direction', 'DEBIT') == 'DEBIT',
      );
}

/// A multilateral netting proposal (F13).
///
/// The screen (docs/07-mobile-flutter/02-screens.md §2.9) shows the gross
/// obligations, then the single net position that would replace them, then
/// how many participants have already accepted. The invariant behind it —
/// `Σ net_position(i) == 0` across all members — is enforced server-side; the
/// client only ever sees its own row.
///
/// TODO(backend): §2.9 lists the endpoints but not the resource shape. Fields
/// below follow the §2.9 mockup. `net_position_mg` is signed: negative means
/// this organisation owes the clearing account.
final class NettingBatch {
  const NettingBatch({
    required this.id,
    required this.batchCode,
    required this.status,
    required this.participantCount,
    required this.acceptedCount,
    required this.obligations,
    required this.netPositionMg,
    this.expiresAt,
    this.myResponse,
  });

  final int id;
  final String batchCode;

  /// `PROPOSED`, `ACCEPTING`, `EXECUTING`, `EXECUTED`, `CANCELLED`, `FAILED`
  /// — docs/11-appendix/02-state-machines.md §2.9.
  final String status;

  final int participantCount;
  final int acceptedCount;

  /// The gross obligations this batch would replace.
  final List<NettingObligation> obligations;

  /// Signed net position in fine milligrams. Negative = this organisation
  /// owes the clearing account.
  final int netPositionMg;

  final DateTime? expiresAt;

  /// `ACCEPTED`, `REJECTED`, or null when this organisation has not answered.
  final String? myResponse;

  bool get isDebit => netPositionMg < 0;

  FineWeight get netPosition =>
      FineWeight.fromMilligrams(netPositionMg.abs());

  bool get awaitsMyResponse =>
      myResponse == null &&
      (status == 'PROPOSED' || status == 'ACCEPTING');

  /// "۱ انتقال به‌جای ۴" — the whole point of netting.
  int get transfersSaved =>
      obligations.isEmpty ? 0 : obligations.length - (netPositionMg == 0 ? 0 : 1);

  String get statusLabel => switch (status) {
        'PROPOSED' => 'پیشنهاد شده',
        'ACCEPTING' => 'در حال جمع‌آوری پذیرش',
        'EXECUTING' => 'در حال اجرا',
        'EXECUTED' => 'اجرا شده',
        'CANCELLED' => 'لغو شده',
        'FAILED' => 'ناموفق',
        _ => status,
      };

  StatusTone get statusTone => switch (status) {
        'EXECUTED' => StatusTone.positive,
        'PROPOSED' || 'ACCEPTING' || 'EXECUTING' => StatusTone.warning,
        'CANCELLED' || 'FAILED' => StatusTone.negative,
        _ => StatusTone.neutral,
      };

  factory NettingBatch.fromJson(Map<String, dynamic> json) => NettingBatch(
        id: json.requireInt('id'),
        batchCode: json.stringOr('batch_code', ''),
        status: json.stringOr('status', 'PROPOSED'),
        participantCount: json.intOr('participant_count', 0),
        acceptedCount: json.intOr('accepted_count', 0),
        obligations: json.listOf('obligations', NettingObligation.fromJson),
        netPositionMg: json.intOr('net_position_mg', 0),
        expiresAt: json.dateTimeOrNull('expires_at'),
        myResponse: json.stringOrNull('my_response'),
      );
}
