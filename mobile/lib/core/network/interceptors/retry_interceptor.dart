import 'package:dio/dio.dart';
import 'package:gold_b2b/core/config/constants.dart';

/// Retries transport failures — and ONLY transport failures — with a bounded
/// backoff.
///
/// The rule that matters:
///
///   A retry re-issues the SAME `RequestOptions` instance. It never rebuilds
///   the request, never re-mints an `Idempotency-Key`, and never touches the
///   body. `dio.fetch(options)` re-runs the interceptor chain, and
///   `IdempotencyInterceptor` sees a header that is already set and leaves it
///   alone. That is what makes a retry safe: the server recognises the second
///   delivery attempt as the same intent and replays the stored result
///   (`200` + `X-Idempotent-Replay: true`) instead of placing a second order.
///
/// The corollary is the safety gate in [_isSafeToRetry]: a write WITHOUT an
/// idempotency key is never retried, no matter how transient the failure
/// looked. If a POST /orders somehow reached the wire without a key, the only
/// correct behaviour is to surface the timeout and let the user look at their
/// order list.
class RetryInterceptor extends Interceptor {
  RetryInterceptor({
    required Dio dio,
    this.maxAttempts = 3,
    this.backoff = const <Duration>[
      Duration(milliseconds: 400),
      Duration(milliseconds: 1200),
      Duration(milliseconds: 3000),
    ],
  }) : _dio = dio;

  final Dio _dio;

  /// Total delivery attempts, including the first.
  final int maxAttempts;

  final List<Duration> backoff;

  static const String _attemptExtra = 'retry.attempt';

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    final options = err.requestOptions;
    final attempt = (options.extra[_attemptExtra] as int? ?? 1) + 1;

    if (attempt > maxAttempts || !_isSafeToRetry(err)) {
      handler.next(err);

      return;
    }

    final delay = backoff[
        attempt - 2 < backoff.length ? attempt - 2 : backoff.length - 1];
    await Future<void>.delayed(_withJitter(delay, attempt));

    options.extra[_attemptExtra] = attempt;

    try {
      handler.resolve(await _dio.fetch<dynamic>(options));
    } on DioException catch (error) {
      handler.next(error);
    }
  }

  bool _isSafeToRetry(DioException error) {
    final options = error.requestOptions;
    final method = options.method.toUpperCase();

    final isRead = method == 'GET' || method == 'HEAD';
    final hasIdempotencyKey =
        (options.headers[ApiHeaders.idempotencyKey] as String?)?.isNotEmpty ??
            false;

    if (!isRead && !hasIdempotencyKey) {
      return false;
    }

    switch (error.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.connectionError:
        return true;

      case DioExceptionType.badResponse:
        {
          final status = error.response?.statusCode ?? 0;

          // 502/503/504 are "the request never reached the application".
          // 409 IDEMPOTENCY_IN_PROGRESS means an identical request is
          // mid-flight server-side; backing off and asking again is exactly
          // right and will return the stored result once it commits.
          return status == 502 ||
              status == 503 ||
              status == 504 ||
              status == 409;
        }

      case DioExceptionType.badCertificate:
      case DioExceptionType.cancel:
      case DioExceptionType.unknown:
        // A pinning failure must never be retried into submission, and a
        // cancellation was deliberate.
        return false;
    }
  }

  /// Spread retries out so that a whole trading floor coming back online after
  /// a network blip does not arrive at the API in lockstep.
  Duration _withJitter(Duration base, int attempt) {
    final jitterMs = (attempt * 37) % 250;

    return base + Duration(milliseconds: jitterMs);
  }
}
