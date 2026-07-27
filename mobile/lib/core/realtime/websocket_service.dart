import 'dart:async';
import 'dart:convert';

import 'package:gold_b2b/core/config/app_config.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:web_socket_channel/web_socket_channel.dart';
import 'package:web_socket_channel/status.dart' as ws_status;

/// Realtime transport, speaking the Pusher wire protocol against Laravel
/// Reverb over a raw WebSocket.
///
/// ## Why not pusher_channels_flutter
///
/// The architecture doc names that package, but it wraps two platform-native
/// SDKs whose reconnect behaviour cannot be observed or overridden from Dart.
/// The one thing this app absolutely must control is what happens on
/// reconnect (see below), so the ~200 lines of protocol are implemented here
/// instead. The wire format is stable and documented; the tradeoff is worth it.
///
/// ## The rule that shapes this whole class
///
///   The WebSocket does not guarantee delivery. Every time a connection is
///   established, financial state must be discarded and re-read from REST.
///
/// A missed `balance.updated` while the socket was down leaves the user
/// looking at a balance that is wrong in the direction that lets them place an
/// order they cannot cover. So [resyncs] fires on EVERY successful connection,
/// including the first, and `realtimeSyncProvider` invalidates the balance,
/// orders and settlement providers in response. Nothing else in the app is
/// allowed to treat a socket event as authoritative.
class WebSocketService {
  WebSocketService({
    required AppConfig config,
    required ApiClient apiClient,
  })  : _config = config,
        _api = apiClient;

  final AppConfig _config;
  final ApiClient _api;

  WebSocketChannel? _channel;
  StreamSubscription<dynamic>? _subscription;

  final StreamController<RealtimeEvent> _events =
      StreamController<RealtimeEvent>.broadcast();
  final StreamController<RealtimeStatus> _statuses =
      StreamController<RealtimeStatus>.broadcast();
  final StreamController<RealtimeResync> _resyncs =
      StreamController<RealtimeResync>.broadcast();

  /// Channels the app wants to be on. Survives disconnection so that a
  /// reconnect restores exactly the same subscription set.
  final Set<String> _desiredChannels = <String>{};

  /// Channels the server has confirmed on the CURRENT connection.
  final Set<String> _activeChannels = <String>{};

  String? _socketId;
  RealtimeStatus _status = RealtimeStatus.disconnected;
  int _epoch = 0;
  int _reconnectAttempt = 0;
  bool _shutdown = false;

  Timer? _reconnectTimer;
  Timer? _heartbeatTimer;
  Timer? _pongDeadline;

  static const Duration _initialBackoff = Duration(seconds: 1);
  static const Duration _maxBackoff = Duration(seconds: 30);
  static const Duration _heartbeatInterval = Duration(seconds: 30);
  static const Duration _pongTimeout = Duration(seconds: 10);

  Stream<RealtimeEvent> get events => _events.stream;

  Stream<RealtimeStatus> get statuses => _statuses.stream;

  /// Fires after every successful connection. Listeners MUST re-read financial
  /// state from REST.
  Stream<RealtimeResync> get resyncs => _resyncs.stream;

  RealtimeStatus get status => _status;

  String? get socketId => _socketId;

  /// Only the events named [name], already decoded.
  Stream<RealtimeEvent> on(String name) =>
      _events.stream.where((event) => event.name == name);

  /// Only the events on [channel].
  Stream<RealtimeEvent> onChannel(String channel) =>
      _events.stream.where((event) => event.channel == channel);

  // --- lifecycle -----------------------------------------------------------

  Future<void> connect() async {
    _shutdown = false;
    if (_channel != null || _status == RealtimeStatus.connecting) {
      return;
    }

    _setStatus(
      _epoch == 0 ? RealtimeStatus.connecting : RealtimeStatus.reconnecting,
    );

    try {
      final channel = WebSocketChannel.connect(_config.webSocketUri);
      _channel = channel;

      _subscription = channel.stream.listen(
        _onFrame,
        onError: _onSocketError,
        onDone: _onSocketDone,
        cancelOnError: false,
      );
    } on Object catch (error) {
      _channel = null;
      _onSocketError(error);
    }
  }

  /// Deliberate disconnect: the app was backgrounded, or the user logged out.
  /// No reconnect is scheduled.
  Future<void> disconnect() async {
    _shutdown = true;
    _cancelTimers();
    _activeChannels.clear();
    _socketId = null;

    await _subscription?.cancel();
    _subscription = null;

    await _channel?.sink.close(ws_status.normalClosure);
    _channel = null;

    _setStatus(RealtimeStatus.disconnected);
  }

  /// Tear everything down permanently. Call from provider disposal.
  Future<void> dispose() async {
    await disconnect();
    _desiredChannels.clear();
    await _events.close();
    await _statuses.close();
    await _resyncs.close();
  }

