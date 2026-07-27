import 'package:dio/dio.dart';

/// Business error codes from docs/05-api/01-conventions.md §1.7.
///
/// These are string constants rather than an enum on purpose: §1.11 lists
/// "adding a new enum value to a response" as a NON-breaking change, so the
/// client must be able to receive a code it has never heard of, show the
/// server-provided Persian message, and carry on. An enum would throw.
final class ApiErrorCode {
  const ApiErrorCode._();

  // Authentication and access
  static const String authInvalidCredentials = 'AUTH_INVALID_CREDENTIALS';
  static const String authTokenExpired = 'AUTH_TOKEN_EXPIRED';
  static const String authTokenInvalid = 'AUTH_TOKEN_INVALID';
  static const String auth2faRequired = 'AUTH_2FA_REQUIRED';
  static const String auth2faInvalid = 'AUTH_2FA_INVALID';
  static const String authAccountLocked = 'AUTH_ACCOUNT_LOCKED';
  static const String authTransactionSignRequired =
      'AUTH_TRANSACTION_SIGN_REQUIRED';
  static const String forbiddenRole = 'FORBIDDEN_ROLE';
  static const String forbiddenTenant = 'FORBIDDEN_TENANT';
  static const String forbiddenDualControl = 'FORBIDDEN_DUAL_CONTROL';

  // Organisation status
  static const String orgNotActive = 'ORG_NOT_ACTIVE';
  static const String orgSuspended = 'ORG_SUSPENDED';
  static const String orgRestricted = 'ORG_RESTRICTED';
  static const String orgLicenseExpired = 'ORG_LICENSE_EXPIRED';
  static const String orgKycIncomplete = 'ORG_KYC_INCOMPLETE';

  // Trading
  static const String marketClosed = 'MARKET_CLOSED';
  static const String marketPaused = 'MARKET_PAUSED';
  static const String instrumentNotActive = 'INSTRUMENT_NOT_ACTIVE';
  static const String orderQuantityTooSmall = 'ORDER_QUANTITY_TOO_SMALL';
  static const String orderQuantityTooLarge = 'ORDER_QUANTITY_TOO_LARGE';
  static const String orderPriceInvalidTick = 'ORDER_PRICE_INVALID_TICK';
  static const String orderPriceOutOfRange = 'ORDER_PRICE_OUT_OF_RANGE';
  static const String orderNotFound = 'ORDER_NOT_FOUND';
  static const String orderNotCancellable = 'ORDER_NOT_CANCELLABLE';
  static const String selfTradePrevented = 'SELF_TRADE_PREVENTED';

  // Ledger and balances
  static const String insufficientBalance = 'INSUFFICIENT_BALANCE';
  static const String insufficientGold = 'INSUFFICIENT_GOLD';
  static const String insufficientRial = 'INSUFFICIENT_RIAL';
  static const String balanceLocked = 'BALANCE_LOCKED';
  static const String ledgerAccountFrozen = 'LEDGER_ACCOUNT_FROZEN';

  // Risk
  static const String limitPerOrderExceeded = 'LIMIT_PER_ORDER_EXCEEDED';
  static const String limitDailyVolumeExceeded = 'LIMIT_DAILY_VOLUME_EXCEEDED';
  static const String limitOpenOrdersExceeded = 'LIMIT_OPEN_ORDERS_EXCEEDED';
  static const String limitExposureExceeded = 'LIMIT_EXPOSURE_EXCEEDED';
  static const String limitCounterpartyExceeded = 'LIMIT_COUNTERPARTY_EXCEEDED';
  static const String settlementTypeNotAllowed = 'SETTLEMENT_TYPE_NOT_ALLOWED';
  static const String tradingNotAllowed = 'TRADING_NOT_ALLOWED';

  // Settlement
  static const String settlementNotFound = 'SETTLEMENT_NOT_FOUND';
  static const String settlementWrongState = 'SETTLEMENT_WRONG_STATE';
  static const String settlementNotYourTurn = 'SETTLEMENT_NOT_YOUR_TURN';
  static const String settlementAlreadyConfirmed =
      'SETTLEMENT_ALREADY_CONFIRMED';
  static const String settlementOverdue = 'SETTLEMENT_OVERDUE';

