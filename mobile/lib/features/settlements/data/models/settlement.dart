import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';

/// docs/11-appendix/02-state-machines.md §2.4.
final class SettlementStatus {
  const SettlementStatus._();

  static const String created = 'CREATED';
  static const String assetsLocked = 'ASSETS_LOCKED';
  static const String paymentPending = 'PAYMENT_PENDING';
  static const String paymentDeclared = 'PAYMENT_DECLARED';
  static const String paymentConfirmed = 'PAYMENT_CONFIRMED';
  static const String goldTransferring = 'GOLD_TRANSFERRING';
  static const String settled = 'SETTLED';
  static const String completed = 'COMPLETED';
  static const String overdue = 'OVERDUE';
  static const String defaulted = 'DEFAULTED';
  static const String cancelled = 'CANCELLED';
  static const String disputed = 'DISPUTED';
  static const String reversed = 'REVERSED';
  static const String nettingQueue = 'NETTING_QUEUE';

  static String label(String status) => switch (status) {
        created => 'ایجاد شده',
        assetsLocked => 'دارایی قفل شده',
        paymentPending => 'در انتظار پرداخت',
        paymentDeclared => 'پرداخت اعلام شده',
        paymentConfirmed => 'پرداخت تأیید شده',
        goldTransferring => 'در حال انتقال طلا',
        settled => 'تسویه شده',
        completed => 'تکمیل شده',
        overdue => 'سررسید گذشته',
        defaulted => 'نکول',
        cancelled => 'لغو شده',
        disputed => 'در اختلاف',
        reversed => 'برگشت خورده',
        nettingQueue => 'در صف تهاتر',
        _ => status,
      };

  static StatusTone tone(String status) => switch (status) {
        completed || settled || paymentConfirmed => StatusTone.positive,
        paymentPending || paymentDeclared || goldTransferring || nettingQueue =>
          StatusTone.warning,
        overdue || defaulted || disputed || reversed => StatusTone.negative,
        _ => StatusTone.neutral,
      };
}

/// What the current user must do next, if anything.
enum SettlementAction {
  /// This user owes the money and must declare a payment.
  declarePayment,

  /// The counterparty declared a payment; this user must confirm receipt.
  confirmPayment,

  /// This user must confirm the gold was delivered.
  confirmDelivery,

  /// Waiting on the other side.
  none,
}

extension SettlementActionX on SettlementAction {
  static SettlementAction parse(String? wire) => switch (wire) {
        'DECLARE_PAYMENT' => SettlementAction.declarePayment,
        'CONFIRM_PAYMENT' => SettlementAction.confirmPayment,
        'CONFIRM_DELIVERY' => SettlementAction.confirmDelivery,
        _ => SettlementAction.none,
      };

  String get label => switch (this) {
        SettlementAction.declarePayment => 'اعلام پرداخت',
        SettlementAction.confirmPayment => 'تأیید دریافت وجه',
        SettlementAction.confirmDelivery => 'تأیید تحویل طلا',
        SettlementAction.none => '',
      };
}

/// A destination bank account.
final class BankAccountRef {
  const BankAccountRef({
    required this.iban,
    required this.bankName,
    required this.holderName,
  });

  final String iban;
  final String bankName;
  final String holderName;

  factory BankAccountRef.fromJson(Map<String, dynamic> json) => BankAccountRef(
        iban: json.stringOr('iban', ''),
        bankName: json.stringOr('bank_name', ''),
        holderName: json.stringOr('holder_name', ''),
      );
}

/// A counterparty as it appears on a settlement or a trade.
final class CounterpartyRef {
  const CounterpartyRef({
    required this.id,
    required this.displayName,
    required this.verificationTier,
  });

  final int id;
  final String displayName;
  final String verificationTier;

  factory CounterpartyRef.fromJson(Map<String, dynamic> json) =>
      CounterpartyRef(
        id: json.intOr('id', 0),
        displayName: json.stringOr('display_name', ''),
        verificationTier: json.stringOr('verification_tier', 'BRONZE'),
      );
}

/// One settlement obligation.
///
/// TODO(backend): §2.8 documents the endpoints but not the settlement
/// resource shape. The fields below are inferred from the settlement screen
/// mockups (docs/07-mobile-flutter/02-screens.md §2.5), the
/// `settlement.status_changed` realtime event (§3.3) and the
/// `settlement.completed` webhook payload (§3.12). Every field except `id`
/// and `status` has a safe default, so an unexpected shape degrades to a
/// sparse card rather than a crash. Confirm before release — in particular
/// `destination_account`, which the payment-declaration screen depends on and
/// which appears in no documented payload.
final class Settlement {
  const Settlement({
    required this.id,
    required this.settlementCode,
    required this.status,
    required this.side,
    required this.amount,
    required this.fineWeight,
    required this.requiresMyAction,
    required this.action,
    this.instrumentCode,
    this.pricePerGram,
    this.counterparty,
    this.deadlineAt,
    this.destinationAccount,
    this.paymentReference,
    this.paymentDeclaredAt,
    this.receiptDocumentId,
    this.tradeCode,
  });

  final int id;
  final String settlementCode;
  final String status;

  /// `BUY` when this organisation is the buyer (and therefore pays rial).
  final String side;

  final Rial amount;
  final FineWeight fineWeight;

