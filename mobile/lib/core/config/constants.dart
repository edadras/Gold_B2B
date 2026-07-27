/// Cross-cutting constants. Anything that is a business rule lives on the
/// server and arrives via `GET /meta/settings`; what is here is either a
/// protocol constant or a client-side presentation choice.
library;

/// Request/response header names used by the API (docs/05-api/01-conventions
/// §1.3).
final class ApiHeaders {
  const ApiHeaders._();

  static const String authorization = 'Authorization';
  static const String accept = 'Accept';
  static const String contentType = 'Content-Type';
  static const String acceptLanguage = 'Accept-Language';
  static const String requestId = 'X-Request-Id';
  static const String idempotencyKey = 'Idempotency-Key';
  static const String clientVersion = 'X-Client-Version';

  /// Present on a replayed idempotent response.
  static const String idempotentReplay = 'X-Idempotent-Replay';

  static const String rateLimitRemaining = 'X-RateLimit-Remaining';
  static const String rateLimitReset = 'X-RateLimit-Reset';
  static const String retryAfter = 'Retry-After';
  static const String deprecation = 'Deprecation';
  static const String sunset = 'Sunset';
}

/// Paths that MUST carry an `Idempotency-Key` (§1.10).
///
/// Matching is by substring against the request path, which is why the
/// entries are written as the distinctive fragment rather than a full path —
/// `/settlements/88231/declare-payment` contains `/declare-payment`.
final class IdempotentPaths {
  const IdempotentPaths._();

  static const Set<String> fragments = <String>{
    '/orders',
    '/otc-offers',
    '/rfqs',
    '/rfq-quotes',
    '/settlements',
    '/vault/deposits',
    '/vault/withdrawals',
    '/netting-batches',
    '/lots',
    '/disputes',
  };

  /// True when a POST to [path] requires an idempotency key.
  ///
  /// Deliberately over-inclusive: sending a key where the server does not
  /// require one is harmless, whereas omitting one where it does turns a
  /// dropped response into a duplicate order.
  static bool requiresKey(String path) =>
      fragments.any((fragment) => path.contains(fragment));
}

/// WebSocket channel names (docs/05-api/03-realtime-webhooks.md §3.2).
final class Channels {
  const Channels._();

  static String market(String instrumentCode) => 'market.$instrumentCode';

  static const String marketStatus = 'market.status';
  static const String referencePrice = 'reference-price';

  static String organization(int orgId) => 'private-org.$orgId';

  static String settlements(int orgId) => 'private-org.$orgId.settlement';

  static String rfq(int orgId) => 'private-org.$orgId.rfq';

  static String notifications(int orgId) => 'private-org.$orgId.notification';

  static String user(int userId) => 'private-user.$userId';

  static bool isPrivate(String channel) =>
      channel.startsWith('private-') || channel.startsWith('presence-');
}

/// Event names carried on those channels (§3.3).
final class RealtimeEvents {
  const RealtimeEvents._();

  static const String quoteUpdated = 'quote.updated';
  static const String depthUpdated = 'depth.updated';
  static const String tradeExecuted = 'trade.executed';
  static const String marketStatusChanged = 'market.status_changed';

  static const String orderUpdated = 'order.updated';
  static const String balanceUpdated = 'balance.updated';
  static const String settlementStatusChanged = 'settlement.status_changed';
  static const String rfqQuoteReceived = 'rfq.quoted';
  static const String rfqReceived = 'rfq.received';
  static const String otcOfferReceived = 'otc.offer_received';
  static const String nettingProposed = 'netting.proposed';
  static const String notificationCreated = 'notification.created';

  /// Pusher-protocol control frames, handled by the transport rather than the
  /// application.
  static const String pusherConnectionEstablished =
      'pusher:connection_established';
  static const String pusherSubscriptionSucceeded =
      'pusher_internal:subscription_succeeded';
  static const String pusherSubscriptionError = 'pusher:subscription_error';
  static const String pusherPing = 'pusher:ping';
  static const String pusherPong = 'pusher:pong';
  static const String pusherError = 'pusher:error';
}

/// Presentation constants.
final class Ui {
  const Ui._();

  /// Accessibility floor from docs/07-mobile-flutter/02-screens.md §2.10.
  static const double minTouchTarget = 48;

  static const Duration snackDuration = Duration(seconds: 4);

  /// How long the order form waits after the last keystroke before it
  /// re-validates against the server-side reference price.
  static const Duration formDebounce = Duration(milliseconds: 300);

  /// Depth ladder rows per side.
  static const int depthLevels = 10;

  static const int defaultPageSize = 50;
}
