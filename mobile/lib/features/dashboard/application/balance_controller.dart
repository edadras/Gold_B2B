import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/core/realtime/websocket_service.dart';
import 'package:gold_b2b/features/dashboard/data/dashboard_repository.dart';
import 'package:gold_b2b/features/dashboard/data/models/balances.dart';
import 'package:gold_b2b/features/dashboard/data/models/daily_summary.dart';

/// The balance, and the live patches applied to it between REST reads.
///
/// The contract, restated because it is the most important one in the app:
///
///   REST is truth. The socket is a hint.
///
/// A `balance.updated` event patches the in-memory snapshot so the number on
/// screen moves the instant an order fills. It does NOT make that number
/// authoritative. `realtime_sync.dart` calls [refresh] on every reconnect, and
/// any financial action re-reads the balance server-side before acting on it.
class BalanceController extends StateNotifier<AsyncValue<BalanceSnapshot>> {
  BalanceController({
    required DashboardRepository repository,
    required WebSocketService realtime,
  })  : _repository = repository,
        super(const AsyncValue<BalanceSnapshot>.loading()) {
    _events = realtime.on(RealtimeEvents.balanceUpdated).listen(_applyEvent);
    unawaited(refresh());
  }

  final DashboardRepository _repository;
  StreamSubscription<RealtimeEvent>? _events;

  Future<void> refresh() async {
    state = await AsyncValue.guard(_repository.fetchBalances);
  }

  /// Silent refresh: keeps the current value on screen while re-reading, so a
  /// pull-to-refresh does not blank the card.
  Future<void> refreshQuietly() async {
    final next = await AsyncValue.guard(_repository.fetchBalances);
    if (mounted) {
      state = next;
    }
  }

  void _applyEvent(RealtimeEvent event) {
    final current = state.valueOrNull;
    if (current == null) {
      return;
    }

    state = AsyncValue<BalanceSnapshot>.data(
      current.applyRealtimeUpdate(event.data),
    );
  }

  @override
  void dispose() {
    unawaited(_events?.cancel());
    super.dispose();
  }
}

final balanceProvider =
    StateNotifierProvider<BalanceController, AsyncValue<BalanceSnapshot>>(
  (ref) => BalanceController(
    repository: ref.watch(dashboardRepositoryProvider),
    realtime: ref.watch(webSocketServiceProvider),
  ),
);

/// Convenience selector: the free-to-trade gold, used by the order form to
/// validate a sell quantity before the request is built.
final availableGoldProvider = Provider(
  (ref) => ref.watch(balanceProvider).valueOrNull?.gold.available,
);

/// Convenience selector: the free-to-spend rial, used by the order form to
/// validate a buy against F10.
final availableRialProvider = Provider(
  (ref) => ref.watch(balanceProvider).valueOrNull?.rial.available,
);

final dailySummaryProvider = FutureProvider<DailySummary>(
  (ref) => ref.watch(dashboardRepositoryProvider).fetchDailySummary(),
);
