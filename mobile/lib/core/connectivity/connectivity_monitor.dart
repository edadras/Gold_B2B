import 'dart:async';

import 'package:dio/dio.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';

/// What the app currently believes about its connection.
enum ConnectivityStatus {
  /// REST calls are succeeding and the realtime socket is up. Trading is
  /// allowed.
  online,

  /// REST is reachable but the realtime socket is not, so prices and balances
  /// may be behind. Trading is allowed but the UI says so.
  degraded,

  /// REST is unreachable. Trading and settlement actions are DISABLED —
  /// docs/07-mobile-flutter/01-architecture.md §1.9.
  offline,
}

extension ConnectivityStatusX on ConnectivityStatus {
  /// The single question every action button asks.
  bool get allowsFinancialActions => this != ConnectivityStatus.offline;

  String? get bannerMessage => switch (this) {
        ConnectivityStatus.online => null,
        ConnectivityStatus.degraded =>
          'اتصال ضعیف — داده‌ها ممکن است به‌روز نباشند',
        ConnectivityStatus.offline =>
          'اتصال اینترنت برقرار نیست — امکان معامله وجود ندارد',
      };
}

/// Observes actual request outcomes rather than the OS connectivity flag.
///
/// A phone can be "connected to Wi-Fi" and still be behind a captive portal,
/// on a network that blocks the API host, or on a link so bad that every call
/// times out. What matters to a trading app is not whether a radio is on but
/// whether the last few requests worked, so that is what this measures.
///
/// The state machine is deliberately asymmetric: one network-level failure is
/// enough to go offline (fail fast, disable the buttons), but recovery
/// requires an actual successful response. Nothing here guesses.
class ConnectivityMonitor {
  ConnectivityMonitor();

  final StreamController<ConnectivityStatus> _controller =
      StreamController<ConnectivityStatus>.broadcast();

  ConnectivityStatus _status = ConnectivityStatus.online;
  bool _realtimeLive = false;
  DateTime? _lastSuccessAt;
  StreamSubscription<RealtimeStatus>? _realtimeSubscription;

  Stream<ConnectivityStatus> get statuses => _controller.stream;

  ConnectivityStatus get status => _status;

  /// When the last successful response arrived — drives the staleness label.
  DateTime? get lastSuccessAt => _lastSuccessAt;

  /// Track the realtime transport so a socket outage downgrades to
  /// [ConnectivityStatus.degraded] without claiming the app is offline.
  void bindRealtime(Stream<RealtimeStatus> statuses) {
    _realtimeSubscription?.cancel();
    _realtimeSubscription = statuses.listen((status) {
      _realtimeLive = status.isLive;
      _recompute();
    });
  }

  void recordSuccess() {
    _lastSuccessAt = DateTime.now().toUtc();
    _recompute(restReachable: true);
  }

  /// Only TRANSPORT failures count. A 422 INSUFFICIENT_BALANCE means the
  /// network is working perfectly.
  void recordFailure(AppFailure failure) {
    final isTransport = failure is NetworkFailure || failure is TimeoutFailure;
    _recompute(restReachable: !isTransport);
  }

  void _recompute({bool? restReachable}) {
    final reachable = restReachable ?? _status != ConnectivityStatus.offline;

    final next = !reachable
        ? ConnectivityStatus.offline
        : _realtimeLive
            ? ConnectivityStatus.online
            : ConnectivityStatus.degraded;

    if (next == _status) {
      return;
    }

    _status = next;
    if (!_controller.isClosed) {
      _controller.add(next);
    }
  }

  Future<void> dispose() async {
    await _realtimeSubscription?.cancel();
    _realtimeSubscription = null;
    await _controller.close();
  }
}

/// Feeds every request outcome into a [ConnectivityMonitor].
///
/// Installed AFTER `ErrorInterceptor` so that it sees a typed [AppFailure]
/// and observes the FINAL outcome of a request: by the time an error reaches
/// it, `RetryInterceptor` has already exhausted its attempts, so a single
/// transient blip does not flip the whole UI into offline mode.
class ConnectivityInterceptor extends Interceptor {
  ConnectivityInterceptor(this._monitor);

  final ConnectivityMonitor _monitor;

  @override
  void onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) {
    _monitor.recordSuccess();
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final failure = err.error;
    if (failure is AppFailure) {
      _monitor.recordFailure(failure);
    } else {
      _monitor.recordFailure(failureFromDioException(err));
    }

    handler.next(err);
  }
}
