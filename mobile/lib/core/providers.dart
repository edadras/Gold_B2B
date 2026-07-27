import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/app_config.dart';
import 'package:gold_b2b/core/connectivity/connectivity_monitor.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/core/realtime/websocket_service.dart';
import 'package:gold_b2b/core/storage/secure_storage.dart';

/// The core object graph.
///
/// Providers are declared by hand (`Provider`, `StateNotifierProvider`,
/// `StreamProvider`, `FutureProvider`) rather than generated with
/// `@riverpod`, because this project has no `build_runner` step — see
/// pubspec.yaml for why.

/// Injected by `main.dart` via a `ProviderScope` override, so that the flavour
/// is chosen exactly once, at the entry point, and no code below can reach for
/// a different one.
final appConfigProvider = Provider<AppConfig>(
  (ref) => throw UnimplementedError(
    'appConfigProvider must be overridden in ProviderScope. '
    'See main.dart.',
  ),
);

final secureStorageProvider = Provider<SecureStorage>((ref) => SecureStorage());

final connectivityMonitorProvider = Provider<ConnectivityMonitor>((ref) {
  final monitor = ConnectivityMonitor();
  ref.onDispose(monitor.dispose);

  return monitor;
});

/// Broadcasts "the session just ended involuntarily".
///
/// `AuthInterceptor` cannot reach into Riverpod (it is constructed inside the
/// `ApiClient` and knows nothing about the widget tree), so a forced logout
/// travels out through this bus. `AuthController` listens and clears its state;
/// the router reacts to that and redirects to `/login`.
class SessionEvents {
  SessionEvents();

  final StreamController<void> _forcedLogouts =
      StreamController<void>.broadcast();

  Stream<void> get forcedLogouts => _forcedLogouts.stream;

  Future<void> notifyForcedLogout() async {
    if (!_forcedLogouts.isClosed) {
      _forcedLogouts.add(null);
    }
  }

  Future<void> dispose() => _forcedLogouts.close();
}

final sessionEventsProvider = Provider<SessionEvents>((ref) {
  final events = SessionEvents();
  ref.onDispose(events.dispose);

  return events;
});

final apiClientProvider = Provider<ApiClient>((ref) {
  final client = ApiClient(
    config: ref.watch(appConfigProvider),
    storage: ref.watch(secureStorageProvider),
    connectivity: ref.watch(connectivityMonitorProvider),
    onSessionEnded: ref.watch(sessionEventsProvider).notifyForcedLogout,
  );

  ref.onDispose(client.close);

  return client;
});

final webSocketServiceProvider = Provider<WebSocketService>((ref) {
  final service = WebSocketService(
    config: ref.watch(appConfigProvider),
    apiClient: ref.watch(apiClientProvider),
  );

  // The monitor needs to know whether the socket is live in order to tell
  // "offline" apart from "REST is fine but prices are not streaming".
  ref.watch(connectivityMonitorProvider).bindRealtime(service.statuses);

  ref.onDispose(service.dispose);

  return service;
});

// --- observable status ------------------------------------------------------

class ConnectivityController extends StateNotifier<ConnectivityStatus> {
  ConnectivityController(this._monitor) : super(_monitor.status) {
    _subscription = _monitor.statuses.listen((status) => state = status);
  }

  final ConnectivityMonitor _monitor;
  StreamSubscription<ConnectivityStatus>? _subscription;

  DateTime? get lastSuccessAt => _monitor.lastSuccessAt;

  @override
  void dispose() {
    unawaited(_subscription?.cancel());
    super.dispose();
  }
}

final connectivityProvider =
    StateNotifierProvider<ConnectivityController, ConnectivityStatus>(
  (ref) => ConnectivityController(ref.watch(connectivityMonitorProvider)),
);

class RealtimeStatusController extends StateNotifier<RealtimeStatus> {
  RealtimeStatusController(WebSocketService service)
      : super(service.status) {
    _subscription = service.statuses.listen((status) => state = status);
  }

  StreamSubscription<RealtimeStatus>? _subscription;

  @override
  void dispose() {
    unawaited(_subscription?.cancel());
    super.dispose();
  }
}

final realtimeStatusProvider =
    StateNotifierProvider<RealtimeStatusController, RealtimeStatus>(
  (ref) => RealtimeStatusController(ref.watch(webSocketServiceProvider)),
);

/// Raw realtime event stream, for features that want to listen directly.
final realtimeEventsProvider = StreamProvider<RealtimeEvent>(
  (ref) => ref.watch(webSocketServiceProvider).events,
);

/// Fires after every successful (re)connection. See `realtime_sync.dart` for
/// the listener that turns this into provider invalidation.
final realtimeResyncProvider = StreamProvider<RealtimeResync>(
  (ref) => ref.watch(webSocketServiceProvider).resyncs,
);

/// "Right now", refreshed once a second.
///
/// Countdown chips and staleness labels need a ticking clock. Having one
/// provider own it means the whole screen rebuilds on the same tick instead of
/// each card running its own timer.
final clockProvider = StreamProvider<DateTime>(
  (ref) => Stream<DateTime>.periodic(
    const Duration(seconds: 1),
    (_) => DateTime.now(),
  ).asBroadcastStream(),
);
