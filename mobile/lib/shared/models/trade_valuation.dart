/// Client-side port of `App\Modules\Shared\Calculation`.
///
/// WHY THIS EXISTS ON THE CLIENT AT ALL: the server is, and remains, the
/// authority on what a trade costs. This port exists so the order form can
/// show a live breakdown as the user types, and so the confirmation sheet can
/// show the same numbers the server will produce. It is never used to decide
/// anything — the request carries `quantity_mg` and `price_rial` and nothing
/// else, and whatever the server returns is what is displayed afterwards.
///
/// Because it exists, it must agree with the server exactly. The test vectors
/// in test/models/calculation_test.dart are copied verbatim from
/// tests/Unit/Shared/CalculationTest.php for that reason. If you change one,
/// change both.
///
/// Formulas F1, F5, F8, F9, F10, F11 — docs/11-appendix/01-formulas.md.
library;

import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/weight.dart';


/// Fee terms for one side of a trade, in hundred-thousandths (0.15% -> 150).
final class FeeTerms {
  const FeeTerms({
    required this.rateX100k,
    this.minAmount,
    this.maxAmount,
  });

  const FeeTerms.rate(this.rateX100k)
      : minAmount = null,
        maxAmount = null;

  final int rateX100k;
  final Rial? minAmount;
  final Rial? maxAmount;

  static const FeeTerms none = FeeTerms.rate(0);

  /// F8 — `fee = ceil(gross * rate / 100000)`, then clamped to min/max.
  Rial applyTo(Rial gross) {
    final fee = gross.rateCeil(rateX100k);

    final floor = minAmount;
    if (floor != null && fee < floor) {
      return floor;
    }

    final cap = maxAmount;
    if (cap != null && fee > cap) {
      return cap;
    }

    return fee;
  }

  factory FeeTerms.fromJson(Map<String, dynamic> json) {
    final min = json['min_fee_rial'];
    final max = json['max_fee_rial'];

    return FeeTerms(
      rateX100k: (json['rate_x100k'] as num?)?.toInt() ?? 0,
      minAmount: min is num ? Rial.fromRial(min.toInt()) : null,
      maxAmount: max is num ? Rial.fromRial(max.toInt()) : null,
    );
  }

  /// Rate rendered as a percentage for the breakdown label, e.g. `"۰.۱۵٪"`
  /// is produced by formatting `"0.15"` — see PercentFormatter.
  String get ratePercentString {
    final whole = rateX100k ~/ 1000;
    final fraction = rateX100k % 1000;
    if (fraction == 0) {
      return whole.toString();
    }

    final padded = fraction.toString().padLeft(3, '0');

    return '$whole.${padded.replaceAll(RegExp(r'0+$'), '')}';
  }
}

/// Tax terms. Currently zero for inter-dealer melted gold, but the rate and
/// base are configurable because that is a legal question, not a technical
/// one — docs/10-compliance/01-regulatory.md §1.1(ز).
final class TaxTerms {
  const TaxTerms({this.rateX100k = 0, this.base = TaxBase.gross});

  final int rateX100k;
  final TaxBase base;

  static const TaxTerms none = TaxTerms();

  /// Rounds UP, matching the fee convention (F8).
  Rial applyTo(Rial gross, Rial fee) {
    if (rateX100k == 0) {
      return Rial.zero;
    }

    final subject = base == TaxBase.fee ? fee : gross;

    return subject.rateCeil(rateX100k);
  }

  factory TaxTerms.fromJson(Map<String, dynamic> json) => TaxTerms(
        rateX100k: (json['rate_x100k'] as num?)?.toInt() ?? 0,
        base: json['base'] == 'FEE' ? TaxBase.fee : TaxBase.gross,
      );
}

enum TaxBase { gross, fee }

/// The fully-priced result of a trade. Immutable and self-checking:
/// constructing one that does not balance throws immediately, exactly as the
/// PHP `TradeValuation` does.
///
/// Invariant (F9):
///   grossAmount == sellerNet + sellerFee + sellerTax
///   buyerNet    == grossAmount + buyerFee + buyerTax
final class TradeValuation {
  TradeValuation({
    required this.fineWeight,
    required this.pricePerFineGram,
    required this.grossAmount,
    required this.buyerFee,
    required this.sellerFee,
    required this.buyerTax,
    required this.sellerTax,
    required this.buyerNet,
    required this.sellerNet,
  }) {
    _assertBalanced();
  }

