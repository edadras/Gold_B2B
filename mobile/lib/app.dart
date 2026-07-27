import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/realtime/realtime_sync.dart';
import 'package:gold_b2b/core/router/app_router.dart';
import 'package:gold_b2b/core/security/app_lock.dart';
import 'package:gold_b2b/core/theme/app_theme.dart';

/// The application widget.
///
/// Three things are set up here and nowhere else:
///
///  1. RTL. The whole app is Persian, so `Directionality` is forced at the
///     root rather than left to the locale, and every NUMBER inside it opts
///     back out to LTR through `LtrNumber` (see `shared/widgets/ltr_number.dart`
///     for why that is not optional).
///  2. The realtime session — `realtimeSyncProvider` is read once so it is
///     constructed and starts watching auth state.
///  3. Idle-lock activity tracking, via `AppLockScope`.
class GoldB2BApp extends ConsumerStatefulWidget {
  const GoldB2BApp({super.key});

  @override
  ConsumerState<GoldB2BApp> createState() => _GoldB2BAppState();
}

class _GoldB2BAppState extends ConsumerState<GoldB2BApp> {
  @override
  void initState() {
    super.initState();

    // Construct the realtime coordinator. It listens to auth state from here
    // on: connecting when a session appears, disconnecting when it goes, and
    // re-reading financial state from REST after every reconnect.
    ref.read(realtimeSyncProvider);
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);

    return MaterialApp.router(
      title: 'سامانه معاملات طلا',
      debugShowCheckedModeBanner: false,
      routerConfig: router,
      theme: AppTheme.light(),
      locale: const Locale('fa', 'IR'),
      supportedLocales: const <Locale>[Locale('fa', 'IR')],
      localizationsDelegates: const <LocalizationsDelegate<dynamic>>[
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: AppLockScope(
          child: MediaQuery.withClampedTextScaling(
            // Accessibility: honour the system text size, up to 200%
            // (docs/07-mobile-flutter/02-screens.md §2.10). Clamping the upper
            // end keeps a 300% setting from making a settlement amount
            // unreadable rather than merely large.
            minScaleFactor: 1,
            maxScaleFactor: 2,
            child: child ?? const SizedBox.shrink(),
          ),
        ),
      ),
    );
  }
}