  // Vault
  static const String lotNotFound = 'LOT_NOT_FOUND';
  static const String lotNotAvailable = 'LOT_NOT_AVAILABLE';
  static const String lotNotOwned = 'LOT_NOT_OWNED';
  static const String lotOnHold = 'LOT_ON_HOLD';
  static const String vaultWithdrawalBlocked = 'VAULT_WITHDRAWAL_BLOCKED';
  static const String assayRequired = 'ASSAY_REQUIRED';

  // General
  static const String validationFailed = 'VALIDATION_FAILED';
  static const String idempotencyInProgress = 'IDEMPOTENCY_IN_PROGRESS';
  static const String idempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
  static const String rateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
  static const String resourceNotFound = 'RESOURCE_NOT_FOUND';
  static const String internalError = 'INTERNAL_ERROR';
  static const String serviceUnavailable = 'SERVICE_UNAVAILABLE';

  /// Codes that mean "your session is no longer usable". The router sends the
  /// user back to login on any of these; they are the only ones that clear
  /// stored tokens.
  static const Set<String> sessionEnding = <String>{
    authTokenExpired,
    authTokenInvalid,
    authAccountLocked,
  };

  /// Codes that mean "the account cannot trade right now". These render as a
  /// full-screen explanation rather than a snackbar, because retrying will not
  /// help and the user needs to go to the web panel.
  static const Set<String> accountBlocking = <String>{
    orgNotActive,
    orgSuspended,
    orgRestricted,
    orgLicenseExpired,
    orgKycIncomplete,
    tradingNotAllowed,
  };
}

/// Root of every failure the UI has to render.
///
/// Sealed so that `ErrorView` can switch exhaustively and no failure mode can
/// be added without the presentation layer being forced to consider it.
sealed class AppFailure implements Exception {
  const AppFailure({this.requestId});

  /// `meta.request_id` when one was returned — the single most useful thing to
  /// show a user who is about to phone support.
  final String? requestId;

  /// Persian text safe to put in front of the user.
  String get userMessage;

  /// Whether offering a "retry" button makes any sense.
  bool get isRetryable;
}

/// No usable connection: DNS failure, refused socket, airplane mode.
final class NetworkFailure extends AppFailure {
  const NetworkFailure({this.cause});

  final String? cause;

  @override
  String get userMessage =>
      'اتصال اینترنت برقرار نیست. لطفاً اتصال خود را بررسی کنید.';

  @override
  bool get isRetryable => true;

  @override
  String toString() => 'NetworkFailure(${cause ?? 'no connection'})';
}

/// The request was sent but no response arrived in time.
///
/// This is the dangerous case for writes: the server may well have executed
/// the operation. It is precisely why every financial POST carries an
/// `Idempotency-Key` that survives the retry.
final class TimeoutFailure extends AppFailure {
  const TimeoutFailure({this.wasWrite = false});

  final bool wasWrite;

  @override
  String get userMessage => wasWrite
      ? 'پاسخی از سرور دریافت نشد. وضعیت این عملیات نامشخص است — '
          'پیش از تلاش مجدد، فهرست را بررسی کنید.'
      : 'سرور پاسخ نداد. لطفاً دوباره تلاش کنید.';

  @override
  bool get isRetryable => true;

  @override
  String toString() => 'TimeoutFailure(wasWrite: $wasWrite)';
}

/// The request was cancelled by the client (screen disposed, search superseded).
final class CancelledFailure extends AppFailure {
  const CancelledFailure();

  @override
  String get userMessage => 'درخواست لغو شد.';

  @override
  bool get isRetryable => false;
}

/// Certificate pinning rejected the server's certificate.
///
/// Never retryable and never silently downgraded: on a financial app this is
/// either a misconfiguration or an active interception attempt.
final class PinningFailure extends AppFailure {
  const PinningFailure(this.host);

  final String host;

  @override
  String get userMessage =>
      'اتصال امن به سرور برقرار نشد. ممکن است شبکه شما امن نباشد. '
      'لطفاً از شبکه دیگری استفاده کنید و با پشتیبانی تماس بگیرید.';

  @override
  bool get isRetryable => false;

  @override
  String toString() => 'PinningFailure($host)';
}

/// An action was attempted while the app knows it is offline or stale.
///
/// docs/07-mobile-flutter/01-architecture.md §1.9 — there is deliberately NO
/// offline write queue, so this is a terminal refusal, not a deferral.
final class OfflineActionFailure extends AppFailure {
  const OfflineActionFailure();

