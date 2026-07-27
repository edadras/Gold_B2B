import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/auth/application/auth_controller.dart';

/// Cold start: look for a stored token, verify it, and let the router take
/// over.
///
/// No navigation happens here. `AuthController.restore()` moves the state out
/// of [AuthStatus.unknown] and `app_router.dart`'s redirect does the rest —
/// which means there is exactly one place in the app that decides where an
/// unauthenticated user ends up.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(authControllerProvider.notifier).restore();
    });
  }

  @override
  Widget build(BuildContext context) => const Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Icon(
                Icons.workspace_premium_outlined,
                size: 64,
                color: AppColors.gold,
              ),
              SizedBox(height: 16),
              Text('سامانه معاملات طلا', style: AppTypography.titleMedium),
              SizedBox(height: 24),
              SizedBox(
                width: 24,
                height: 24,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ],
          ),
        ),
      );
}
