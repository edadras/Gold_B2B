import 'package:dio/dio.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/network/idempotency.dart';

/// Guarantees that every financial write carries an `Idempotency-Key`, and
/// that the key is minted exactly once per user intent.
///
/// docs/05-api/01-conventions.md §1.10, and the emphatic warning in
/// docs/07-mobile-flutter/01-architecture.md §1.6:
///
///   "If a request is retried because of a network error, the SAME key must be
///    used, not a new one. RetryInterceptor must not change the key."
///
/// Three things can happen to a request before it reaches the wire, and all
/// three must end up with the same key:
///
///   1. The repository minted a key up front (the preferred path — see
///      `ApiClient.postOne(idempotencyKey: ...)`), so the header is already
///      set and this interceptor leaves it alone.
///   2. `RetryInterceptor` re-issues the request through `dio.fetch`, which
///      re-runs this interceptor. The header survives on the same
///      `RequestOptions` instance, so branch 1 applies again.
///   3. `AuthInterceptor` replays the request after a token refresh. Same
///      instance, same header.
///
/// The `extra` copy exists purely as a belt-and-braces recovery for any future
/// code path that rebuilds headers from scratch.
class IdempotencyInterceptor extends Interceptor {
  IdempotencyInterceptor();

  /// Key under which the minted value is mirrored into `RequestOptions.extra`.
  static const String extraKey = 'idempotency.key';

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final method = options.method.toUpperCase();
    if (method != 'POST' && method != 'PUT') {
      handler.next(options);

      return;
    }

    final existing = options.headers[ApiHeaders.idempotencyKey];
    if (existing is String && existing.isNotEmpty) {
      options.extra[extraKey] = existing;
      handler.next(options);

      return;
    }

    final carried = options.extra[extraKey];
    if (carried is String && carried.isNotEmpty) {
      options.headers[ApiHeaders.idempotencyKey] = carried;
      handler.next(options);

      return;
    }

    if (IdempotentPaths.requiresKey(options.path)) {
      final key = IdempotencyKey.mint();
      options.headers[ApiHeaders.idempotencyKey] = key.value;
      options.extra[extraKey] = key.value;
    }

    handler.next(options);
  }
}