  @override
  String get userMessage =>
      'در حالت آفلاین امکان انجام عملیات مالی وجود ندارد. '
      'قیمت‌ها لحظه‌ای تغییر می‌کنند و اجرای سفارش قدیمی خطرناک است.';

  @override
  bool get isRetryable => false;
}

/// Something the client itself got wrong — a malformed response, a parse
/// error. Distinct from a server error because it needs a bug report, not a
/// retry.
final class UnexpectedFailure extends AppFailure {
  const UnexpectedFailure(this.detail, {super.requestId});

  final String detail;

  @override
  String get userMessage => 'خطای غیرمنتظره رخ داد.';

  @override
  bool get isRetryable => false;

  @override
  String toString() => 'UnexpectedFailure($detail)';
}

/// The server returned a well-formed error envelope (§1.5).
base class ApiException extends AppFailure {
  const ApiException({
    required this.code,
    required this.message,
    required this.statusCode,
    this.messageEn,
    this.details = const <String, dynamic>{},
    this.fieldErrors = const <String, List<String>>{},
    this.documentationUrl,
    super.requestId,
  });

  final String code;

  /// The Persian message the server produced. Always prefer this over any
  /// client-side text: the server knows the amounts and the limits.
  final String message;

  final String? messageEn;
  final Map<String, dynamic> details;
  final Map<String, List<String>> fieldErrors;
  final String? documentationUrl;
  final int statusCode;

  @override
  String get userMessage => message;

  @override
  bool get isRetryable =>
      statusCode >= 500 || code == ApiErrorCode.idempotencyInProgress;

  bool get endsSession => ApiErrorCode.sessionEnding.contains(code);

  bool get blocksAccount => ApiErrorCode.accountBlocking.contains(code);

  /// True when the failure is about the user's balance or limits rather than
  /// about the request being malformed — these get a "view balance" action.
  bool get isBalanceRelated => const <String>{
        ApiErrorCode.insufficientBalance,
        ApiErrorCode.insufficientGold,
        ApiErrorCode.insufficientRial,
        ApiErrorCode.balanceLocked,
      }.contains(code);

  bool get isLimitRelated => code.startsWith('LIMIT_');

  /// Parse the documented error envelope. Falls back to a generic
  /// [ApiException] when the body is not the expected shape (a proxy returning
  /// an HTML 502, for example).
  factory ApiException.fromEnvelope(
    Map<String, dynamic> body,
    int statusCode,
  ) {
    final error = body['error'];
    final meta = body['meta'];
    final requestId =
        meta is Map<String, dynamic> ? meta['request_id'] as String? : null;

    if (error is! Map<String, dynamic>) {
      return ApiException(
        code: _codeForStatus(statusCode),
        message: _messageForStatus(statusCode),
        statusCode: statusCode,
        requestId: requestId,
      );
    }

    final rawFieldErrors = error['field_errors'];
    final fieldErrors = <String, List<String>>{};
    if (rawFieldErrors is Map) {
      rawFieldErrors.forEach((key, value) {
        if (value is List) {
          fieldErrors['$key'] = value.map((e) => '$e').toList(growable: false);
        } else if (value != null) {
          fieldErrors['$key'] = <String>['$value'];
        }
      });
    }

    final details = error['details'];
    final code = error['code'] is String
        ? error['code'] as String
        : _codeForStatus(statusCode);

    return ApiException(
      code: code,
      message: error['message'] is String
          ? error['message'] as String
          : _messageForStatus(statusCode),
      messageEn: error['message_en'] as String?,
      details: details is Map<String, dynamic>
          ? details
          : const <String, dynamic>{},
      fieldErrors: fieldErrors,
      documentationUrl: error['documentation_url'] as String?,
      statusCode: statusCode,
      requestId: requestId,
    );
  }

  static String _codeForStatus(int status) => switch (status) {
        401 => ApiErrorCode.authTokenInvalid,
        403 => ApiErrorCode.forbiddenRole,
        404 => ApiErrorCode.resourceNotFound,
        409 => ApiErrorCode.idempotencyInProgress,
        429 => ApiErrorCode.rateLimitExceeded,
        503 => ApiErrorCode.serviceUnavailable,
        _ => ApiErrorCode.internalError,
      };

  static String _messageForStatus(int status) => switch (status) {
        400 => 'درخواست نامعتبر است.',
        401 => 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.',
        403 => 'شما مجاز به انجام این عملیات نیستید.',
        404 => 'مورد درخواستی یافت نشد.',
        409 => 'درخواست مشابهی در حال پردازش است.',
        410 => 'این مورد منقضی شده است.',
        423 => 'حساب شما قفل است.',
        429 => 'تعداد درخواست‌ها بیش از حد مجاز است. کمی صبر کنید.',
        503 => 'سرویس موقتاً در دسترس نیست.',
        _ => 'خطایی در سرور رخ داد.',
      };

  @override
  String toString() =>
      'ApiException($code, status=$statusCode, requestId=$requestId): $message';
}

