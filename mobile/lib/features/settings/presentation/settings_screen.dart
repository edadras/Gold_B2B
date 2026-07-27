import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/app_config.dart';
import 'package:gold_b2b/core/formatting/number_display.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/core/security/app_lock.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/auth/application/auth_controller.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';

class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key});

  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final config = ref.watch(appConfigProvider);
    final realtime = ref.watch(realtimeStatusProvider);
    final connectivity = ref.watch(connectivityProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('تنظیمات')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: <Widget>[
          SectionCard(
            title: 'حساب',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                KeyValueRow(
                  label: 'سازمان',
                  value: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      TierBadge(
                        auth.organization?.verificationTier,
                        showLabel: true,
                      ),
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          auth.organization?.displayName ?? '—',
                          style: AppTypography.body,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
                ),
                KeyValueRow(
                  label: 'وضعیت',
                  value: Text(
                    auth.organization?.statusLabel ?? '—',
                    style: AppTypography.body,
                  ),
                ),
                if (auth.user != null)
                  KeyValueRow(
                    label: 'کاربر',
                    value: Text(auth.user!.fullName, style: AppTypography.body),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: 'نمایش',
            child: SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: NumberDisplay.style == DigitStyle.persian,
              title: const Text('نمایش اعداد فارسی'),
              subtitle: Text(
                'اعداد انگلیسی برای کپی کردن شماره شبا و شماره پیگیری '
                'در برنامه‌های بانکی مناسب‌تر است.',
                style: AppTypography.caption,
              ),
              onChanged: (usePersian) {
                setState(() {
                  NumberDisplay.style =
                      usePersian ? DigitStyle.persian : DigitStyle.ascii;
                });

                // TODO(mobile-platform): the choice is process-wide and is NOT
                // persisted. Persisting it needs a settings store; the simplest
                // correct home is `SecureStorage`, which is already injected
                // everywhere. Restore it in main.dart before runApp.
              },
            ),
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: 'امنیت',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                KeyValueRow(
                  label: 'قفل خودکار',
                  value: Text(
                    'پس از ${config.idleLockTimeout.inMinutes} دقیقه '
                    'بی‌فعالیتی',
                    style: AppTypography.body,
                  ),
                ),
                KeyValueRow(
                  label: 'اتصال امن (Pinning)',
                  value: StatusBadge(
                    label: config.pinningEnabled ? 'فعال' : 'غیرفعال',
                    tone: config.pinningEnabled
                        ? StatusTone.positive
                        : StatusTone.warning,
                    dense: true,
                  ),
                ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: () =>
                      ref.read(appLockProvider.notifier).lockNow(),
                  icon: const Icon(Icons.lock_outline),
                  label: const Text('قفل کردن برنامه'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: 'وضعیت اتصال',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                KeyValueRow(
                  label: 'شبکه',
                  value: Text(
                    switch (connectivity) {
                      _ when connectivity.allowsFinancialActions => 'برقرار',
                      _ => 'قطع',
                    },
                    style: AppTypography.body,
                  ),
                ),
                KeyValueRow(
                  label: 'داده زنده',
                  value: StatusBadge(
                    label: realtime.persianLabel,
                    tone: realtime.isLive
                        ? StatusTone.positive
                        : StatusTone.warning,
                    dense: true,
                  ),
                ),
                KeyValueRow(
                  label: 'نسخه کلاینت',
                  value: Text(config.clientVersion, style: AppTypography.code),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: 'در پنل وب انجام می‌شود',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Text(
                  // docs/07-mobile-flutter/01-architecture.md §1.1 lists what
                  // the mobile app deliberately does not do. Saying so here is
                  // cheaper than a support call.
                  '· احراز هویت و بارگذاری اسناد (KYC)\n'
                  '· حسابداری تفصیلی و گزارش‌های سنگین\n'
                  '· مدیریت کاربران و نقش‌ها\n'
                  '· عملیات خزانه\n'
                  '· رسیدگی به اختلاف',
                  style: AppTypography.bodyMuted,
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.negative,
            ),
            onPressed: () => _confirmLogout(context),
            icon: const Icon(Icons.logout),
            label: const Text('خروج از حساب'),
          ),
          const SizedBox(height: 32),
        ],
      ),
    );
  }

  Future<void> _confirmLogout(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('خروج از حساب'),
        content: const Text(
          'برای ورود دوباره به شماره موبایل و رمز عبور نیاز خواهید داشت.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('خروج'),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      await ref.read(authControllerProvider.notifier).logout();
    }
  }
}
