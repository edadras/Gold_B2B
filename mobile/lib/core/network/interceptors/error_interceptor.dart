import 'package:dio/dio.dart';
import 'package:gold_b2b/core/network/api_exception.dart';

/// The last interceptor in the chain: turns whatever Dio produced into a
/// typed [AppFailure] and hangs it on `DioException.error`.
///
/// It must run AFTER [AuthInterceptor] and [RetryInterceptor], both of which
/// need to inspect the raw status code and `DioExceptionType`. By the time an
/// exception leaves `ApiClient`, no part of the app above the network layer
/// ever sees a `DioException` again.
class ErrorInterceptor extends Interceptor {
  ErrorInterceptor();

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    if (err.error is AppFailure) {
      handler.next(err);

      return;
    }

    handler.next(
      DioException(
        requestOptions: err.requestOptions,
        response: err.response,
        type: err.type,
        error: failureFromDioException(err),
        stackTrace: err.stackTrace,
        message: err.message,
      ),
    );
  }
}
