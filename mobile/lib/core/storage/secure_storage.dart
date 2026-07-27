import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Keychain / EncryptedSharedPreferences wrapper.
///
/// Everything that would let someone act as this user lives here and nowhere
/// else: no tokens in SharedPreferences, no tokens in memory beyond the
/// lifetime of a request, nothing written to a log.
///
/// docs/07-mobile-flutter/01-architecture.md §1.10.
class SecureStorage {
  SecureStorage({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(
                encryptedSharedPreferences: true,
              ),
              iOptions: IOSOptions(
                // first_unlock_this_device: readable after the first unlock
                // following a boot, never synced to iCloud, never restored to
                // a different device from a backup.
                accessibility: KeychainAccessibility.first_unlock_this_device,
              ),
            );

  final FlutterSecureStorage _storage;

  static const String _accessTokenKey = 'auth.access_token';
  static const String _refreshTokenKey = 'auth.refresh_token';
  static const String _accessTokenExpiryKey = 'auth.access_token_expires_at';
  static const String _deviceIdKey = 'device.id';
  static const String _biometricEnabledKey = 'security.biometric_enabled';
  static const String _lastUserKey = 'auth.last_user';
  static const String _cachePrefix = 'cache.';

  // --- tokens --------------------------------------------------------------

  Future<String?> readAccessToken() => _storage.read(key: _accessTokenKey);

  Future<String?> readRefreshToken() => _storage.read(key: _refreshTokenKey);

  /// Persist a freshly issued token pair.
  ///
  /// [expiresIn] is the `expires_in` field from the auth response (seconds).
  /// It is stored as an absolute UTC instant so that a device clock change
  /// between issue and use cannot make an expired token look fresh forever.
  Future<void> writeSession({
    required String accessToken,
    required String refreshToken,
    required int expiresIn,
  }) async {
    final expiresAt = DateTime.now().toUtc().add(Duration(seconds: expiresIn));

    await Future.wait(<Future<void>>[
      _storage.write(key: _accessTokenKey, value: accessToken),
      _storage.write(key: _refreshTokenKey, value: refreshToken),
      _storage.write(
        key: _accessTokenExpiryKey,
        value: expiresAt.toIso8601String(),
      ),
    ]);
  }

  Future<void> writeAccessToken(String accessToken, {int? expiresIn}) async {
    await _storage.write(key: _accessTokenKey, value: accessToken);

    if (expiresIn != null) {
      await _storage.write(
        key: _accessTokenExpiryKey,
        value:
            DateTime.now().toUtc().add(Duration(seconds: expiresIn)).toIso8601String(),
      );
    }
  }

  Future<DateTime?> readAccessTokenExpiry() async {
    final raw = await _storage.read(key: _accessTokenExpiryKey);

    return raw == null ? null : DateTime.tryParse(raw)?.toUtc();
  }

  /// True when we already know the access token is dead, so the app can
  /// refresh proactively instead of taking a 401 on a trading action.
  Future<bool> isAccessTokenExpired({
    Duration leeway = const Duration(seconds: 30),
  }) async {
    final expiry = await readAccessTokenExpiry();
    if (expiry == null) {
      return false;
    }

    return DateTime.now().toUtc().add(leeway).isAfter(expiry);
  }

  Future<void> clearSession() async {
    await Future.wait(<Future<void>>[
      _storage.delete(key: _accessTokenKey),
      _storage.delete(key: _refreshTokenKey),
      _storage.delete(key: _accessTokenExpiryKey),
    ]);
  }

  /// Nuclear option: on logout-all, or when a token refresh fails.
  ///
  /// Deliberately keeps [_deviceIdKey] so that push registration and device
  /// trust survive a logout — losing it forces the user through the
  /// new-device verification flow every single time.
  Future<void> clearAll() async {
    final deviceId = await readDeviceId();
    await _storage.deleteAll();
    await _storage.write(key: _deviceIdKey, value: deviceId);
  }

  // --- device --------------------------------------------------------------

  /// Stable per-installation identifier, generated on first use.
  Future<String> readDeviceId() async {
    final existing = await _storage.read(key: _deviceIdKey);
    if (existing != null && existing.isNotEmpty) {
      return existing;
    }

    final generated = _randomHex(16);
    await _storage.write(key: _deviceIdKey, value: generated);

    return generated;
  }

  // --- preferences ---------------------------------------------------------

  Future<bool> readBiometricEnabled() async =>
      await _storage.read(key: _biometricEnabledKey) == 'true';

  Future<void> writeBiometricEnabled({required bool enabled}) =>
      _storage.write(key: _biometricEnabledKey, value: enabled.toString());

  /// Mobile number of the last user to sign in, so the login screen can
  /// pre-fill it. Not sensitive on its own, but it lives here to keep the
  /// number of storage backends at one.
  Future<String?> readLastUserMobile() => _storage.read(key: _lastUserKey);

  Future<void> writeLastUserMobile(String mobile) =>
      _storage.write(key: _lastUserKey, value: mobile);

  // --- read-only offline cache --------------------------------------------
  //
  // Cached balances and lists are financial data, so they go in the same
  // encrypted store as the tokens rather than in a plain SQLite file.
  //
  // TODO(mobile-platform): this is a deliberately minimal cache — a handful of
  // small JSON blobs. It is fine for the balance snapshot and the pending
  // settlement queue, and it is NOT fine for the ledger or trade history,
  // which need pagination and querying. When those screens gain offline
  // support, introduce drift (as the architecture doc specifies) and move
  // list caching there, leaving only the balance snapshot here.

  Future<void> writeCache(String key, Map<String, dynamic> value) =>
      _storage.write(
        key: '$_cachePrefix$key',
        value: jsonEncode(<String, dynamic>{
          'cached_at': DateTime.now().toUtc().toIso8601String(),
          'payload': value,
        }),
      );

  /// Returns the cached payload and the instant it was written, or null.
  Future<CachedEntry?> readCache(String key) async {
    final raw = await _storage.read(key: '$_cachePrefix$key');
    if (raw == null) {
      return null;
    }

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map<String, dynamic>) {
        return null;
      }

      final cachedAt = DateTime.tryParse('${decoded['cached_at']}');
      final payload = decoded['payload'];
      if (cachedAt == null || payload is! Map<String, dynamic>) {
        return null;
      }

      return CachedEntry(payload: payload, cachedAt: cachedAt.toUtc());
    } on FormatException {
      // A corrupt cache entry is never worth crashing over; treat it as absent.
      await _storage.delete(key: '$_cachePrefix$key');

      return null;
    }
  }

  Future<void> deleteCache(String key) =>
      _storage.delete(key: '$_cachePrefix$key');

  static String _randomHex(int bytes) {
    final random = Random.secure();
    final buffer = StringBuffer();
    for (var i = 0; i < bytes; i++) {
      buffer.write(random.nextInt(256).toRadixString(16).padLeft(2, '0'));
    }

    return buffer.toString();
  }
}

/// A cache hit, carrying the age the UI must display alongside the data.
final class CachedEntry {
  const CachedEntry({required this.payload, required this.cachedAt});

  final Map<String, dynamic> payload;
  final DateTime cachedAt;

  Duration ageFrom(DateTime now) => now.toUtc().difference(cachedAt);
}

/// Well-known cache keys, so no two features silently collide.
final class CacheKeys {
  const CacheKeys._();

  static const String balances = 'balances';
  static const String pendingSettlements = 'settlements.pending';
  static const String instruments = 'market.instruments';
  static const String lots = 'lots';
  static const String metaEnums = 'meta.enums';
  static const String metaSettings = 'meta.settings';
}
