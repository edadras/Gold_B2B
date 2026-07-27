import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/features/orders/data/models/order.dart';
import 'package:gold_b2b/shared/models/paginated.dart';

/// Orders (docs/05-api/02-endpoints.md §2.5).
class OrderRepository {
  OrderRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Paginated<Order>> fetchOrders({
    List<String>? statuses,
    String? instrumentCode,
    String? cursor,
    int limit = Ui.defaultPageSize,
  }) =>
      _api.getList(
        '/orders',
        Order.fromJson,
        query: <String, dynamic>{
          'limit': limit,
          if (cursor != null) 'cursor': cursor,
          if (statuses != null && statuses.isNotEmpty)
            'filter[status]': statuses.join(','),
          if (instrumentCode != null) 'filter[instrument]': instrumentCode,
          'sort': '-placed_at',
        },
      );

  Future<Paginated<Order>> fetchOpenOrders({String? cursor}) => fetchOrders(
        statuses: const <String>[
          OrderStatus.pending,
          OrderStatus.open,
          OrderStatus.partiallyFilled,
        ],
        cursor: cursor,
      );

  Future<Order> fetchOrder(int id) =>
      _api.getOne('/orders/$id', Order.fromJson);

  /// `POST /orders` — 🔑 idempotent.
  ///
  /// [idempotencyKey] is REQUIRED, not optional, and the caller is expected to
  /// have minted it when the user opened the confirmation sheet. That is what
  /// makes the whole chain safe:
  ///
  ///   * the transport-level retry after a dropped connection reuses it;
  ///   * the replay after a token refresh reuses it;
  ///   * the user tapping "try again" on a timeout reuses it, because the
  ///     controller holds onto the same key until the order is known to have
  ///     been accepted.
  ///
  /// The server then returns the stored result with `X-Idempotent-Replay:
  /// true` instead of placing a second order.
  Future<Order> place(
    PlaceOrderRequest request, {
    required IdempotencyKey idempotencyKey,
  }) =>
      _api.postOne(
        '/orders',
        Order.fromJson,
        body: request.toJson(),
        idempotencyKey: idempotencyKey,
      );

  /// `POST /orders/{id}/cancel` — 🔑 idempotent.
  ///
  /// Cancelling twice must not be an error, which is exactly what the
  /// idempotency key buys: a retry of a cancel whose response was lost
  /// replays the original result rather than returning
  /// `ORDER_NOT_CANCELLABLE` for an order that is already cancelled.
  Future<void> cancel(
    int orderId, {
    required IdempotencyKey idempotencyKey,
  }) =>
      _api.postVoid(
        '/orders/$orderId/cancel',
        idempotencyKey: idempotencyKey,
      );

  Future<void> cancelAll({
    required IdempotencyKey idempotencyKey,
    String? instrumentCode,
  }) =>
      _api.postVoid(
        '/orders/cancel-all',
        body: <String, dynamic>{
          if (instrumentCode != null) 'instrument': instrumentCode,
        },
        idempotencyKey: idempotencyKey,
      );

  Future<List<Fill>> fetchFills(int orderId) async {
    final page = await _api.getList('/orders/$orderId/fills', Fill.fromJson);

    return page.items;
  }
}

final orderRepositoryProvider = Provider<OrderRepository>(
  (ref) => OrderRepository(api: ref.watch(apiClientProvider)),
);
