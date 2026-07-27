<div dir="rtl">

# ۱. معماری اپلیکیشن Flutter

## ۱.۱ دامنه اپ موبایل

اپ موبایل **همه کارها را انجام نمی‌دهد**. تمرکز آن روی کارهایی است که
معامله‌گر در حرکت انجام می‌دهد:

</div>

```
✅ در اپ موبایل
   · مشاهده موجودی و قیمت
   · ثبت و لغو سفارش
   · پیشنهاد و پذیرش OTC
   · پاسخ به RFQ
   · تأیید تسویه (پرداخت/دریافت)
   · اعلان‌های فوری
   · گزارش خلاصه روزانه
   · اسکن QR برای تأیید اصالت lot
   · پذیرش تهاتر

❌ در وب انجام می‌شود
   · KYC و آپلود اسناد
   · حسابداری تفصیلی
   · گزارش‌های سنگین
   · مدیریت کاربران و نقش‌ها
   · عملیات خزانه
   · رسیدگی به اختلاف (فقط اعلان و مشاهده در موبایل)
```

<div dir="rtl">

---

## ۱.۲ پشته فنی

| لایه | انتخاب | دلیل |
|---|---|---|
| Flutter | 3.x (Dart 3.x) | الزام پروژه |
| مدیریت وضعیت | **Riverpod 2** | تست‌پذیری، بدون BuildContext، compile-safe |
| مسیریابی | **go_router** | deep link، مسیر تودرتو |
| شبکه | **dio** | interceptor، retry، cancel |
| مدل‌ها | **freezed** + **json_serializable** | تغییرناپذیری، union type |
| ذخیره امن | **flutter_secure_storage** | Keychain / EncryptedSharedPreferences |
| کش محلی | **drift** (SQLite) | کوئری type-safe، حالت آفلاین |
| WebSocket | **pusher_channels_flutter** | سازگار با Reverb |
| Push | **firebase_messaging** | FCM + APNs |
| بیومتریک | **local_auth** | |
| نمودار | **fl_chart** | سبک و قابل سفارشی‌سازی |
| تاریخ شمسی | **shamsi_date** | |
| تست | **flutter_test**, **mocktail**, **patrol** | |

---

## ۱.۳ ساختار پوشه

</div>

```
mobile/lib/
├── main.dart
├── app.dart
│
├── core/
│   ├── config/
│   │   ├── app_config.dart          محیط‌ها (dev/staging/prod)
│   │   └── constants.dart
│   ├── network/
│   │   ├── api_client.dart
│   │   ├── interceptors/
│   │   │   ├── auth_interceptor.dart
│   │   │   ├── idempotency_interceptor.dart
│   │   │   ├── error_interceptor.dart
│   │   │   ├── retry_interceptor.dart
│   │   │   └── logging_interceptor.dart
│   │   └── api_exception.dart
│   ├── storage/
│   │   ├── secure_storage.dart
│   │   └── local_db.dart
│   ├── realtime/
│   │   ├── websocket_service.dart
│   │   └── channel_manager.dart
│   ├── formatting/
│   │   ├── weight_formatter.dart    ◄── میلی‌گرم ► نمایش
│   │   ├── money_formatter.dart
│   │   ├── purity_formatter.dart
│   │   └── jalali_formatter.dart
│   ├── errors/
│   ├── router/
│   │   └── app_router.dart
│   └── theme/
│       ├── app_theme.dart
│       ├── colors.dart
│       └── typography.dart
│
├── features/
│   ├── auth/
│   │   ├── data/
│   │   │   ├── auth_repository.dart
│   │   │   └── models/
│   │   ├── domain/
│   │   │   └── entities/
│   │   ├── application/
│   │   │   └── auth_controller.dart      (Riverpod Notifier)
│   │   └── presentation/
│   │       ├── login_screen.dart
│   │       ├── otp_screen.dart
│   │       ├── two_factor_screen.dart
│   │       └── widgets/
│   │
│   ├── dashboard/
│   ├── market/
│   ├── orders/
│   ├── otc/
│   ├── rfq/
│   ├── settlements/
│   ├── lots/
│   ├── counterparties/
│   ├── notifications/
│   ├── reports/
│   └── settings/
│
└── shared/
    ├── widgets/
    │   ├── amount_display.dart
    │   ├── weight_display.dart
    │   ├── loading_overlay.dart
    │   ├── error_view.dart
    │   ├── empty_state.dart
    │   └── confirm_sheet.dart
    ├── models/
    │   ├── fine_weight.dart          ◄── معادل Dart از VO بک‌اند
    │   ├── rial.dart
    │   └── purity.dart
    └── extensions/
```

