/// Connection state of the realtime transport.
enum RealtimeStatus {
  /// Never connected, or deliberately disconnected (app backgrounded, logged
  /// out).
  disconnected,

  /// First connection attempt in flight.
  connecting,

  /// Connected and subscribed. Live updates are flowing.
  connected,

  /// Connection lost; a backoff timer is running.
  reconnecting,

  /// The server rejected channel authorisation. Reconnecting will not help
  /// until the session is refreshed.
  unauthorized,
}

extension RealtimeStatusX on RealtimeStatus {
  bool get isLive => this == RealtimeStatus.connected;

  /// True when the UI must warn that displayed data may be behind.
  bool get isDegraded =>
      this == RealtimeStatus.reconnecting ||
      this == RealtimeStatus.disconnected ||
      this == RealtimeStatus.unauthorized;

  String get persianLabel => switch (this) {
        RealtimeStatus.connected => 'زنده',
        RealtimeStatus.connecting => 'در حال اتصال…',
        RealtimeStatus.reconnecting => 'اتصال مجدد…',
        RealtimeStatus.disconnected => 'قطع',
        RealtimeStatus.unauthorized => 'دسترسی زنده مجاز نیست',
      };
}

/// One decoded application event off a channel.
///
/// The Pusher wire format double-encodes: the frame is JSON, and its `data`
/// member is a JSON *string*. [RealtimeEvent.tryParse] deals with that so no
/// feature ever has to.
final class RealtimeEvent {
  const RealtimeEvent({
    required this.name,
    required this.channel,
    required this.data,
    required this.receivedAt,
  });

  /// Application event name, e.g. `quote.updated`.
  final String name;

  /// Channel it arrived on, e.g. `private-org.184`.
  final String channel;

  final Map<String, dynamic> data;

  final DateTime receivedAt;

  bool get isControlFrame =>
      name.startsWith('pusher:') || name.startsWith('pusher_internal:');

  @override
  String toString() => 'RealtimeEvent($name on $channel)';
}

/// A reconnection notice.
///
/// docs/05-api/03-realtime-webhooks.md §3.4 states the rule this type exists
/// to enforce:
///
///   "WebSocket is for UPDATES, not for TRUTH. Real state is always read from
///    REST. After every reconnect the client must reload balances and open
///    orders."
///
/// [epoch] increments on every successful (re)connection, so a listener can
/// tell a genuine reconnect from a duplicate notification.
final class RealtimeResync {
  const RealtimeResync({required this.epoch, required this.at});

  final int epoch;
  final DateTime at;

  /// True for the very first connection of the app's lifetime, where there is
  /// nothing stale to invalidate yet.
  bool get isInitial => epoch == 1;
}
