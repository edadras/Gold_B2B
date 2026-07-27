import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/core/realtime/websocket_service.dart';
import 'package:gold_b2b/features/dashboard/application/balance_controller.dart';
import 'package:gold_b2b/features/orders/data/models/order.dart';
import 'package:gold_b2b/features/orders/data/order_repository.dart';
import 'package:gold_b2b/shared/models/paginated.dart';

/// Open orders, kept live by `order.updated`.
///
/// The list is seeded from REST and patched by the socket. On reconnect,
/// `realtime_sync.dart` calls [refresh] — see
/// docs/05-api/03-realtime-webhooks.md §3.4: a fill that arrived while the
/// socket was down would otherwise leave an order showing as OPEN forever,
/// and the user would try to cancel gold that is already sold.
class OpenOrdersController extends StateNotifier<AsyncValue<List<Order>>> {
  OpenOrdersController({
    required OrderRepository repository,
    required WebSocketService realtime,
    required this.onBalanceMayHaveChanged,
  })  : _repository = repository,
        super(const AsyncValue<List<Order>>.loading()) {
    _events =
        realtime.on(RealtimeEvents.orderUpdated).listen(_applyRealtimeUpdate);
    unawaited(refresh());
  }

  final OrderRepository _repository;

  /// Invoked whenever something happened that must have moved the ledger.
  final void Function() onBalanceMayHaveChanged;

  StreamSubscription<RealtimeEvent>? _events;

  Future<void> refresh() async {
    final next = await AsyncValue.guard(() async {
      final page = await _repository.fetchOpenOrders();

      return page.items;
    });

    if (mounted) {
      state = next;
    }
  }

  /// Cancel with an optimistic state change.
  ///
  /// The optimistic step marks the order `CANCELLING` rather than removing it.
  /// Removing it would claim the gold is free the instant the button is
  /// tapped, which is not true until the server says so — and if the request
  /// fails, the user would have to hunt for an order they believe is gone.
  Future<void> cancel(int orderId) async {
    final previous = state.valueOrNull;
    if (previous == null) {
      return;
    }

    state = AsyncValue<List<Order>>.data(
      previous
          .map(
            (order) => order.id == orderId
                ? order.copyWith(status: OrderStatus.cancelling)
                : order,
          )
          .toList(growable: false),
    );

    try {
      // Minted here, at the moment of intent, so the retry inside the network
      // layer reuses it (see RetryInterceptor).
      await _repository.cancel(orderId, idempotencyKey: IdempotencyKey.mint());
      onBalanceMayHaveChanged();
      await refresh();
    } on AppFailure {
      if (mounted) {
        state = AsyncValue<List<Order>>.data(previous);
      }
      rethrow;
    }
  }

  /// Insert a freshly placed order at the top without waiting for a refresh.
  void prepend(Order order) {
    final current = state.valueOrNull ?? const <Order>[];
    if (!order.isOpen) {
      // A MARKET order can come back already FILLED; it belongs in history,
      // not in the open list.
      return;
    }

    state = AsyncValue<List<Order>>.data(<Order>[order, ...current]);
  }

  void _applyRealtimeUpdate(RealtimeEvent event) {
    final current = state.valueOrNull;
    if (current == null) {
      return;
    }

    final id = event.data['id'];
    if (id is! num) {
      return;
    }

    final updated = <Order>[];
    var touched = false;

    for (final order in current) {
      if (order.id != id.toInt()) {
        updated.add(order);

        continue;
      }

      touched = true;
      final next = order.applyRealtimeUpdate(event.data);
      if (next.isOpen) {
        updated.add(next);
      }
    }

    if (!touched) {
      // An order we do not know about changed — most likely one placed on
      // another device. Re-read rather than guess.
      unawaited(refresh());

      return;
    }

    state = AsyncValue<List<Order>>.data(updated);
    onBalanceMayHaveChanged();
  }

  @override
  void dispose() {
    unawaited(_events?.cancel());
    super.dispose();
  }
}

final openOrdersProvider =
    StateNotifierProvider<OpenOrdersController, AsyncValue<List<Order>>>(
  (ref) => OpenOrdersController(
    repository: ref.watch(orderRepositoryProvider),
    realtime: ref.watch(webSocketServiceProvider),
    onBalanceMayHaveChanged: () =>
        unawaited(ref.read(balanceProvider.notifier).refreshQuietly()),
  ),
);

/// Paged order history.
///
/// Separate from [openOrdersProvider] because it has entirely different
/// semantics: history is immutable, arbitrarily long, and does not need to be
/// live. Merging the two would mean either paginating live data or holding
/// the whole history in memory.
class OrderHistoryController extends StateNotifier<AsyncValue<Paginated<Order>>> {
  OrderHistoryController({required OrderRepository repository})
      : _repository = repository,
        super(const AsyncValue<Paginated<Order>>.loading()) {
    unawaited(refresh());
  }

  final OrderRepository _repository;
  bool _loadingMore = false;

  Future<void> refresh() async {
    final next = await AsyncValue.guard(
      () => _repository.fetchOrders(limit: Ui.defaultPageSize),
    );

    if (mounted) {
      state = next;
    }
  }

  Future<void> loadMore() async {
    final current = state.valueOrNull;
    if (_loadingMore || current == null || !current.hasMore) {
      return;
    }

    _loadingMore = true;
    try {
      final next = await _repository.fetchOrders(cursor: current.nextCursor);
      if (mounted) {
        state = AsyncValue<Paginated<Order>>.data(current.followedBy(next));
      }
    } on AppFailure {
      // Leave the already-loaded page on screen; the footer shows a retry.
      rethrow;
    } finally {
      _loadingMore = false;
    }
  }
}

final orderHistoryProvider = StateNotifierProvider<OrderHistoryController,
    AsyncValue<Paginated<Order>>>(
  (ref) => OrderHistoryController(
    repository: ref.watch(orderRepositoryProvider),
  ),
);

final orderDetailProvider = FutureProvider.family<Order, int>(
  (ref, id) => ref.watch(orderRepositoryProvider).fetchOrder(id),
);