  // --- subscriptions -------------------------------------------------------

  /// Register interest in [channel]. Safe to call before the socket is up: the
  /// channel is remembered and subscribed as soon as a connection exists, and
  /// re-subscribed after every reconnect.
  Future<void> subscribe(String channel) async {
    _desiredChannels.add(channel);

    if (_status.isLive && !_activeChannels.contains(channel)) {
      await _sendSubscribe(channel);
    }
  }

  Future<void> unsubscribe(String channel) async {
    _desiredChannels.remove(channel);
    _activeChannels.remove(channel);

    if (_status.isLive) {
      _send(<String, dynamic>{
        'event': 'pusher:unsubscribe',
        'data': <String, dynamic>{'channel': channel},
      });
    }
  }

  /// Replace the set of market channels in one shot — used when the user
  /// changes which instrument they are watching, so we do not accumulate
  /// subscriptions to every instrument they have ever opened.
  Future<void> setMarketChannels(Iterable<String> instrumentCodes) async {
    final wanted = instrumentCodes.map(Channels.market).toSet();
    final current =
        _desiredChannels.where((c) => c.startsWith('market.')).toSet();

    for (final stale in current.difference(wanted)) {
      if (stale == Channels.marketStatus) {
        continue;
      }
      await unsubscribe(stale);
    }

    for (final fresh in wanted.difference(current)) {
      await subscribe(fresh);
    }
  }

  Future<void> _sendSubscribe(String channel) async {
    if (!Channels.isPrivate(channel)) {
      _send(<String, dynamic>{
        'event': 'pusher:subscribe',
        'data': <String, dynamic>{'channel': channel},
      });

      return;
    }

    final socketId = _socketId;
    if (socketId == null) {
      return;
    }

    try {
      final response = await _api.postRaw(
        '/broadcasting/auth',
        body: <String, dynamic>{
          'socket_id': socketId,
          'channel_name': channel,
        },
      );

      final auth = response['auth'];
      if (auth is! String || auth.isEmpty) {
        _setStatus(RealtimeStatus.unauthorized);

        return;
      }

      _send(<String, dynamic>{
        'event': 'pusher:subscribe',
        'data': <String, dynamic>{
          'channel': channel,
          'auth': auth,
          if (response['channel_data'] != null)
            'channel_data': response['channel_data'],
        },
      });
    } on UnauthorizedException {
      // The bearer token is dead. AuthInterceptor has already started the
      // session-ended flow; there is nothing useful to retry here.
      _setStatus(RealtimeStatus.unauthorized);
    } on AppFailure {
      // Transient: the next reconnect will try again.
    }
  }

  Future<void> _resubscribeAll() async {
    _activeChannels.clear();

    for (final channel in _desiredChannels.toList(growable: false)) {
      await _sendSubscribe(channel);
    }
  }

  // --- frame handling ------------------------------------------------------

  void _onFrame(dynamic frame) {
    _restartHeartbeat();

    if (frame is! String) {
      return;
    }

    final Object? decoded;
    try {
      decoded = jsonDecode(frame);
    } on FormatException {
      return;
    }

    if (decoded is! Map<String, dynamic>) {
      return;
    }

    final name = decoded['event'];
    if (name is! String) {
      return;
    }

    final payload = _decodePayload(decoded['data']);
    final channel = decoded['channel'] as String? ?? '';

    switch (name) {
      case RealtimeEvents.pusherConnectionEstablished:
        _onConnectionEstablished(payload);

        return;

      case RealtimeEvents.pusherPing:
        _send(<String, dynamic>{
          'event': RealtimeEvents.pusherPong,
          'data': <String, dynamic>{},
        });

        return;

      case RealtimeEvents.pusherPong:
        _pongDeadline?.cancel();
        _pongDeadline = null;

        return;

      case RealtimeEvents.pusherSubscriptionSucceeded:
        if (channel.isNotEmpty) {
          _activeChannels.add(channel);
        }

        return;

      case RealtimeEvents.pusherSubscriptionError:
        _activeChannels.remove(channel);
        _setStatus(RealtimeStatus.unauthorized);

        return;

      case RealtimeEvents.pusherError:
        _handleProtocolError(payload);

        return;
    }

    if (_events.isClosed) {
      return;
    }

    _events.add(
      RealtimeEvent(
        name: _normalizeEventName(name),
        channel: channel,
        data: payload,
        receivedAt: DateTime.now().toUtc(),
      ),
    );
  }

