import 'dart:async';

import 'package:dio/dio.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/storage/secure_storage.dart';

/// Attaches the bearer token, and transparently refreshes it once on a 401.
///
/// Extends [QueuedInterceptor] so that when five screens fire requests at the
/// same moment and all five come back 401, the refresh endpoint is called
/// once rather than five times — a burst of refreshes is indistinguishable
/// from a stolen-token replay and will get the account locked.
///
/// docs/07-mobile-flutter/01-architecture.md §1.6.
class AuthInterceptor extends QueuedInterceptor {
  AuthInterceptor({
    required SecureStorage storage,
    required Dio dio,
    required Dio refreshDio,
    required Future<void> Function() onSessionEnded,
  })  : _storage = storage,
        _dio = dio,
        _refreshDio = refreshDio,
        _onSessionEnded = onSessionEnded;

  final SecureStorage _storage;

  /// The fully configured client, used to replay the original request.
  final Dio _dio;

  /// A BARE client with no interceptors, used only for `POST /auth/refresh`.
  ///
  /// Refreshing through [_dio] would re-enter this interceptor: the refresh
  /// call would carry the dead access token, come back 401, and trigger
  /// another refresh, forever.
  final Dio _refreshDio;

  final Future<void> Function() _onSessionEnded;

  /// Marks a request that has already been replayed once after a refresh. A
  /// second 401 on the same request means the new token is not the problem.
  static const String _replayedExtra = 'auth.replayed_after_refresh';

  /// Endpoints that must not carry (or require) a bearer token.
  static const Set<String> _publicPathFragments = <String>{
    '/auth/login',
    '/auth/register',
    '/auth/otp/',
    '/auth/refresh',
    '/auth/password/forgot',
    '/auth/password/reset',
    '/verify/',
    '/health',
    '/openapi.json',
  };

  Future<String?>? _inFlightRefresh;

  static bool _isPublic(String path) =>
      _publicPathFragments.any((fragment) => path.contains(fragment));

  @override
  void onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    if (_isPublic(options.path)) {
      handler.next(options);

      return;
    }

    final token = await _storage.readAccessToken();
    if (token != null && token.isNotEmpty) {
      options.headers[ApiHeaders.authorization] = 'Bearer $token';
    }

    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    final status = err.response?.statusCode;
    final options = err.requestOptions;

    if (status != 401 || _isPublic(options.path)) {
      handler.next(err);

      return;
    }

    if (options.extra[_replayedExtra] == true) {
      // Already refreshed once for this request and still unauthorised.
      await _endSession();
      handler.next(err);

      return;
    }

    final refreshed = await _refreshOnce();
    if (refreshed == null) {
      await _endSession();
      handler.next(err);

      return;
    }

    // Replay the ORIGINAL RequestOptions object. Everything else about it —
    // body, query, and critically the Idempotency-Key header minted when the
    // user first tapped the button — is carried over untouched, so the server
    // recognises this as the same intent rather than a second order.
    options.headers[ApiHeaders.authorization] = 'Bearer $refreshed';
    options.extra[_replayedExtra] = true;

    try {
      handler.resolve(await _dio.fetch<dynamic>(options));
    } on DioException catch (error) {
      handler.next(error);
    }
  }

  /// Collapses concurrent refreshes into one network call.
  Future<String?> _refreshOnce() {
    final pending = _inFlightRefresh;
    if (pending != null) {
      return pending;
    }

    final started = _performRefresh();
    _inFlightRefresh = started;
    unawaited(started.whenComplete(() => _inFlightRefresh = null));

    return started;
  }

  Future<String?> _performRefresh() async {
    final refreshToken = await _storage.readRefreshToken();
    if (refreshToken == null || refreshToken.isEmpty) {
      return null;
    }

    try {
      final response = await _refreshDio.post<dynamic>(
        '/auth/refresh',
        data: <String, dynamic>{'refresh_token': refreshToken},
      );

      final body = response.data;
      if (body is! Map<String, dynamic>) {
        return null;
      }

      final data = body['data'];
      if (data is! Map<String, dynamic>) {
        return null;
      }

      final accessToken = data['access_token'];
      if (accessToken is! String || accessToken.isEmpty) {
        return null;
      }

      final newRefreshToken = data['refresh_token'];
      final expiresIn = data['expires_in'];

      if (newRefreshToken is String && newRefreshToken.isNotEmpty) {
        // Rotating refresh tokens: persist both or the next refresh fails.
        await _storage.writeSession(
          accessToken: accessToken,
          refreshToken: newRefreshToken,
          expiresIn: expiresIn is num ? expiresIn.toInt() : 900,
        );
      } else {
        await _storage.writeAccessToken(
          accessToken,
          expiresIn: expiresIn is num ? expiresIn.toInt() : null,
        );
      }

      return accessToken;
    } on DioException {
      return null;
    }
  }

  Future<void> _endSession() async {
    await _storage.clearSession();
    await _onSessionEnded();
  }
}