<div dir="rtl">

---

## ۱.۴ لایه‌بندی feature

</div>

```
presentation/          ویجت‌ها — بدون منطق کسب‌وکار
       │  ref.watch / ref.read
       ▼
application/           Riverpod Notifier — وضعیت UI
       │
       ▼
domain/                Entity، Use Case — منطق خالص
       │
       ▼
data/                  Repository — API، کش، نگاشت
```

<div dir="rtl">

---

## ۱.۵ اشیاء ارزش در Dart

**قاعده حیاتی:** همان قواعد بک‌اند در موبایل هم اعمال می‌شود.
هیچ `double` برای مقادیر مالی.

</div>

```dart
// shared/models/fine_weight.dart

import 'package:freezed_annotation/freezed_annotation.dart';

@immutable
class FineWeight implements Comparable<FineWeight> {
  final int milligrams;

  const FineWeight._(this.milligrams);

  factory FineWeight.fromMilligrams(int mg) {
    if (mg < 0) throw ArgumentError('Weight cannot be negative');
    return FineWeight._(mg);
  }

  /// ⚠️ فقط برای ورودی کاربر — همیشه به int تبدیل می‌شود
  factory FineWeight.fromGramsInput(String input) {
    final normalized = input.replaceAll(',', '').trim();
    final parts = normalized.split('.');

    final whole = int.parse(parts[0]);
    final frac  = parts.length > 1
        ? int.parse(parts[1].padRight(3, '0').substring(0, 3))
        : 0;

    return FineWeight._(whole * 1000 + frac);
  }

  static const zero = FineWeight._(0);

  /// نمایش: "۱,۲۴۷.۳۲۰"
  String get displayGrams {
    final whole = milligrams ~/ 1000;
    final frac  = milligrams % 1000;
    return '${_group(whole)}.${frac.toString().padLeft(3, '0')}';
  }

  String get displayGramsWithUnit => '$displayGrams گرم';

  FineWeight operator +(FineWeight o) => FineWeight._(milligrams + o.milligrams);
  FineWeight operator -(FineWeight o) => FineWeight._(milligrams - o.milligrams);
  bool operator >(FineWeight o) => milligrams > o.milligrams;
  bool operator <(FineWeight o) => milligrams < o.milligrams;

  @override
  int compareTo(FineWeight o) => milligrams.compareTo(o.milligrams);

  @override
  bool operator ==(Object o) => o is FineWeight && o.milligrams == milligrams;

  @override
  int get hashCode => milligrams.hashCode;

  static String _group(int n) =>
      n.toString().replaceAllMapped(
        RegExp(r'(\d)(?=(\d{3})+$)'), (m) => '${m[1]},');
}
```

<div dir="rtl">

</div>

```dart
// shared/models/rial.dart

@immutable
class Rial {
  final int amount;

  const Rial._(this.amount);

  factory Rial.fromRial(int r) => Rial._(r);
  static const zero = Rial._(0);

  /// "۱۹,۶۴۹,۴۳۰,۰۰۰ ریال"
  String get display => '${_group(amount)} ریال';

  /// نمایش خلاصه برای فضای کم: "۱۹.۶۵ میلیارد"
  String get displayCompact {
    if (amount.abs() >= 1000000000) {
      return '${(amount / 1000000000).toStringAsFixed(2)} میلیارد ریال';
    }
    if (amount.abs() >= 1000000) {
      return '${(amount / 1000000).toStringAsFixed(1)} میلیون ریال';
    }
    return display;
  }

  Rial operator +(Rial o) => Rial._(amount + o.amount);
  Rial operator -(Rial o) => Rial._(amount - o.amount);
  // ...
}
```

