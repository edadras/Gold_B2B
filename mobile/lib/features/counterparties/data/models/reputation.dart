import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';

/// Public reputation of a member (docs/03-domain/14-reputation.md, F19).
///
/// Shown wherever the user is about to take counterparty risk: RFQ quote
/// comparison, OTC offers, counterparty detail. §2.6 is explicit that the
/// cheapest quote must not automatically look like the best one — a dealer
/// with a 0.9% dispute rate and 342 trades is a different proposition from
/// one with 12,830 trades and no disputes, and the UI has to say so.
final class ReputationSummary {
  const ReputationSummary({
    required this.totalTrades,
    this.score,
    this.onTimeSettlementRateBps,
    this.disputeRateBps,
    this.memberSinceDays,
    this.verificationTier,
  });

  final int totalTrades;

  /// F19 composite, 0..1000. Null when the server does not expose it.
  final int? score;

  /// On-time settlements / total, in basis points. Null when there have been
  /// no settlements at all — §1.11(6) is explicit that this is null and NOT
  /// 100%, because "never failed" and "never tried" are different facts.
  final int? onTimeSettlementRateBps;

  /// Lost disputes / total trades, in basis points.
  final int? disputeRateBps;

  final int? memberSinceDays;
  final String? verificationTier;

  /// A member with fewer than 50 trades or less than 90 days of history is
  /// flagged as new. Both thresholds are presentation choices, not business
  /// rules, and are deliberately generous.
  bool get isNewMember =>
      totalTrades < 50 || (memberSinceDays != null && memberSinceDays! < 90);

  /// Above 50 bps (0.5%) of trades ending in a lost dispute, the UI warns.
  bool get hasElevatedDisputeRate =>
      disputeRateBps != null && disputeRateBps! > 50;

  StatusTone get tone {
    if (hasElevatedDisputeRate) {
      return StatusTone.warning;
    }

    if (isNewMember) {
      return StatusTone.neutral;
    }

    final rate = onTimeSettlementRateBps;
    if (rate != null && rate >= 9900) {
      return StatusTone.positive;
    }

    return StatusTone.neutral;
  }

  factory ReputationSummary.fromJson(Map<String, dynamic> json) =>
      ReputationSummary(
        totalTrades: json.intOr('total_trades', 0),
        score: json.intOrNull('score'),
        onTimeSettlementRateBps:
            json.intOrNull('on_time_settlement_rate_bps') ??
                json.intOrNull('on_time_rate_bps'),
        disputeRateBps: json.intOrNull('dispute_rate_bps'),
        memberSinceDays: json.intOrNull('member_since_days'),
        verificationTier: json.stringOrNull('verification_tier'),
      );
}
