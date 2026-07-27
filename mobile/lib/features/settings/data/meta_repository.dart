import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/trade_valuation.dart';

/// `GET /meta/settings` and `GET /meta/enums` (§2.17).
///
/// The enum endpoint exists specifically so that the client can render new
/// statuses without an app update, and the settings endpoint carries the fee
/// rates the order form needs for its live breakdown.
class MetaRepository {
  MetaRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<PlatformSettings> fetchSettings() =>
      _api.getOne('/meta/settings', PlatformSettings.fromJson);

  /// Server-provided Persian labels for every enum, keyed by enum name then by
  /// value: `enums['order_status']['PARTIALLY_FILLED'] == 'اجرای جزئی'`.
  Future<Map<String, Map<String, String>>> fetchEnumLabels() =>
      _api.getOne('/meta/enums', _parseEnums);

  static Map<String, Map<String, String>> _parseEnums(
    Map<String, dynamic> json,
  ) {
    final result = <String, Map<String, String>>{};

    json.forEach((group, values) {
      if (values is! List) {
        return;
      }

      final labels = <String, String>{};
      for (final entry in values) {
        if (entry is Map<String, dynamic>) {
          final value = entry['value'];
          final label = entry['label'];
          if (value is String && label is String) {
            labels[value] = label;
          }
        }
      }

      if (labels.isNotEmpty) {
        result[group] = labels;
      }
    });

    return result;
  }
}

/// Platform-wide settings the client needs in order to show correct numbers.
///
/// TODO(backend): `GET /meta/settings` is listed in §2.17 but its response
/// body is not specified. The field names below are the client's best guess,
/// chosen to mirror `config/goldb2b.php`. Every one of them has a documented
/// fallback that matches the worked example in
/// docs/11-appendix/01-formulas.md §1.4, so a missing field degrades to a
/// plausible breakdown rather than a crash — but the breakdown shown to the
/// user would then be wrong, so this MUST be confirmed before release.
///
/// Note that being wrong here is not dangerous in the way a wrong quantity
/// would be: the fee is computed server-side and the executed trade carries
/// the real figures. The risk is a confusing preview, not a mis-priced order.
final class PlatformSettings {
  const PlatformSettings({
    required this.buyerFee,
    required this.sellerFee,
    required this.tax,
    required this.minOrderRial,
    required this.settlementGraceHours,
  });

  final FeeTerms buyerFee;
  final FeeTerms sellerFee;
  final TaxTerms tax;
  final Rial minOrderRial;
  final int settlementGraceHours;

  /// 0.15% buyer / 0.10% seller — the rates used throughout the worked
  /// examples in the formula appendix.
  static const PlatformSettings fallback = PlatformSettings(
    buyerFee: FeeTerms.rate(150),
    sellerFee: FeeTerms.rate(100),
    tax: TaxTerms.none,
    minOrderRial: Rial.zero,
    settlementGraceHours: 0,
  );

  factory PlatformSettings.fromJson(Map<String, dynamic> json) {
    final fees = json.mapOrNull('fees');
    final taxJson = json.mapOrNull('tax');

    return PlatformSettings(
      buyerFee: fees == null
          ? fallback.buyerFee
          : FeeTerms(
              rateX100k: fees.intOr('buyer_rate_x100k', 150),
              minAmount: fees.rialOrNull('min_fee_rial'),
              maxAmount: fees.rialOrNull('max_fee_rial'),
            ),
      sellerFee: fees == null
          ? fallback.sellerFee
          : FeeTerms(
              rateX100k: fees.intOr('seller_rate_x100k', 100),
              minAmount: fees.rialOrNull('min_fee_rial'),
              maxAmount: fees.rialOrNull('max_fee_rial'),
            ),
      tax: taxJson == null ? TaxTerms.none : TaxTerms.fromJson(taxJson),
      minOrderRial: json.rialOr('min_order_rial', Rial.zero),
      settlementGraceHours: json.intOr('settlement_grace_hours', 0),
    );
  }
}

final metaRepositoryProvider = Provider<MetaRepository>(
  (ref) => MetaRepository(api: ref.watch(apiClientProvider)),
);

/// Platform settings, falling back to the documented defaults if the endpoint
/// is unavailable — a failed settings fetch must not stop somebody trading.
final platformSettingsProvider = FutureProvider<PlatformSettings>(
  (ref) async {
    try {
      return await ref.watch(metaRepositoryProvider).fetchSettings();
    } on Object {
      return PlatformSettings.fallback;
    }
  },
);

final enumLabelsProvider = FutureProvider<Map<String, Map<String, String>>>(
  (ref) async {
    try {
      return await ref.watch(metaRepositoryProvider).fetchEnumLabels();
    } on Object {
      return const <String, Map<String, String>>{};
    }
  },
);