<div dir="rtl">

> ⚠️ `displayCompact` از `double` استفاده می‌کند اما **فقط برای نمایش**.
> هیچ محاسبه‌ای روی آن انجام نمی‌شود.

---

## ۱.۶ کلاینت API

</div>

```dart
// core/network/api_client.dart

class ApiClient {
  late final Dio _dio;

  ApiClient({required AppConfig config, required SecureStorage storage}) {
    _dio = Dio(BaseOptions(
      baseUrl: config.apiBaseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 30),
      headers: {
        'Accept': 'application/json',
        'Accept-Language': 'fa',
        'X-Client-Version': config.clientVersion,
      },
    ));

    _dio.interceptors.addAll([
      AuthInterceptor(storage: storage, dio: _dio),
      IdempotencyInterceptor(),
      RetryInterceptor(),
      ErrorInterceptor(),
      if (config.isDebug) LoggingInterceptor(),
    ]);

    // Certificate pinning در تولید
    if (config.isProduction) {
      _dio.httpClientAdapter = _pinnedAdapter(config.certificateFingerprints);
    }
  }
}
```

<div dir="rtl">

### Interceptor مربوط به Idempotency

</div>

```dart
class IdempotencyInterceptor extends Interceptor {
  static const _requiresKey = {
    '/orders', '/otc-offers', '/rfqs', '/settlements',
    '/vault/deposits', '/vault/withdrawals', '/netting-batches',
  };

  final _uuid = const Uuid();

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final needsKey = options.method == 'POST' &&
        _requiresKey.any((p) => options.path.contains(p));

    if (needsKey && !options.headers.containsKey('Idempotency-Key')) {
      options.headers['Idempotency-Key'] = _uuid.v4();
    }

    handler.next(options);
  }
}
```

<div dir="rtl">

> ⚠️ **مهم:** اگر درخواست به دلیل خطای شبکه تلاش مجدد شود، باید
> **همان کلید** استفاده شود، نه کلید جدید. `RetryInterceptor` نباید
> کلید را تغییر دهد.

### Interceptor تازه‌سازی توکن

</div>

```dart
class AuthInterceptor extends QueuedInterceptor {
  bool _isRefreshing = false;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await storage.readAccessToken();
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    if (err.response?.statusCode != 401 || _isRefreshing) {
      return handler.next(err);
    }

    _isRefreshing = true;
    try {
      final newToken = await _refreshToken();
      await storage.writeAccessToken(newToken);

      // تکرار درخواست اصلی با همان Idempotency-Key
      final response = await dio.fetch(
        err.requestOptions..headers['Authorization'] = 'Bearer $newToken',
      );
      return handler.resolve(response);

    } catch (_) {
      await storage.clear();
      _navigateToLogin();
      return handler.next(err);
    } finally {
      _isRefreshing = false;
    }
  }
}
```

<div dir="rtl">

`QueuedInterceptor` تضمین می‌کند چند درخواست همزمان با ۴۰۱، فقط
یک بار refresh را فراخوانی کنند.

---

## ۱.۷ مدیریت وضعیت با Riverpod

</div>

