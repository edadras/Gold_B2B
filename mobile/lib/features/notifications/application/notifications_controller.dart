import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/core/realtime/websocket_service.dart';
import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/status_tone.dart';

/// One in-app notification.
final class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.createdAt,
    required this.isRead,
    this.deepLink,
  });

  final int id;

  /// e.g. `settlement.overdue`, `rfq.quoted`, `trade.executed`.
  final String type;

  final String title;
  final String body;
  final DateTime createdAt;
  final bool isRead;

  /// In-app route to open, when the server provides one.
  final String? deepLink;

  StatusTone get tone {
    if (type.startsWith('dispute') ||
        type.contains('overdue') ||
        type.contains('defaulted')) {
      return StatusTone.negative;
    }

    if (type.startsWith('settlement') || type.startsWith('netting')) {
      return StatusTone.warning;
    }

    if (type.startsWith('trade') || type.startsWith('order')) {
      return StatusTone.info;
    }

    return StatusTone.neutral;
  }

  AppNotification copyWith({bool? isRead}) => AppNotification(
        id: id,
        type: type,
        title: title,
        body: body,
        createdAt: createdAt,
        isRead: isRead ?? this.isRead,
        deepLink: deepLink,
      );

  factory AppNotification.fromJson(Map<String, dynamic> json) =>
      AppNotification(
        id: json.requireInt('id'),
        type: json.stringOr('type', ''),
        title: json.stringOr('title', ''),
        body: json.stringOr('body', ''),
        createdAt:
            json.dateTimeOrNull('created_at') ?? DateTime.now().toUtc(),
        isRead: json.boolOr('is_read', fallback: false) ||
            json.dateTimeOrNull('read_at') != null,
        deepLink: json.stringOrNull('deep_link'),
      );
}

/// Notifications (docs/05-api/02-endpoints.md §2.14).
class NotificationRepository {
  NotificationRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Paginated<AppNotification>> fetch({String? cursor}) => _api.getList(
        '/notifications',
        AppNotification.fromJson,
        query: <String, dynamic>{if (cursor != null) 'cursor': cursor},
      );

  Future<int> fetchUnreadCount() => _api.getOne(
        '/notifications/unread-count',
        (json) => json.intOr('count', 0),
      );

  Future<void> markRead(int id) =>
      _api.postVoid('/notifications/$id/read');

  Future<void> markAllRead() => _api.postVoid('/notifications/read-all');

  /// Register this device for push.
  ///
  /// TODO(mobile-platform): there is no push provider wired up. The
  /// dependency list for this project does not include firebase_messaging,
  /// and adding it means Google Services configuration files, an APNs key and
  /// a Firebase project — none of which exist yet. A developer must:
  ///   1. add firebase_messaging and the platform config;
  ///   2. obtain the FCM token and call this method with it;
  ///   3. handle the background/terminated message handlers, routing the
  ///      notification's `deep_link` through GoRouter;
  ///   4. call `DELETE /devices/{token}` on logout.
  /// Until then the app relies on the WebSocket for live notification, which
  /// works only while it is in the foreground.
  Future<void> registerDevice({
    required String token,
    required String platform,
  }) =>
      _api.postVoid(
        '/devices',
        body: <String, dynamic>{'token': token, 'platform': platform},
      );

  Future<void> unregisterDevice(String token) =>
      _api.deleteVoid('/devices/$token');
}

final notificationRepositoryProvider = Provider<NotificationRepository>(
  (ref) => NotificationRepository(api: ref.watch(apiClientProvider)),
);

/// Live notification list.
class NotificationsController
    extends StateNotifier<AsyncValue<Paginated<AppNotification>>> {
  NotificationsController({
    required NotificationRepository repository,
    required WebSocketService realtime,
    required this.onUnreadChanged,
  })  : _repository = repository,
        super(const AsyncValue<Paginated<AppNotification>>.loading()) {
    _events = realtime
        .on(RealtimeEvents.notificationCreated)
        .listen((_) => unawaited(refresh()));
    unawaited(refresh());
  }

  final NotificationRepository _repository;
  final void Function() onUnreadChanged;

  StreamSubscription<RealtimeEvent>? _events;

  Future<void> refresh() async {
    final next = await AsyncValue.guard(() => _repository.fetch());
    if (mounted) {
      state = next;
      onUnreadChanged();
    }
  }

  Future<void> markRead(int id) async {
    final current = state.valueOrNull;
    if (current == null) {
      return;
    }

    // Optimistic: marking read is idempotent server-side and losing the
    // update costs nothing.
    state = AsyncValue<Paginated<AppNotification>>.data(
      current.copyWithItems(
        current.items
            .map(
              (notification) => notification.id == id
                  ? notification.copyWith(isRead: true)
                  : notification,
            )
            .toList(growable: false),
      ),
    );

    await _repository.markRead(id);
    onUnreadChanged();
  }

  Future<void> markAllRead() async {
    final current = state.valueOrNull;
    if (current != null) {
      state = AsyncValue<Paginated<AppNotification>>.data(
        current.copyWithItems(
          current.items
              .map((notification) => notification.copyWith(isRead: true))
              .toList(growable: false),
        ),
      );
    }

    await _repository.markAllRead();
    onUnreadChanged();
  }

  @override
  void dispose() {
    unawaited(_events?.cancel());
    super.dispose();
  }
}

final notificationsProvider = StateNotifierProvider<NotificationsController,
    AsyncValue<Paginated<AppNotification>>>(
  (ref) => NotificationsController(
    repository: ref.watch(notificationRepositoryProvider),
    realtime: ref.watch(webSocketServiceProvider),
    onUnreadChanged: () => ref.invalidate(unreadNotificationCountProvider),
  ),
);

final unreadNotificationCountProvider = FutureProvider<int>(
  (ref) => ref.watch(notificationRepositoryProvider).fetchUnreadCount(),
);

/// The private channel this organisation's live notifications arrive on.
/// Subscribed by `realtime_sync.dart`, which is where the organisation id is
/// known.
String notificationChannelFor(int organizationId) =>
    Channels.notifications(organizationId);
