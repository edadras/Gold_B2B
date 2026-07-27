import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/storage/secure_storage.dart';
import 'package:gold_b2b/features/auth/data/models/auth_session.dart';
import 'package:gold_b2b/shared/models/numeric_input.dart';

/// Authentication endpoints (docs/05-api/02-endpoints.md §2.1).
///
/// The repository owns token persistence: a caller that receives an
/// [AuthSession] can be sure it is already in the keychain, so there is no
/// window in which the app believes it is signed in but the interceptor
/// cannot find a token.
class AuthRepository {
  AuthRepository({required ApiClient api, required SecureStorage storage})
      : _api = api,
        _storage = storage;

  final ApiClient _api;
  final SecureStorage _storage;

  /// Mobile numbers are normalised to ASCII before being sent — a Persian
  /// keyboard produces ۰۹۱۲۳۴۵۶۷۸۹ and the server expects 09123456789.
  Future<LoginOutcome> login({
    required String mobile,
    required String password,
  }) async {
    final normalizedMobile = NumericInput.normalizeDigits(mobile).trim();

    final outcome = await _api.postOne(
      '/auth/login',
      LoginOutcome.fromJson,
      body: <String, dynamic>{
        'mobile': normalizedMobile,
        'password': password,
        'device_id': await _storage.readDeviceId(),
      },
    );

    if (outcome is LoginSucceeded) {
      await _persist(outcome.session);
    }

    await _storage.writeLastUserMobile(normalizedMobile);

    return outcome;
  }

  Future<AuthSession> verifySecondFactor({
    required String challengeToken,
    required String method,
    required String code,
  }) async {
    final session = await _api.postOne(
      '/auth/login/2fa',
      AuthSession.fromJson,
      body: <String, dynamic>{
        'challenge_token': challengeToken,
        'method': method,
        'code': NumericInput.normalizeDigits(code).trim(),
      },
    );

    await _persist(session);

    return session;
  }

  Future<void> sendOtp({required String mobile, String purpose = 'LOGIN'}) =>
      _api.postVoid(
        '/auth/otp/send',
        body: <String, dynamic>{
          'mobile': NumericInput.normalizeDigits(mobile).trim(),
          'purpose': purpose,
        },
      );

  Future<AuthSession> verifyOtp({
    required String mobile,
    required String code,
  }) async {
    final session = await _api.postOne(
      '/auth/otp/verify',
      AuthSession.fromJson,
      body: <String, dynamic>{
        'mobile': NumericInput.normalizeDigits(mobile).trim(),
        'code': NumericInput.normalizeDigits(code).trim(),
        'device_id': await _storage.readDeviceId(),
      },
    );

    await _persist(session);

    return session;
  }

  Future<void> requestPasswordReset(String mobile) => _api.postVoid(
        '/auth/password/forgot',
        body: <String, dynamic>{
          'mobile': NumericInput.normalizeDigits(mobile).trim(),
        },
      );

  /// Logs out server-side, then clears local state.
  ///
  /// The local clear happens even if the network call fails: a user who taps
  /// "sign out" on a phone they are about to hand over must end up signed out
  /// of THIS device regardless of connectivity. The server-side token stays
  /// alive until it expires, which is the lesser evil.
  Future<void> logout() async {
    try {
      await _api.postVoid('/auth/logout');
    } on Object {
      // Deliberately swallowed — see above.
    } finally {
      await _storage.clearSession();
    }
  }

  Future<void> logoutAllDevices() async {
    try {
      await _api.postVoid('/auth/logout-all');
    } finally {
      await _storage.clearSession();
    }
  }

  /// True when a token is on disk. Says nothing about whether it is still
  /// valid — that is settled by the first real request.
  Future<bool> hasStoredSession() async {
    final token = await _storage.readAccessToken();

    return token != null && token.isNotEmpty;
  }

  Future<String?> readLastUserMobile() => _storage.readLastUserMobile();

  /// Re-reads the current user after a cold start with a stored token.
  ///
  /// TODO(backend): docs/05-api/02-endpoints.md has no `GET /auth/me`.
  /// `GET /organization` returns the organisation but not the user's roles and
  /// permissions, which the UI needs in order to hide actions the user cannot
  /// perform. Until a `/auth/me` (or an expanded `/organization`) exists, a
  /// cold start with a valid token restores the ORGANISATION only and treats
  /// the permission list as empty, which fails closed — buttons are hidden
  /// rather than shown and then rejected.
  Future<OrganizationSummary> fetchOrganization() =>
      _api.getOne('/organization', OrganizationSummary.fromJson);

  Future<void> _persist(AuthSession session) => _storage.writeSession(
        accessToken: session.accessToken,
        refreshToken: session.refreshToken,
        expiresIn: session.expiresIn,
      );
}

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(
    api: ref.watch(apiClientProvider),
    storage: ref.watch(secureStorageProvider),
  ),
);