  final bool requiresMyAction;
  final SettlementAction action;

  final String? instrumentCode;
  final PricePerFineGram? pricePerGram;
  final CounterpartyRef? counterparty;
  final DateTime? deadlineAt;

  /// Where to send the money. Present when this user is the payer.
  final BankAccountRef? destinationAccount;

  final String? paymentReference;
  final DateTime? paymentDeclaredAt;
  final int? receiptDocumentId;
  final String? tradeCode;

  bool get isBuy => side.toUpperCase() == 'BUY';

  String? get counterpartyName => counterparty?.displayName;

  bool isOverdueAt(DateTime now) {
    final deadline = deadlineAt;

    return deadline != null && deadline.toUtc().isBefore(now.toUtc());
  }

  /// The line the dashboard shows: "پرداخت ۱۹.۶۵ میلیارد" or
  /// "تأیید دریافت وجه".
  String get actionTitle => switch (action) {
        SettlementAction.declarePayment => 'اعلام پرداخت',
        SettlementAction.confirmPayment => 'تأیید دریافت وجه',
        SettlementAction.confirmDelivery => 'تأیید تحویل طلا',
        SettlementAction.none => SettlementStatus.label(status),
      };

  /// "خرید ۲۵۰ گرم از بنکداری پارس".
  String get summaryLine {
    final party = counterparty?.displayName ?? '';
    final direction = isBuy ? 'خرید' : 'فروش';
    final preposition = isBuy ? 'از' : 'به';

    return '$direction ${fineWeight.gramsFormatted} گرم '
        '$preposition $party';
  }

  factory Settlement.fromJson(Map<String, dynamic> json) => Settlement(
        id: json.requireInt('id'),
        settlementCode: json.stringOr('settlement_code', ''),
        status: json.stringOr('status', SettlementStatus.created),
        side: json.stringOr('side', 'BUY'),
        amount: json.rialOr('amount_rial', Rial.zero),
        fineWeight:
            json.fineWeightOr('fine_weight_mg', FineWeight.zero),
        requiresMyAction:
            json.boolOr('requires_your_action', fallback: false),
        action: SettlementActionX.parse(json.stringOrNull('action_type')),
        instrumentCode: json.stringOrNull('instrument'),
        pricePerGram: json.priceOrNull('price_per_gram_rial'),
        counterparty: json.mapOrNull('counterparty') == null
            ? null
            : CounterpartyRef.fromJson(json.mapOrNull('counterparty')!),
        deadlineAt: json.dateTimeOrNull('deadline_at') ??
            json.dateTimeOrNull('settlement_deadline'),
        destinationAccount: json.mapOrNull('destination_account') == null
            ? null
            : BankAccountRef.fromJson(json.mapOrNull('destination_account')!),
        paymentReference: json.stringOrNull('payment_reference'),
        paymentDeclaredAt: json.dateTimeOrNull('payment_declared_at'),
        receiptDocumentId: json.intOrNull('receipt_document_id'),
        tradeCode: json.stringOrNull('trade_code'),
      );

  Settlement copyWith({
    String? status,
    bool? requiresMyAction,
    SettlementAction? action,
    String? paymentReference,
    DateTime? deadlineAt,
  }) =>
      Settlement(
        id: id,
        settlementCode: settlementCode,
        status: status ?? this.status,
        side: side,
        amount: amount,
        fineWeight: fineWeight,
        requiresMyAction: requiresMyAction ?? this.requiresMyAction,
        action: action ?? this.action,
        instrumentCode: instrumentCode,
        pricePerGram: pricePerGram,
        counterparty: counterparty,
        deadlineAt: deadlineAt ?? this.deadlineAt,
        destinationAccount: destinationAccount,
        paymentReference: paymentReference ?? this.paymentReference,
        paymentDeclaredAt: paymentDeclaredAt,
        receiptDocumentId: receiptDocumentId,
        tradeCode: tradeCode,
      );

  /// Apply a `settlement.status_changed` event (§3.3).
  Settlement applyRealtimeUpdate(Map<String, dynamic> data) => copyWith(
        status: data.stringOrNull('to_status'),
        requiresMyAction: data.boolOr('requires_your_action', fallback: requiresMyAction),
        action: data.stringOrNull('action_type') == null
            ? null
            : SettlementActionX.parse(data.stringOrNull('action_type')),
        paymentReference: data.stringOrNull('payment_reference'),
        deadlineAt: data.dateTimeOrNull('deadline_at'),
      );
}

/// One entry in a settlement's event history (`GET /settlements/{id}/events`).
final class SettlementEvent {
  const SettlementEvent({
    required this.fromStatus,
    required this.toStatus,
    required this.occurredAt,
    this.actor,
    this.reason,
  });

  final String fromStatus;
  final String toStatus;
  final DateTime occurredAt;
  final String? actor;
  final String? reason;

  factory SettlementEvent.fromJson(Map<String, dynamic> json) =>
      SettlementEvent(
        fromStatus: json.stringOr('from_status', ''),
        toStatus: json.stringOr('to_status', ''),
        occurredAt:
            json.dateTimeOrNull('occurred_at') ?? DateTime.now().toUtc(),
        actor: json.stringOrNull('actor'),
        reason: json.stringOrNull('reason'),
      );
}