/// 401 — the access token was rejected and refreshing did not help.
final class UnauthorizedException extends ApiException {
  const UnauthorizedException({
    required String code,
    required String message,
    String? messageEn,
    String? requestId,
  }) : super(
          code: code,
          message: message,
          messageEn: messageEn,
          statusCode: 401,
          requestId: requestId,
        );

  @override
  bool get isRetryable => false;
}

/// 422 with `VALIDATION_FAILED` — [fieldErrors] maps request field names to
/// Persian messages and is wired straight into the form.
final class ValidationException extends ApiException {
  const ValidationException({
    required String message,
    required Map<String, List<String>> fieldErrors,
    String? requestId,
  }) : super(
          code: ApiErrorCode.validationFailed,
          message: message,
          fieldErrors: fieldErrors,
          statusCode: 422,
          requestId: requestId,
        );

  @override
  bool get isRetryable => false;

  /// First message for [field], or null.
  String? forField(String field) {
    final messages = fieldErrors[field];

    return (messages == null || messages.isEmpty) ? null : messages.first;
  }
}

/// 429 — includes how long to wait, when the server said.
final class RateLimitException extends ApiException {
  const RateLimitException({
    required String message,
    this.retryAfter,
    String? requestId,
  }) : super(
          code: ApiErrorCode.rateLimitExceeded,
          message: message,
          statusCode: 429,
          requestId: requestId,
        );

  final Duration? retryAfter;

  @override
  bool get isRetryable => true;
}

/// Translate anything Dio can throw into an [AppFailure].
///
/// Called from `ErrorInterceptor`; exposed separately so that the WebSocket
/// authoriser, which uses the same Dio instance, can reuse it.
AppFailure failureFromDioException(DioException error) {
  switch (error.type) {
    case DioExceptionType.connectionTimeout:
    case DioExceptionType.sendTimeout:
    case DioExceptionType.receiveTimeout:
      {
        return TimeoutFailure(
          wasWrite: error.requestOptions.method.toUpperCase() != 'GET',
        );
      }

    case DioExceptionType.cancel:
      {
        return const CancelledFailure();
      }

    case DioExceptionType.connectionError:
      {
        return NetworkFailure(cause: error.message);
      }

    case DioExceptionType.badCertificate:
      {
        return PinningFailure(error.requestOptions.uri.host);
      }

    case DioExceptionType.unknown:
      {
        final inner = error.error;
        if (inner is AppFailure) {
          return inner;
        }

        return NetworkFailure(cause: error.message ?? '$inner');
      }

    case DioExceptionType.badResponse:
      final response = error.response;
      final status = response?.statusCode ?? 0;
      final data = response?.data;

      final body = data is Map<String, dynamic> ? data : <String, dynamic>{};
      final parsed = ApiException.fromEnvelope(body, status);

      if (status == 401) {
        return UnauthorizedException(
          code: parsed.code,
          message: parsed.message,
          messageEn: parsed.messageEn,
          requestId: parsed.requestId,
        );
      }

      if (status == 422 && parsed.code == ApiErrorCode.validationFailed) {
        return ValidationException(
          message: parsed.message,
          fieldErrors: parsed.fieldErrors,
          requestId: parsed.requestId,
        );
      }

      if (status == 429) {
        return RateLimitException(
          message: parsed.message,
          retryAfter: _retryAfterOf(response),
          requestId: parsed.requestId,
        );
      }

      return parsed;
  }
}

Duration? _retryAfterOf(Response<dynamic>? response) {
  final raw = response?.headers.value('Retry-After');
  if (raw == null) {
    return null;
  }

  final seconds = int.tryParse(raw.trim());

  return seconds == null ? null : Duration(seconds: seconds);
}
