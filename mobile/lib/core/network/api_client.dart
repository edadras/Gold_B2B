import 'package:dio/dio.dart';
import 'package:gold_b2b/core/config/app_config.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/connectivity/connectivity_monitor.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/network/certificate_pinning.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/core/network/interceptors/auth_interceptor.dart';
import 'package:gold_b2b/core/network/interceptors/error_interceptor.dart';
import 'package:gold_b2b/core/network/interceptors/idempotency_interceptor.dart';
import 'package:gold_b2b/core/network/interceptors/logging_interceptor.dart';
import 'package:gold_b2b/core/network/interceptors/retry_interceptor.dart';
import 'package:gold_b2b/core/storage/secure_storage.dart';
import 'package:gold_b2b/shared/models/paginated.dart';

/// The single HTTP entry point.
///
/// Repositories talk to this and nothing else; nothing above the network layer
/// imports `package:dio`. Every method here either returns a parsed value or
/// throws an [AppFailure] — a `DioException` never escapes.
class ApiClient {
  ApiClient({
    required AppConfig config,
    required SecureStorage storage,
    required Future<void> Function() onSessionEnded,
    ConnectivityMonitor? connectivity,
  })  : _config = config,
        _dio = Dio(_baseOptions(config)) {
    final refreshDio = Dio(_baseOptions(config));

    if (config.pinningEnabled) {
      final adapter = CertificatePinning.adapter(config.certificateFingerprints);
      _dio.httpClientAdapter = adapter;
      refreshDio.httpClientAdapter = adapter;
    }

    // ORDER MATTERS.
    //
    //   auth        must see the raw 401 before anything rewrites it, and must
    //               attach the token before the request is sent.
    //   idempotency must run before the request leaves, and must be a no-op on
    //               replays (it is — it checks for an existing header).
    //   retry       must see the raw DioExceptionType, so it precedes error.
    //   error       converts everything to AppFailure.
    //   connectivity follows error, so it sees a typed failure and only after
    //               retry has given up.
    //   logging     is appended last so it observes the final state of both
    //               the request and the failure.
    _dio.interceptors.addAll(<Interceptor>[
      AuthInterceptor(
        storage: storage,
        dio: _dio,
        refreshDio: refreshDio,
        onSessionEnded: onSessionEnded,
      ),
      IdempotencyInterceptor(),
      RetryInterceptor(dio: _dio, maxAttempts: config.maxRetryAttempts),
      ErrorInterceptor(),
      if (connectivity != null) ConnectivityInterceptor(connectivity),
      if (config.isDebug) LoggingInterceptor(),
    ]);
  }

  final AppConfig _config;
  final Dio _dio;

  AppConfig get config => _config;

  /// Escape hatch for the WebSocket channel authoriser, which needs the raw
  /// response body of `POST /broadcasting/auth`. Nothing else should use it.
  Dio get raw => _dio;

  static BaseOptions _baseOptions(AppConfig config) => BaseOptions(
        baseUrl: config.apiBaseUrl,
        connectTimeout: config.connectTimeout,
        receiveTimeout: config.receiveTimeout,
        sendTimeout: config.connectTimeout,
        headers: <String, dynamic>{
          ApiHeaders.accept: 'application/json',
          ApiHeaders.acceptLanguage: 'fa',
          ApiHeaders.clientVersion: config.clientVersion,
        },
        contentType: 'application/json',
        responseType: ResponseType.json,
        // Never let Dio throw on a status by itself; the interceptor chain
        // decides what each status means.
        validateStatus: (status) => status != null && status < 400,
      );

  // --- reads ---------------------------------------------------------------

  /// `GET` returning a single resource from `data`.
  Future<T> getOne<T>(
    String path,
    T Function(Map<String, dynamic> json) parse, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    final envelope = await _send(
      method: 'GET',
      path: path,
      query: query,
      cancelToken: cancelToken,
    );

    return parse(_requireDataMap(envelope, path));
  }

  /// `GET` returning a list from `data`, plus cursors from `links`.
  Future<Paginated<T>> getList<T>(
    String path,
    T Function(Map<String, dynamic> json) parse, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    final envelope = await _send(
      method: 'GET',
      path: path,
      query: query,
      cancelToken: cancelToken,
    );

    final data = envelope['data'];
    if (data is! List) {
      throw UnexpectedFailure(
        'Expected a list at "data" for $path',
        requestId: _requestIdOf(envelope),
      );
    }

    final links = envelope['links'];
    final meta = envelope['meta'];

    return Paginated<T>(
      items: data
          .whereType<Map<String, dynamic>>()
          .map(parse)
          .toList(growable: false),
      nextCursor: _cursorFrom(links, 'next'),
      previousCursor: _cursorFrom(links, 'prev'),
      count: meta is Map<String, dynamic> && meta['count'] is num
          ? (meta['count'] as num).toInt()
          : null,
    );
  }

  // --- writes --------------------------------------------------------------

  /// `POST` returning a single resource.
  ///
  /// [idempotencyKey] is REQUIRED for every financial write. Mint it in the
  /// controller when the user's intent is formed — `IdempotencyKey.mint()` —
  /// and hold onto it, so that a manual "try again" after a timeout reuses it.
  /// If it is omitted, `IdempotencyInterceptor` will mint one for the paths
  /// that need it, which protects against the transport-level retry but NOT
  /// against the user tapping the button twice.
  Future<T> postOne<T>(
    String path,
    T Function(Map<String, dynamic> json) parse, {
    Object? body,
    IdempotencyKey? idempotencyKey,
    CancelToken? cancelToken,
  }) async {
    final envelope = await _send(
      method: 'POST',
      path: path,
      body: body,
      idempotencyKey: idempotencyKey,
      cancelToken: cancelToken,
    );

    return parse(_requireDataMap(envelope, path));
  }

