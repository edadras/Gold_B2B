import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/rial.dart';

/// The "امروز" card: how much was traded and what it earned.
///
/// [realizedProfit] is F16 — `sale_revenue − cogs − fees` — computed
/// server-side. It is never derived on the client, because doing so would
/// require the weighted-average cost basis (F14), which lives in the
/// accounting module and is not exposed to mobile.
final class DailySummary {
  const DailySummary({
    required this.tradeCount,
    required this.volume,
    required this.realizedProfit,
    required this.date,
  });

  final int tradeCount;
  final FineWeight volume;
  final Rial realizedProfit;
  final DateTime date;

  bool get isEmpty => tradeCount == 0 && volume.isZero;

  factory DailySummary.fromJson(Map<String, dynamic> json) => DailySummary(
        tradeCount: json.intOr('trade_count', 0),
        volume: json.fineWeightOr('volume_mg', FineWeight.zero),
        realizedProfit: json.rialOr('realized_profit_rial', Rial.zero),
        date: json.dateTimeOrNull('date') ?? DateTime.now().toUtc(),
      );
}
