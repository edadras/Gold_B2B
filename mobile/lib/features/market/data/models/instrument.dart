import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/purity.dart';

/// A tradable instrument, e.g. `GOLD-995-T0`.
///
/// The trading parameters here — tick size, min/max quantity, slippage cap —
/// are the SAME rules the server enforces. Having them client-side lets the
/// order form reject a bad order before it costs a round trip; it does not
/// make the client authoritative. Every one of them is re-checked server-side
/// and the corresponding error codes (`ORDER_PRICE_INVALID_TICK`,
/// `ORDER_QUANTITY_TOO_SMALL`, …) are handled.
final class Instrument {
  const Instrument({
    required this.code,
    required this.displayName,
    required this.purity,
    required this.settlementType,
    required this.isActive,
    required this.tickSizeRial,
    required this.minQuantity,
    required this.maxQuantity,
    required this.maxSlippageBps,
    required this.priceDeviationWarningBps,
  });

  final String code;
  final String displayName;

  /// Minimum purity deliverable against this instrument.
  final Purity purity;

  /// `T0`, `T1`, `T2` — when settlement is due.
  final String settlementType;

  final bool isActive;

  /// Prices must be a multiple of this.
  final int tickSizeRial;

  final FineWeight minQuantity;
  final FineWeight maxQuantity;

  /// Worst-case price move a MARKET order will accept, used for the F10
  /// reservation estimate.
  final int maxSlippageBps;

  /// Above this deviation from the reference price, the order form shows an
  /// explicit fat-finger warning (§2.3 rule 3 — the mockup says 5%).
  final int priceDeviationWarningBps;

  factory Instrument.fromJson(Map<String, dynamic> json) => Instrument(
        code: json.requireString('code'),
        displayName: json.stringOr('display_name', json.stringOr('code', '')),
        purity: json.purityOrNull('purity_x10') ?? Purity.pure,
        settlementType: json.stringOr('settlement_type', 'T0'),
        isActive: json.boolOr('is_active', fallback: true),
        tickSizeRial: json.intOr('tick_size_rial', 10000),
        minQuantity: json.fineWeightOr('min_quantity_mg', FineWeight.zero),
        maxQuantity: json.fineWeightOr(
          'max_quantity_mg',
          FineWeight.fromMilligrams(100000000),
        ),
        maxSlippageBps: json.intOr('max_slippage_bps', 50),
        priceDeviationWarningBps:
            json.intOr('price_deviation_warning_bps', 500),
      );

  /// Local pre-flight validation of a price. Returns a Persian message, or
  /// null when the price is acceptable.
  String? validatePrice(PricePerFineGram price) {
    if (price.isZero) {
      return 'قیمت باید بزرگ‌تر از صفر باشد.';
    }

    if (!price.isMultipleOf(tickSizeRial)) {
      return 'قیمت باید مضربی از $tickSizeRial ریال باشد.';
    }

    return null;
  }

  /// Local pre-flight validation of a quantity.
  String? validateQuantity(FineWeight quantity) {
    if (quantity.isZero) {
      return 'مقدار باید بزرگ‌تر از صفر باشد.';
    }

    if (quantity < minQuantity) {
      return 'حداقل مقدار سفارش ${minQuantity.gramsFormatted} گرم است.';
    }

    if (quantity > maxQuantity) {
      return 'حداکثر مقدار سفارش ${maxQuantity.gramsFormatted} گرم است.';
    }

    return null;
  }
}

/// Trading session state for an instrument (`GET /market/sessions/{code}`).
final class MarketSessionState {
  const MarketSessionState({
    required this.instrumentCode,
    required this.status,
    this.opensAt,
    this.closesAt,
    this.haltReason,
  });

  /// `SCHEDULED`, `PRE_OPEN`, `OPEN`, `PAUSED`, `CLOSED`, …
  /// docs/11-appendix/02-state-machines.md §2.10.
  final String status;

  final String instrumentCode;
  final DateTime? opensAt;
  final DateTime? closesAt;
  final String? haltReason;

  bool get isOpen => status == 'OPEN';

  bool get isPaused => status == 'PAUSED' || status == 'HALTED';

  String get label => switch (status) {
        'OPEN' => 'بازار باز',
        'PRE_OPEN' => 'پیش‌گشایش',
        'PAUSED' => 'توقف موقت',
        'HALTED' => 'متوقف',
        'CLOSED' => 'بازار بسته',
        'SCHEDULED' => 'برنامه‌ریزی‌شده',
        _ => status,
      };

  factory MarketSessionState.fromJson(Map<String, dynamic> json) =>
      MarketSessionState(
        instrumentCode: json.stringOr('instrument', ''),
        status: json.stringOr('status', 'CLOSED'),
        opensAt: json.dateTimeOrNull('opens_at'),
        closesAt: json.dateTimeOrNull('closes_at'),
        haltReason: json.stringOrNull('halt_reason'),
      );
}
