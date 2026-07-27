import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/features/auth/application/auth_controller.dart';
import 'package:gold_b2b/features/dashboard/application/balance_controller.dart';
import 'package:gold_b2b/features/notifications/application/notifications_controller.dart';
import 'package:gold_b2b/features/orders/application/orders_controller.dart';
import 'package:gold_b2b/features/settlements/application/settlements_controller.dart';

/// The composition root for realtime.
///
/// This is the ONE place in the app where `core/` reaches into `features/`,
/// and it is deliberate: connecting the socket, subscribing the right private
/// channels for the signed-in organisation, and — the important part —
/// re-reading financial state from REST after every reconnect are decisions
/// that cannot be made by any single feature. Splitting them across features
/// would mean each one independently deciding what "resynchronise" means, and
/// the first one that got it wrong would be a silent balance bug.
///
/// The rule being enforced, from docs/05-api/03-realtime-webhooks.md §3.4:
///
///   > WebSocket is for UPDATES, not for TRUTH. Real state is always read from
///   > REST. After every reconnect the client must reload balances and open
///   > orders.
///
/// and from docs/07-mobile-flutter/01-architecture.md §1.8:
///
///   > ⚠️ critical: after every reconnection, synchronise state from REST.
class RealtimeSync {
  RealtimeSync(this._ref) {
    _authSubscription = _ref.listen<AuthState>(
      authControllerProvider,
      (previous, next) => _onAuthChanged(previous, next),
      fireImmediately: true,
    );
  }

  final Ref _ref;

  ProviderSubscription<AuthState>? _authSubscription;
  StreamSubscription<RealtimeResync>? _resyncSubscription;
  int? _subscribedOrganizationId;

  void _onAuthChanged(AuthState? previous, AuthState next) {
    final organizationId = next.organization?.id;

    if (!next.isAuthenticated || organizationId == null) {
      unawaited(_disconnect());

      return;
    }

    if (_subscribedOrganizationId == organizationId) {
      return;
    }

    unawaited(_connectFor(organizationId, next.user?.id));
  }

  Future<void> _connectFor(int organizationId, int? userId) async {
    final service = _ref.read(webSocketServiceProvider);

    _subscribedOrganizationId = organizationId;

    // Register interest BEFORE connecting. WebSocketService remembers the
    // desired channel set and subscribes as soon as a socket exists, and
    // re-subscribes the same set after every reconnect.
    await service.subscribe(Channels.organization(organizationId));
    await service.subscribe(Channels.settlements(organizationId));
    await service.subscribe(Channels.rfq(organizationId));
    await service.subscribe(notificationChannelFor(organizationId));
    await service.subscribe(Channels.marketStatus);
    await service.subscribe(Channels.referencePrice);

    if (userId != null) {
      await service.subscribe(Channels.user(userId));
    }

    await _resyncSubscription?.cancel();
    _resyncSubscription = service.resyncs.listen(_onResync);

    await service.connect();
  }

  /// Re-read everything that can silently go stale.
  ///
  /// Fires on the FIRST connection too. That costs one extra round trip at
  /// startup and removes an entire class of "is this path also covered?"
  /// reasoning — there is exactly one code path that establishes financial
  /// state, and it runs every time.
  void _onResync(RealtimeResync resync) {
    // Balances: an order that filled while the socket was down changes what
    // the user can spend.
    unawaited(_ref.read(balanceProvider.notifier).refreshQuietly());

    // Open orders: a fill or a cancellation may have been missed.
    unawaited(_ref.read(openOrdersProvider.notifier).refresh());

    // Settlement queue: a deadline may have passed, or the counterparty may
    // have declared a payment that is now waiting on this user.
    unawaited(_ref.read(pendingSettlementsProvider.notifier).refresh());

    // Unread badge.
    _ref.invalidate(unreadNotificationCountProvider);
  }

  Future<void> _disconnect() async {
    if (_subscribedOrganizationId == null) {
      return;
    }

    _subscribedOrganizationId = null;
    await _resyncSubscription?.cancel();
    _resyncSubscription = null;
    await _ref.read(webSocketServiceProvider).disconnect();
  }

  Future<void> dispose() async {
    _authSubscription?.close();
    _authSubscription = null;
    await _resyncSubscription?.cancel();
    _resyncSubscription = null;
  }
}

/// Instantiated once, from `app.dart`, and kept alive for the whole session.
final realtimeSyncProvider = Provider<RealtimeSync>((ref) {
  final sync = RealtimeSync(ref);
  ref.onDispose(sync.dispose);

  return sync;
});