  final FineWeight fineWeight;
  final PricePerFineGram pricePerFineGram;
  final Rial grossAmount;
  final Rial buyerFee;
  final Rial sellerFee;
  final Rial buyerTax;
  final Rial sellerTax;
  final Rial buyerNet;
  final Rial sellerNet;

  /// Total platform income from this trade.
  Rial get platformIncome => buyerFee + sellerFee;

  /// Total tax withheld from this trade.
  Rial get totalTax => buyerTax + sellerTax;

  void _assertBalanced() {
    final sellerSide = sellerNet + sellerFee + sellerTax;
    if (sellerSide != grossAmount) {
      throw StateError(
        'Seller side does not balance: gross=${grossAmount.amount}, '
        'net+fee+tax=${sellerSide.amount}',
      );
    }

    final buyerSide = grossAmount + buyerFee + buyerTax;
    if (buyerSide != buyerNet) {
      throw StateError(
        'Buyer side does not balance: buyerNet=${buyerNet.amount}, '
        'gross+fee+tax=${buyerSide.amount}',
      );
    }

    // Cash-flow closure: what the buyer pays minus what the seller receives
    // must equal exactly the fees and taxes the platform retains.
    final spread = buyerNet - sellerNet;
    final retained = platformIncome + totalTax;
    if (spread != retained) {
      throw StateError(
        'Cash flow does not close: spread=${spread.amount}, '
        'retained=${retained.amount}',
      );
    }
  }
}

/// The single place where a trade is priced on the client.
final class TradeValueCalculator {
  const TradeValueCalculator();

  /// Price a trade of a known fine weight.
  ///
  /// Note the ordering: gross is computed, fees and taxes are computed FROM
  /// gross, and the net figures are then derived by addition and subtraction —
  /// never by independent rounding. That is what guarantees the books balance.
  TradeValuation value({
    required FineWeight fineWeight,
    required PricePerFineGram price,
    required FeeTerms buyerFee,
    required FeeTerms sellerFee,
    TaxTerms tax = TaxTerms.none,
  }) {
    final gross = price.valueOf(fineWeight);

    final buyerFeeAmount = buyerFee.applyTo(gross);
    final sellerFeeAmount = sellerFee.applyTo(gross);

    final buyerTax = tax.applyTo(gross, buyerFeeAmount);
    final sellerTax = tax.applyTo(gross, sellerFeeAmount);

    return TradeValuation(
      fineWeight: fineWeight,
      pricePerFineGram: price,
      grossAmount: gross,
      buyerFee: buyerFeeAmount,
      sellerFee: sellerFeeAmount,
      buyerTax: buyerTax,
      sellerTax: sellerTax,
      buyerNet: gross + buyerFeeAmount + buyerTax,
      sellerNet: gross - sellerFeeAmount - sellerTax,
    );
  }

  /// Price a trade described by gross weight and purity, deriving fine weight
  /// first (F1).
  TradeValuation valueOfGross({
    required Weight gross,
    required Purity purity,
    required PricePerFineGram price,
    required FeeTerms buyerFee,
    required FeeTerms sellerFee,
    TaxTerms tax = TaxTerms.none,
  }) =>
      value(
        fineWeight: FineWeight.calculate(gross, purity),
        price: price,
        buyerFee: buyerFee,
        sellerFee: sellerFee,
        tax: tax,
      );

  /// F10 — how much rial a buyer must have available before an order will be
  /// accepted. For a MARKET order, pass the worst acceptable price, i.e.
  /// `bestAsk.worseForBuyer(maxSlippageBps)`.
  Rial buyerRequirement({
    required FineWeight quantity,
    required PricePerFineGram price,
    required FeeTerms buyerFee,
    TaxTerms tax = TaxTerms.none,
  }) {
    final gross = price.valueOf(quantity);
    final fee = buyerFee.applyTo(gross);

    return gross + fee + tax.applyTo(gross, fee);
  }

  /// F11 — a seller reserves exactly the fine weight being sold. The seller's
  /// fee is taken from rial at settlement, not from gold.
  FineWeight sellerRequirement(FineWeight quantity) => quantity;
}
