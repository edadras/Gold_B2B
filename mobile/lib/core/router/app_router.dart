
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/security/app_lock.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/features/auth/application/auth_controller.dart';
import 'package:gold_b2b/features/auth/presentation/lock_screen.dart';
import 'package:gold_b2b/features/auth/presentation/login_screen.dart';
import 'package:gold_b2b/features/auth/presentation/splash_screen.dart';
import 'package:gold_b2b/features/auth/presentation/two_factor_screen.dart';
import 'package:gold_b2b/features/counterparties/presentation/counterparties_screen.dart';
import 'package:gold_b2b/features/dashboard/presentation/dashboard_screen.dart';
import 'package:gold_b2b/features/lots/presentation/lot_detail_screen.dart';
import 'package:gold_b2b/features/lots/presentation/lots_screen.dart';
import 'package:gold_b2b/features/lots/presentation/scan_screen.dart';
import 'package:gold_b2b/features/lots/presentation/verify_result_screen.dart';
import 'package:gold_b2b/features/market/presentation/instrument_detail_screen.dart';
import 'package:gold_b2b/features/market/presentation/market_screen.dart';
import 'package:gold_b2b/features/notifications/presentation/notifications_screen.dart';
import 'package:gold_b2b/features/orders/data/models/order.dart';
import 'package:gold_b2b/features/orders/presentation/order_form_screen.dart';
import 'package:gold_b2b/features/orders/presentation/orders_screen.dart';
import 'package:gold_b2b/features/rfq/presentation/rfq_create_screen.dart';
import 'package:gold_b2b/features/rfq/presentation/rfq_detail_screen.dart';
import 'package:gold_b2b/features/settings/presentation/settings_screen.dart';
import 'package:gold_b2b/features/settlements/presentation/declare_payment_screen.dart';
import 'package:gold_b2b/features/settlements/presentation/settlement_detail_screen.dart';
import 'package:gold_b2b/features/settlements/presentation/settlements_screen.dart';

/// Routing and the redirect that owns "where is the user allowed to be".
///
/// Exactly one redirect decides everything: splash while auth is unresolved,
/// login/2FA when signed out, the lock screen when the idle timer has fired,
/// and the shell otherwise. Scattering `Navigator.push` calls through the auth
/// screens instead would give several places an opinion about the same
/// question, and they would eventually disagree.
final routerProvider = Provider<GoRouter>((ref) {
  final refresh = _RouterRefresh(ref);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    initialLocation: '/splash',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final lock = ref.read(appLockProvider);
      final location = state.matchedLocation;

      // Public: a QR verification link may be opened by anyone, including
      // somebody with no account, and must not bounce to login.
      if (location.startsWith('/lots/verify/')) {
        return null;
      }

      if (!auth.isResolved) {
        return location == '/splash' ? null : '/splash';
      }

      switch (auth.status) {
        case AuthStatus.unauthenticated:
          return location == '/login' ? null : '/login';

        case AuthStatus.awaitingSecondFactor:
          return location == '/two-factor' ? null : '/two-factor';

        case AuthStatus.authenticated:
          if (lock == LockState.locked) {
            return location == '/lock' ? null : '/lock';
          }

          if (location == '/splash' ||
              location == '/login' ||
              location == '/two-factor' ||
              location == '/lock') {
            return '/dashboard';
          }

          return null;

        case AuthStatus.unknown:
          return '/splash';
      }
    },
    routes: <RouteBase>[
      GoRoute(
        path: '/splash',
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: '/login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/two-factor',
        builder: (context, state) => const TwoFactorScreen(),
      ),
      GoRoute(
        path: '/lock',
        builder: (context, state) => const LockScreen(),
      ),

      // --- main shell ------------------------------------------------------
      ShellRoute(
        builder: (context, state, child) =>
            MainShell(location: state.matchedLocation, child: child),
        routes: <RouteBase>[
          GoRoute(
            path: '/dashboard',
            builder: (context, state) => const DashboardScreen(),
          ),
          GoRoute(
            path: '/market',
            builder: (context, state) => const MarketScreen(),
          ),
          GoRoute(
            path: '/orders',
            builder: (context, state) => const OrdersScreen(),
          ),
          GoRoute(
            path: '/settlements',
            builder: (context, state) => const SettlementsScreen(),
          ),
          GoRoute(
            path: '/lots',
            builder: (context, state) => const LotsScreen(),
          ),
        ],
      ),

      // --- pushed screens --------------------------------------------------
      GoRoute(
        path: '/market/:code',
        builder: (context, state) => InstrumentDetailScreen(
          instrumentCode: state.pathParameters['code']!,
        ),
      ),
      GoRoute(
        path: '/market/:code/order/:side',
        builder: (context, state) => OrderFormScreen(
          instrumentCode: state.pathParameters['code']!,
          side: OrderSideX.parse(state.pathParameters['side'] ?? 'BUY'),
        ),
      ),
      GoRoute(
        path: '/settlements/:id',
        builder: (context, state) => SettlementDetailScreen(
          settlementId: _intParam(state.pathParameters['id']),
        ),
      ),
      GoRoute(
        path: '/settlements/:id/declare-payment',
        builder: (context, state) => DeclarePaymentScreen(
          settlementId: _intParam(state.pathParameters['id']),
        ),
      ),
      GoRoute(
        path: '/rfq/create',
        builder: (context, state) => const RfqCreateScreen(),
      ),
      GoRoute(
        path: '/rfq/:id',
        builder: (context, state) =>
            RfqDetailScreen(rfqId: _intParam(state.pathParameters['id'])),
      ),
      GoRoute(
        path: '/lots/scan',
        builder: (context, state) => const ScanScreen(),
      ),
      GoRoute(
        path: '/lots/verify/:token',
        builder: (context, state) =>
            VerifyResultScreen(qrToken: state.pathParameters['token']!),
      ),
      GoRoute(
        path: '/lots/:id',
        builder: (context, state) =>
            LotDetailScreen(lotId: _intParam(state.pathParameters['id'])),
      ),
      GoRoute(
        path: '/counterparties',
        builder: (context, state) => const CounterpartiesScreen(),
      ),
      GoRoute(
        path: '/notifications',
        builder: (context, state) => const NotificationsScreen(),
      ),
      GoRoute(
        path: '/settings',
        builder: (context, state) => const SettingsScreen(),
      ),
    ],
    errorBuilder: (context, state) => Scaffold(
      appBar: AppBar(title: const Text('صفحه یافت نشد')),
      body: Center(child: Text('مسیر ${state.uri} وجود ندارد.')),
    ),
  );
});