  void _onConnectionEstablished(Map<String, dynamic> payload) {
    final socketId = payload['socket_id'];
    _socketId = socketId is String ? socketId : '$socketId';

    _reconnectAttempt = 0;
    _epoch += 1;
    _setStatus(RealtimeStatus.connected);
    _restartHeartbeat();

    // Resubscribe first so that no event produced between the REST read and
    // the subscription is missed, THEN tell the app to resynchronise. The
    // opposite order leaves a window in which an update is neither delivered
    // over the socket nor present in the REST snapshot.
    unawaited(
      _resubscribeAll().then((_) {
        if (!_resyncs.isClosed) {
          _resyncs.add(
            RealtimeResync(epoch: _epoch, at: DateTime.now().toUtc()),
          );
        }
      }),
    );
  }

  void _handleProtocolError(Map<String, dynamic> payload) {
    final code = payload['code'];
    final numericCode = code is num ? code.toInt() : null;

    // 4000-4099: do not reconnect, the client is wrong (bad app key, bad
    // protocol version). 4100-4199: reconnect after backoff. 4200+: reconnect
    // immediately.
    if (numericCode != null && numericCode >= 4000 && numericCode < 4100) {
      _shutdown = true;
      _setStatus(RealtimeStatus.unauthorized);
    }
  }

  /// Laravel broadcasts either a `broadcastAs()` name (`quote.updated`) or the
  /// fully-qualified class (`App\Events\QuoteUpdated`). Normalise to the short
  /// form so feature code only ever matches one shape.
  static String _normalizeEventName(String raw) {
    const backslash = '\\';

    if (!raw.contains(backslash)) {
      return raw.startsWith('.') ? raw.substring(1) : raw;
    }

    return raw.split(backslash).last;
  }

  static Map<String, dynamic> _decodePayload(Object? data) {
    if (data is Map<String, dynamic>) {
      return data;
    }

    if (data is String && data.isNotEmpty) {
      try {
        final decoded = jsonDecode(data);
        if (decoded is Map<String, dynamic>) {
          return decoded;
        }
      } on FormatException {
        return const <String, dynamic>{};
      }
    }

    return const <String, dynamic>{};
  }

  void _send(Map<String, dynamic> frame) {
    final channel = _channel;
    if (channel == null) {
      return;
    }

    try {
      channel.sink.add(jsonEncode(frame));
    } on StateError {
      // Sink already closed; the done handler will schedule the reconnect.
    }
  }

  // --- failure and backoff -------------------------------------------------

  void _onSocketError(Object error) {
    _lastTransportError = '$error';
    _activeChannels.clear();
    _scheduleReconnect();
  }

  /// Last transport-level error, surfaced in the settings "diagnostics" row so
  /// that a support call does not begin with "it just does not work".
  String? get lastTransportError => _lastTransportError;
  String? _lastTransportError;

  void _onSocketDone() {
    _activeChannels.clear();
    _scheduleReconnect();
  }

  void _scheduleReconnect() {
    _cancelTimers();
    _socketId = null;

    unawaited(_subscription?.cancel());
    _subscription = null;
    _channel = null;

    if (_shutdown) {
      _setStatus(RealtimeStatus.disconnected);

      return;
    }

    _setStatus(RealtimeStatus.reconnecting);

    final delay = _backoffFor(_reconnectAttempt);
    _reconnectAttempt += 1;

    _reconnectTimer = Timer(delay, () {
      if (!_shutdown) {
        unawaited(connect());
      }
    });
  }

  /// 1s, 2s, 4s, 8s, 16s, then 30s forever (§3.4).
  static Duration _backoffFor(int attempt) {
    if (attempt <= 0) {
      return _initialBackoff;
    }

    final seconds = _initialBackoff.inSeconds * (1 << attempt);

    return seconds >= _maxBackoff.inSeconds
        ? _maxBackoff
        : Duration(seconds: seconds);
  }

  void _restartHeartbeat() {
    _heartbeatTimer?.cancel();
    _pongDeadline?.cancel();
    _pongDeadline = null;

    _heartbeatTimer = Timer(_heartbeatInterval, () {
      _send(<String, dynamic>{
        'event': RealtimeEvents.pusherPing,
        'data': <String, dynamic>{},
      });

      // A socket that is open but not delivering is worse than one that is
      // closed, because the UI keeps claiming to be live. If the pong does not
      // come back, treat the connection as dead.
      _pongDeadline = Timer(_pongTimeout, () {
        unawaited(_channel?.sink.close(ws_status.goingAway));
        _scheduleReconnect();
      });
    });
  }

  void _cancelTimers() {
    _reconnectTimer?.cancel();
    _reconnectTimer = null;
    _heartbeatTimer?.cancel();
    _heartbeatTimer = null;
    _pongDeadline?.cancel();
    _pongDeadline = null;
  }

  void _setStatus(RealtimeStatus next) {
    if (_status == next) {
      return;
    }

    _status = next;
    if (!_statuses.isClosed) {
      _statuses.add(next);
    }
  }
}
