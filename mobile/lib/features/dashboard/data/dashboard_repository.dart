import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/storage/secure_storage.dart';
import 'package:gold_b2b/features/dashboard/data/models/balances.dart';
import 'package:gold_b2b/features/dashboard/data/models/daily_summary.dart';

/// Balances and the daily summary card.
class DashboardRepository {
  DashboardRepository({required ApiClient api, required SecureStorage storage})
      : _api = api,
        _storage = storage;

  final ApiClient _api;
  final SecureStorage _storage;

  /// `GET /balances`, with a read-only offline fallback.
  ///
  /// The fallback is deliberately narrow: it applies ONLY to a transport
  /// failure. A 403 or a 423 means the server has something to say about this
  /// account and showing a cheerful cached balance instead would be a lie, so
  /// those propagate.
  ///
  /// The returned snapshot is flagged [BalanceSnapshot.isFromCache] so the UI
  /// can grey it and label its age. There is no offline write path — §1.9.
  Future<BalanceSnapshot> fetchBalances() async {
    try {
      final snapshot = await _api.getOne('/balances', BalanceSnapshot.fromJson);
      await _storage.writeCache(CacheKeys.balances, snapshot.toJson());

      return snapshot;
    } on NetworkFailure {
      return await _cachedBalances() ?? (throw const NetworkFailure());
    } on TimeoutFailure {
      return await _cachedBalances() ??
          (throw const TimeoutFailure());
    }
  }

  Future<BalanceSnapshot?> _cachedBalances() async {
    final entry = await _storage.readCache(CacheKeys.balances);
    if (entry == null) {
      return null;
    }

    try {
      return BalanceSnapshot.fromJson(entry.payload)
          .copyWith(asOf: entry.cachedAt, isFromCache: true);
    } on FormatException {
      await _storage.deleteCache(CacheKeys.balances);

      return null;
    }
  }

  /// `GET /reports/daily-profit` — the "امروز" card.
  ///
  /// TODO(backend): the endpoint list has `/reports/daily-profit` but no
  /// documented response body. The parser below assumes
  /// `{trade_count, volume_mg, realized_profit_rial, date}`, which matches
  /// the dashboard mockup in docs/07-mobile-flutter/02-screens.md §2.2 and
  /// formula F16. Confirm against the real implementation before release; the
  /// parser tolerates missing fields and renders zeros rather than failing.
  Future<DailySummary> fetchDailySummary() =>
      _api.getOne('/reports/daily-profit', DailySummary.fromJson);
}

final dashboardRepositoryProvider = Provider<DashboardRepository>(
  (ref) => DashboardRepository(
    api: ref.watch(apiClientProvider),
    storage: ref.watch(secureStorageProvider),
  ),
);