int _intParam(String? raw) => int.tryParse(raw ?? '') ?? 0;

/// Bridges Riverpod state changes into GoRouter's `refreshListenable`.
class _RouterRefresh extends ChangeNotifier {
  _RouterRefresh(Ref ref) {
    _auth = ref.listen<AuthState>(
      authControllerProvider,
      (_, __) => notifyListeners(),
    );
    _lock = ref.listen<LockState>(
      appLockProvider,
      (_, __) => notifyListeners(),
    );
  }

  ProviderSubscription<AuthState>? _auth;
  ProviderSubscription<LockState>? _lock;

  @override
  void dispose() {
    _auth?.close();
    _lock?.close();
    super.dispose();
  }
}

/// Bottom navigation for the five main destinations
/// (docs/07-mobile-flutter/02-screens.md §2.1).
class MainShell extends StatelessWidget {
  const MainShell({required this.location, required this.child, super.key});

  final String location;
  final Widget child;

  static const List<_Destination> _destinations = <_Destination>[
    _Destination('/dashboard', Icons.home_outlined, Icons.home, 'خانه'),
    _Destination('/market', Icons.show_chart, Icons.show_chart, 'بازار'),
    _Destination('/orders', Icons.list_alt_outlined, Icons.list_alt, 'سفارش‌ها'),
    _Destination(
      '/settlements',
      Icons.account_balance_wallet_outlined,
      Icons.account_balance_wallet,
      'تسویه',
    ),
    _Destination(
      '/lots',
      Icons.inventory_2_outlined,
      Icons.inventory_2,
      'دارایی',
    ),
  ];

  int get _currentIndex {
    final index = _destinations.indexWhere(
      (destination) => location.startsWith(destination.path),
    );

    return index < 0 ? 0 : index;
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: child,
        bottomNavigationBar: NavigationBar(
          selectedIndex: _currentIndex,
          onDestinationSelected: (index) =>
              context.go(_destinations[index].path),
          indicatorColor: AppColors.goldLight,
          destinations: <NavigationDestination>[
            for (final destination in _destinations)
              NavigationDestination(
                icon: Icon(destination.icon),
                selectedIcon: Icon(destination.selectedIcon),
                label: destination.label,
              ),
          ],
        ),
      );
}

class _Destination {
  const _Destination(this.path, this.icon, this.selectedIcon, this.label);

  final String path;
  final IconData icon;
  final IconData selectedIcon;
  final String label;
}