  /// `POST` where the response body is irrelevant (`204`, or an ack).
  Future<void> postVoid(
    String path, {
    Object? body,
    IdempotencyKey? idempotencyKey,
    CancelToken? cancelToken,
  }) async {
    await _send(
      method: 'POST',
      path: path,
      body: body,
      idempotencyKey: idempotencyKey,
      cancelToken: cancelToken,
    );
  }

  /// `POST` returning the raw envelope, for endpoints whose shape does not fit
  /// the `data` convention (`/broadcasting/auth`).
  Future<Map<String, dynamic>> postRaw(
    String path, {
    Object? body,
    CancelToken? cancelToken,
  }) =>
      _send(
        method: 'POST',
        path: path,
        body: body,
        cancelToken: cancelToken,
        unwrapEnvelope: false,
      );

  Future<T> putOne<T>(
    String path,
    T Function(Map<String, dynamic> json) parse, {
    Object? body,
    IdempotencyKey? idempotencyKey,
    CancelToken? cancelToken,
  }) async {
    final envelope = await _send(
      method: 'PUT',
      path: path,
      body: body,
      idempotencyKey: idempotencyKey,
      cancelToken: cancelToken,
    );

    return parse(_requireDataMap(envelope, path));
  }

  Future<void> deleteVoid(String path, {CancelToken? cancelToken}) async {
    await _send(method: 'DELETE', path: path, cancelToken: cancelToken);
  }

  /// Multipart upload — payment receipts and KYC-adjacent documents.
  Future<T> upload<T>(
    String path,
    T Function(Map<String, dynamic> json) parse, {
    required FormData form,
    IdempotencyKey? idempotencyKey,
    CancelToken? cancelToken,
    void Function(int sent, int total)? onProgress,
  }) async {
    try {
      final response = await _dio.post<dynamic>(
        path,
        data: form,
        cancelToken: cancelToken,
        onSendProgress: onProgress,
        options: Options(
          headers: <String, dynamic>{
            if (idempotencyKey != null)
              ApiHeaders.idempotencyKey: idempotencyKey.value,
          },
          contentType: 'multipart/form-data',
          // Receipt images are slow on a 3G connection in a bazaar.
          sendTimeout: const Duration(seconds: 90),
        ),
      );

      return parse(_requireDataMap(_asEnvelope(response, path), path));
    } on DioException catch (error) {
      throw _translate(error);
    }
  }

  // --- plumbing ------------------------------------------------------------

  Future<Map<String, dynamic>> _send({
    required String method,
    required String path,
    Object? body,
    Map<String, dynamic>? query,
    IdempotencyKey? idempotencyKey,
    CancelToken? cancelToken,
    bool unwrapEnvelope = true,
  }) async {
    try {
      final response = await _dio.request<dynamic>(
        path,
        data: body,
        queryParameters: query,
        cancelToken: cancelToken,
        options: Options(
          method: method,
          headers: <String, dynamic>{
            if (idempotencyKey != null)
              ApiHeaders.idempotencyKey: idempotencyKey.value,
            ApiHeaders.requestId: Uuid.v4(),
          },
        ),
      );

      return _asEnvelope(response, path);
    } on DioException catch (error) {
      throw _translate(error);
    }
  }

  Map<String, dynamic> _asEnvelope(Response<dynamic> response, String path) {
    final data = response.data;

    if (data == null || (data is String && data.trim().isEmpty)) {
      // 204 No Content — cancel, mark-as-read, delete.
      return const <String, dynamic>{};
    }

    if (data is Map<String, dynamic>) {
      return data;
    }

    throw UnexpectedFailure(
      'Expected a JSON object from $path, got ${data.runtimeType}',
    );
  }

  Map<String, dynamic> _requireDataMap(
    Map<String, dynamic> envelope,
    String path,
  ) {
    final data = envelope['data'];
    if (data is Map<String, dynamic>) {
      return data;
    }

    throw UnexpectedFailure(
      'Expected an object at "data" for $path',
      requestId: _requestIdOf(envelope),
    );
  }

  static String? _requestIdOf(Map<String, dynamic> envelope) {
    final meta = envelope['meta'];

    return meta is Map<String, dynamic> ? meta['request_id'] as String? : null;
  }

  /// Extract the opaque `cursor` query parameter from a pagination link.
  ///
  /// The server sends a whole URL (`/api/v1/orders?cursor=...&limit=50`);
  /// repositories only ever pass the cursor back, so that is all we keep.
  static String? _cursorFrom(Object? links, String key) {
    if (links is! Map<String, dynamic>) {
      return null;
    }

    final href = links[key];
    if (href is! String || href.isEmpty) {
      return null;
    }

    return Uri.tryParse(href)?.queryParameters['cursor'];
  }

  AppFailure _translate(DioException error) {
    final inner = error.error;

    return inner is AppFailure ? inner : failureFromDioException(error);
  }

  void close() => _dio.close(force: true);
}