```dart
// features/orders/application/orders_controller.dart

@riverpod
class OrdersController extends _$OrdersController {
  @override
  Future<List<Order>> build() async {
    // اشتراک در WebSocket برای به‌روزرسانی زنده
    ref.listen(orderUpdatesProvider, (_, next) {
      next.whenData(_applyUpdate);
    });

    return ref.read(orderRepositoryProvider).fetchOpen();
  }

  Future<void> place(PlaceOrderRequest request) async {
    state = const AsyncLoading();

    state = await AsyncValue.guard(() async {
      final order = await ref.read(orderRepositoryProvider).place(request);

      // موجودی را باطل کن تا مجدداً خوانده شود
      ref.invalidate(balanceProvider);

      return [order, ...(state.valueOrNull ?? [])];
    });
  }

  Future<void> cancel(int orderId) async {
    final previous = state.valueOrNull ?? [];

    // به‌روزرسانی خوش‌بینانه
    state = AsyncData(previous.map((o) =>
        o.id == orderId ? o.copyWith(status: OrderStatus.cancelling) : o
    ).toList());

    try {
      await ref.read(orderRepositoryProvider).cancel(orderId);
      ref.invalidate(balanceProvider);
    } catch (e) {
      state = AsyncData(previous);   // بازگردانی
      rethrow;
    }
  }

  void _applyUpdate(OrderUpdate update) {
    final current = state.valueOrNull;
    if (current == null) return;

    state = AsyncData(current.map((o) =>
        o.id == update.orderId ? o.applyUpdate(update) : o
    ).toList());
  }
}
```

<div dir="rtl">

---

## ۱.۸ مدیریت WebSocket

</div>

```dart
// core/realtime/websocket_service.dart

class WebSocketService {
  PusherChannelsFlutter? _pusher;
  final _reconnectBackoff = ExponentialBackoff(
    initial: Duration(seconds: 1),
    max: Duration(seconds: 30),
  );

  Future<void> connect() async {
    _pusher = PusherChannelsFlutter.getInstance();

    await _pusher!.init(
      apiKey: config.reverbKey,
      cluster: '',
      onConnectionStateChange: _onStateChange,
      onAuthorizer: _authorizeChannel,
      onError: _onError,
    );

    await _pusher!.connect();
  }

  void _onStateChange(String current, String previous) {
    if (current == 'CONNECTED') {
      _reconnectBackoff.reset();
      _resubscribeAll();

      // ⚠️ حیاتی: پس از هر اتصال مجدد، وضعیت را از REST همگام کن
      ref.invalidate(balanceProvider);
      ref.invalidate(ordersControllerProvider);
      ref.invalidate(pendingSettlementsProvider);
    }

    if (current == 'DISCONNECTED') {
      _scheduleReconnect();
    }
  }

  Future<Map<String, dynamic>> _authorizeChannel(
      String channelName, String socketId, dynamic options) async {
    final response = await apiClient.post('/broadcasting/auth', data: {
      'socket_id': socketId,
      'channel_name': channelName,
    });
    return response.data;
  }
}
```

<div dir="rtl">

**قاعده حیاتی:** WebSocket تحویل را تضمین نمی‌کند. هر بار که اتصال
برقرار می‌شود، وضعیت مالی باید از REST بازخوانی شود.

---

## ۱.۹ حالت آفلاین

</div>

```
✅ در آفلاین در دسترس است (کش شده)
   · آخرین موجودی (با نشانه «آخرین به‌روزرسانی: X دقیقه پیش»)
   · فهرست معاملات اخیر
   · فهرست lotها
   · اعلان‌های خوانده‌نشده
   · گزارش‌های تولیدشده قبلی

❌ در آفلاین ممکن نیست
   · ثبت سفارش
   · تأیید تسویه
   · هر عملیات مالی

⚠️ صف آفلاین برای عملیات مالی پیاده‌سازی نمی‌شود.
   دلیل: قیمت‌ها لحظه‌ای تغییر می‌کنند؛ اجرای معامله با
   نیت ۱۰ دقیقه پیش خطرناک است.
```

<div dir="rtl">

</div>

```dart
// نمایش وضعیت اتصال
class ConnectivityBanner extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = ref.watch(connectivityProvider);

    return switch (status) {
      ConnectivityStatus.online => const SizedBox.shrink(),
      ConnectivityStatus.offline => const _Banner(
          color: Colors.red,
          icon: Icons.cloud_off,
          message: 'اتصال اینترنت برقرار نیست — امکان معامله وجود ندارد',
        ),
      ConnectivityStatus.degraded => const _Banner(
          color: Colors.orange,
          icon: Icons.sync_problem,
          message: 'اتصال ضعیف — داده‌ها ممکن است به‌روز نباشند',
        ),
    };
  }
}
```

