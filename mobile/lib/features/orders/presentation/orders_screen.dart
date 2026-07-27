import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/orders/application/orders_controller.dart';
import 'package:gold_b2b/features/orders/data/models/order.dart';
import 'package:gold_b2b/features/orders/presentation/widgets/order_tile.dart';
import 'package:gold_b2b/features/otc/presentation/otc_offers_screen.dart';
import 'package:gold_b2b/features/rfq/presentation/rfq_list_screen.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';

/// The "سفارش‌ها و معاملات" tab (docs/07-mobile-flutter/02-screens.md §2.1).
///
/// Four sub-tabs: open orders, history, OTC and RFQ. They are grouped here
/// because they are all "things I have asked the market for" — the user's
/// mental model is one place to look for anything they initiated.
class OrdersScreen extends ConsumerWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) => SecureScreen(
        child: DefaultTabController(
          length: 4,
          child: Scaffold(
            appBar: AppBar(
              title: const Text('سفارش‌ها'),
              bottom: const TabBar(
                isScrollable: true,
                tabs: <Widget>[
                  Tab(text: 'سفارش‌های باز'),
                  Tab(text: 'تاریخچه'),
                  Tab(text: 'OTC'),
                  Tab(text: 'RFQ'),
                ],
              ),
            ),
            body: const Column(
              children: <Widget>[
                ConnectivityBanner(),
                Expanded(
                  child: TabBarView(
                    children: <Widget>[
                      OpenOrdersTab(),
                      OrderHistoryTab(),
                      OtcOffersTab(),
                      RfqListTab(),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      );
}

class OpenOrdersTab extends ConsumerWidget {
  const OpenOrdersTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final orders = ref.watch(openOrdersProvider);

    return RefreshIndicator(
      onRefresh: () => ref.read(openOrdersProvider.notifier).refresh(),
      child: AsyncValueView<List<Order>>(
        value: orders,
        loading: const ListSkeleton(),
        onRetry: () => ref.read(openOrdersProvider.notifier).refresh(),
        data: (items) {
          if (items.isEmpty) {
            return ListView(
              // Must stay scrollable so pull-to-refresh still works when the
              // list is empty.
              physics: const AlwaysScrollableScrollPhysics(),
              children: const <Widget>[
                SizedBox(height: 80),
                EmptyState(
                  title: 'سفارش بازی ندارید',
                  message: 'سفارش‌های ثبت‌شده و در انتظار اجرا اینجا '
                      'نمایش داده می‌شوند.',
                  icon: Icons.playlist_add_check,
                ),
              ],
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.all(16),
            physics: const AlwaysScrollableScrollPhysics(),
            itemCount: items.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, index) {
              final order = items[index];

              return OrderTile(
                order: order,
                onCancel: () => _cancel(context, ref, order),
              );
            },
          );
        },
      ),
    );
  }

  Future<void> _cancel(
    BuildContext context,
    WidgetRef ref,
    Order order,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('لغو سفارش'),
        content: Text(
          'سفارش ${order.orderCode} لغو شود؟ '
          'مقدار اجرانشده آزاد خواهد شد.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('لغو سفارش'),
          ),
        ],
      ),
    );

    if (confirmed != true || !context.mounted) {
      return;
    }

    try {
      await ref.read(openOrdersProvider.notifier).cancel(order.id);
    } on AppFailure catch (failure) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(failure.userMessage)),
        );
      }
    }
  }
}

class OrderHistoryTab extends ConsumerWidget {
  const OrderHistoryTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final history = ref.watch(orderHistoryProvider);

    return RefreshIndicator(
      onRefresh: () => ref.read(orderHistoryProvider.notifier).refresh(),
      child: AsyncValueView(
        value: history,
        loading: const ListSkeleton(),
        onRetry: () => ref.read(orderHistoryProvider.notifier).refresh(),
        data: (page) {
          if (page.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: const <Widget>[
                SizedBox(height: 80),
                EmptyState(
                  title: 'تاریخچه‌ای وجود ندارد',
                  icon: Icons.history,
                ),
              ],
            );
          }

          return NotificationListener<ScrollEndNotification>(
            onNotification: (notification) {
              final metrics = notification.metrics;
              if (metrics.pixels >= metrics.maxScrollExtent - 200) {
                ref.read(orderHistoryProvider.notifier).loadMore();
              }

              return false;
            },
            child: ListView.separated(
              padding: const EdgeInsets.all(16),
              physics: const AlwaysScrollableScrollPhysics(),
              itemCount: page.length + (page.hasMore ? 1 : 0),
              separatorBuilder: (_, __) => const SizedBox(height: 12),
              itemBuilder: (context, index) {
                if (index >= page.length) {
                  return const Padding(
                    padding: EdgeInsets.all(16),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }

                return OrderTile(order: page.items[index]);
              },
            ),
          );
        },
      ),
    );
  }
}

/// Small helper used by a couple of screens that want a section heading.
class SectionHeading extends StatelessWidget {
  const SectionHeading(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
        child: Text(text, style: AppTypography.titleMedium),
      );
}
