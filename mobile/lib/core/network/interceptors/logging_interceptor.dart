import 'dart:developer' as developer;

import 'package:dio/dio.dart';
import 'package:gold_b2b/core/config/constants.dart';

/// Debug-build request logging with mandatory redaction.
///
/// This interceptor is only ever installed when `AppConfig.isDebug` is true,
/// but it redacts regardless. Bearer tokens, OTP codes, TOTP codes, passwords
/// and payment references are the four things that must never reach a log
/// file, a crash report or a screen-shared terminal, and "it is only the dev
/// build" is not a defence when the dev build is what gets demoed.
class LoggingInterceptor extends Interceptor {
  LoggingInterceptor();

  static const Set<String> _redactedHeaders = <String>{
    'authorization',
    'cookie',
    'set-cookie',
    'x-csrf-token',
  };

  static const Set<String> _redactedBodyFields = <String>{
    'password',
    'password_confirmation',
    'current_password',
    'code',
    'otp',
    'totp',
    'access_token',
    'refresh_token',
    'challenge_token',
    'secret',
    'auth',
  };

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final key = options.headers[ApiHeaders.idempotencyKey];

    developer.log(
      '--> ${options.method} ${options.uri}'
      '${key == null ? '' : '  [idem ${_shorten('$key')}]'}'
      '\n    headers: ${_redactHeaders(options.headers)}'
      '\n    body:    ${_redactBody(options.data)}',
      name: 'api',
    );

    handler.next(options);
  }

  @override
  void onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) {
    final replayed = response.headers.value(ApiHeaders.idempotentReplay);

    developer.log(
      '<-- ${response.statusCode} ${response.requestOptions.method} '
      '${response.requestOptions.uri}'
      '${replayed == 'true' ? '  [IDEMPOTENT REPLAY]' : ''}',
      name: 'api',
    );

    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    developer.log(
      '<-- ERROR ${err.response?.statusCode ?? err.type.name} '
      '${err.requestOptions.method} ${err.requestOptions.uri}'
      '\n    ${err.error ?? err.message}',
      name: 'api',
    );

    handler.next(err);
  }

  Map<String, Object?> _redactHeaders(Map<String, dynamic> headers) {
    final result = <String, Object?>{};

    headers.forEach((key, value) {
      result[key] =
          _redactedHeaders.contains(key.toLowerCase()) ? '<redacted>' : value;
    });

    return result;
  }

  Object? _redactBody(Object? body) {
    if (body is! Map) {
      // FormData (receipt uploads) and raw strings are never logged: the first
      // is large and binary, the second may be anything.
      return body == null ? null : '<${body.runtimeType}>';
    }

    final result = <String, Object?>{};
    body.forEach((key, value) {
      final name = '$key';
      if (_redactedBodyFields.contains(name.toLowerCase())) {
        result[name] = '<redacted>';
      } else if (value is Map) {
        result[name] = _redactBody(value);
      } else {
        result[name] = value;
      }
    });

    return result;
  }

  static String _shorten(String value) =>
      value.length <= 8 ? value : '${value.substring(0, 8)}…';
}