<div dir="rtl">

---

## ۱.۱۰ امنیت موبایل

</div>

```dart
// ذخیره امن
final _storage = FlutterSecureStorage(
  aOptions: AndroidOptions(encryptedSharedPreferences: true),
  iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock_this_device),
);

// قفل خودکار
class AppLockService {
  Timer? _timer;
  static const _timeout = Duration(minutes: 5);

  void onUserActivity() {
    _timer?.cancel();
    _timer = Timer(_timeout, _lock);
  }

  void _lock() {
    router.go('/lock');
  }
}

// مسدودسازی اسکرین‌شات در صفحات مالی
class SecureScreen extends StatefulWidget {
  @override
  void initState() {
    super.initState();
    if (Platform.isAndroid) {
      FlutterWindowManager.addFlags(FlutterWindowManager.FLAG_SECURE);
    }
  }

  @override
  void dispose() {
    if (Platform.isAndroid) {
      FlutterWindowManager.clearFlags(FlutterWindowManager.FLAG_SECURE);
    }
    super.dispose();
  }
}

// تشخیص Root/Jailbreak
Future<void> _checkDeviceIntegrity() async {
  final isCompromised = await FlutterJailbreakDetection.jailbroken;

  if (isCompromised) {
    // هشدار، اما مسدود نکن (ممکن است کاربر واقعی باشد)
    // فقط عملیات پرارزش را محدود کن
    ref.read(securityStateProvider.notifier).setDeviceCompromised(true);
  }
}
```

<div dir="rtl">

### تأیید تراکنش

</div>

```dart
Future<bool> confirmHighValueOperation({
  required String title,
  required String summary,
  required Rial amount,
}) async {
  // ۱) نمایش خلاصه صریح
  final confirmed = await showModalBottomSheet<bool>(
    context: context,
    isDismissible: false,
    builder: (_) => ConfirmSheet(
      title: title,
      summary: summary,
      amount: amount,
      warningIfLarge: amount.amount > 1000000000,
    ),
  );

  if (confirmed != true) return false;

  // ۲) بیومتریک یا TOTP
  final auth = LocalAuthentication();
  if (await auth.canCheckBiometrics) {
    return auth.authenticate(
      localizedReason: 'تأیید عملیات مالی',
      options: const AuthenticationOptions(
        biometricOnly: false,
        stickyAuth: true,
      ),
    );
  }

  // ۳) fallback به TOTP
  return await _promptTotp();
}
```

<div dir="rtl">

---

## ۱.۱۱ راست‌به‌چپ و محلی‌سازی

</div>

```dart
MaterialApp.router(
  locale: const Locale('fa', 'IR'),
  supportedLocales: const [Locale('fa', 'IR')],
  localizationsDelegates: const [
    AppLocalizations.delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  builder: (context, child) => Directionality(
    textDirection: TextDirection.rtl,
    child: child!,
  ),
);
```

<div dir="rtl">

### اعداد فارسی

</div>

```dart
extension PersianNumbers on String {
  static const _en = ['0','1','2','3','4','5','6','7','8','9'];
  static const _fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

  String toPersianDigits() {
    var result = this;
    for (var i = 0; i < 10; i++) {
      result = result.replaceAll(_en[i], _fa[i]);
    }
    return result;
  }

  /// ⚠️ برای ورودی کاربر — تبدیل به انگلیسی پیش از پارس
  String toEnglishDigits() {
    var result = this;
    for (var i = 0; i < 10; i++) {
      result = result.replaceAll(_fa[i], _en[i]);
      result = result.replaceAll(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'][i], _en[i]);
    }
    return result;
  }
}
```

<div dir="rtl">

> ⚠️ همیشه ورودی کاربر را با `toEnglishDigits()` نرمال کنید پیش از پارس.
> کاربر ممکن است با کیبورد فارسی یا عربی عدد وارد کند.

</div>
