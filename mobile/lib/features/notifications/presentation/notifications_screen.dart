import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/notifications/application/notifications_controller.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifications = ref.watch(notificationsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('اعلان‌ها'),
        actions: <Widget>[
          TextButton(
            onPressed: () =>
                ref.read(notificationsProvider.notifier).markAllRead(),
            child: const Text('همه خوانده شد'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.read(notificationsProvider.notifier).refresh(),
        child: AsyncValueView<Paginated<AppNotification>>(
          value: notifications,
          loading: const ListSkeleton(),
          onRetry: () => ref.read(notificationsProvider.notifier).refresh(),
          data: (page) {
            if (page.isEmpty) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const <Widget>[
                  SizedBox(height: 80),
                  EmptyState(
                    title: 'اعلانی ندارید',
                    icon: Icons.notifications_none,
                  ),
                ],
              );
            }

            return ListView.separated(
              padding: const EdgeInsets.all(16),
              physics: const AlwaysScrollableScrollPhysics(),
              itemCount: page.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (context, index) {
                final notification = page.items[index];

                return SectionCard(
                  borderColor:
                      notification.isRead ? null : notification.tone.foreground,
                  onTap: () {
                    ref
                        .read(notificationsProvider.notifier)
                        .markRead(notification.id);

                    final link = notification.deepLink;
                    if (link != null && link.startsWith('/')) {
                      context.push(link);
                    }
                  },
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      Row(
                        children: <Widget>[
                          Icon(
                            notification.tone.icon,
                            size: 16,
                            color: notification.tone.foreground,
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              notification.title,
                              style: AppTypography.titleMedium.copyWith(
                                fontWeight: notification.isRead
                                    ? FontWeight.w400
                                    : FontWeight.w700,
                              ),
                            ),
                          ),
                          if (!notification.isRead)
                            const Icon(
                              Icons.circle,
                              size: 8,
                              color: AppColors.gold,
                            ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(notification.body, style: AppTypography.bodyMuted),
                      const SizedBox(height: 6),
                      Text(
                        JalaliFormatter.smart(notification.createdAt),
                        style: AppTypography.caption,
                      ),
                    ],
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}
