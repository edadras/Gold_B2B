import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/features/auth/data/auth_repository.dart';
import 'package:gold_b2b/features/auth/data/models/auth_session.dart';

enum AuthStatus {
  /// Before the splash screen has finished looking for a stored token.
  unknown,

  unauthenticated,

  /// Credentials accepted, waiting for the second factor.
  awaitingSecondFactor,

  authenticated,
}

final class AuthState {
  const AuthState({
    required this.status,
    this.user,
    this.organization,
    this.challenge,
    this.failure,
    this.isBusy = false,
  });

  const AuthState.unknown() : this(status: AuthStatus.unknown);

  const AuthState.signedOut({AppFailure? failure})
      : this(status: AuthStatus.unauthenticated, failure: failure);

  final AuthStatus status;
  final UserProfile? user;
  final OrganizationSummary? organization;
  final LoginChallenge? challenge;
  final AppFailure? failure;
  final bool isBusy;

  bool get isAuthenticated => status == AuthStatus.authenticated;

  bool get isResolved => status != AuthStatus.unknown;

  /// The whole app asks this before enabling any trading control.
  bool get canTrade => organization?.canTrade ?? false;

  bool can(String permission) => user?.can(permission) ?? false;

  AuthState copyWith({
    AuthStatus? status,
    UserProfile? user,
    OrganizationSummary? organization,
    LoginChallenge? challenge,
    AppFailure? failure,
    bool? isBusy,
    bool clearFailure = false,
    bool clearChallenge = false,
  }) =>
      AuthState(
        status: status ?? this.status,
        user: user ?? this.user,
        organization: organization ?? this.organization,
        challenge: clearChallenge ? null : (challenge ?? this.challenge),
        failure: clearFailure ? null : (failure ?? this.failure),
        isBusy: isBusy ?? this.isBusy,
      );
}

/// Owns the session.
///
/// Everything that cares about "am I signed in" watches this, including the
/// router's redirect. It is the only thing allowed to change [AuthStatus].
class AuthController extends StateNotifier<AuthState> {
  AuthController({
    required AuthRepository repository,
    required SessionEvents sessionEvents,
  })  : _repository = repository,
        super(const AuthState.unknown()) {
    // A refresh that failed deep inside the interceptor chain surfaces here.
    _forcedLogouts = sessionEvents.forcedLogouts.listen((_) {
      state = const AuthState.signedOut();
    });
  }

  final AuthRepository _repository;
  StreamSubscription<void>? _forcedLogouts;

  /// Called once from the splash screen.
  Future<void> restore() async {
    if (!await _repository.hasStoredSession()) {
      state = const AuthState.signedOut();

      return;
    }

    try {
      final organization = await _repository.fetchOrganization();
      state = AuthState(
        status: AuthStatus.authenticated,
        organization: organization,
      );
    } on UnauthorizedException {
      // The stored token is dead and refreshing did not help.
      state = const AuthState.signedOut();
    } on AppFailure catch (failure) {
      // Offline cold start. The token may well be fine, but nothing can be
      // verified, so the user goes to login rather than into a shell whose
      // every screen will fail. Cached data is reachable from there.
      state = AuthState.signedOut(failure: failure);
    }
  }

  Future<void> login({
    required String mobile,
    required String password,
  }) async {
    state = state.copyWith(isBusy: true, clearFailure: true);

    try {
      final outcome =
          await _repository.login(mobile: mobile, password: password);

      switch (outcome) {
        case LoginSucceeded(:final session):
          state = AuthState(
            status: AuthStatus.authenticated,
            user: session.user,
            organization: session.organization,
          );

        case LoginRequiresSecondFactor(:final challenge):
          state = AuthState(
            status: AuthStatus.awaitingSecondFactor,
            challenge: challenge,
          );
      }
    } on AppFailure catch (failure) {
      state = state.copyWith(
        status: AuthStatus.unauthenticated,
        isBusy: false,
        failure: failure,
        clearChallenge: true,
      );
    }
  }

  Future<void> submitSecondFactor(String code) async {
    final challenge = state.challenge;
    if (challenge == null) {
      return;
    }

    state = state.copyWith(isBusy: true, clearFailure: true);

    try {
      final session = await _repository.verifySecondFactor(
        challengeToken: challenge.challengeToken,
        method: challenge.preferredMethod,
        code: code,
      );

      state = AuthState(
        status: AuthStatus.authenticated,
        user: session.user,
        organization: session.organization,
      );
    } on AppFailure catch (failure) {
      // Stay on the 2FA screen: the challenge token is still valid and the
      // user most likely mistyped, or the code rolled over while they typed.
      state = state.copyWith(isBusy: false, failure: failure);
    }
  }

  Future<void> requestOtp(String mobile) async {
    state = state.copyWith(isBusy: true, clearFailure: true);

    try {
      await _repository.sendOtp(mobile: mobile);
      state = state.copyWith(isBusy: false);
    } on AppFailure catch (failure) {
      state = state.copyWith(isBusy: false, failure: failure);
    }
  }

  Future<void> verifyOtp({
    required String mobile,
    required String code,
  }) async {
    state = state.copyWith(isBusy: true, clearFailure: true);

    try {
      final session = await _repository.verifyOtp(mobile: mobile, code: code);
      state = AuthState(
        status: AuthStatus.authenticated,
        user: session.user,
        organization: session.organization,
      );
    } on AppFailure catch (failure) {
      state = state.copyWith(isBusy: false, failure: failure);
    }
  }

  Future<void> logout() async {
    state = state.copyWith(isBusy: true);
    await _repository.logout();
    state = const AuthState.signedOut();
  }

  /// Drops the 2FA challenge and returns to the login form.
  void cancelSecondFactor() {
    state = const AuthState.signedOut();
  }

  void clearFailure() {
    state = state.copyWith(clearFailure: true);
  }

  @override
  void dispose() {
    unawaited(_forcedLogouts?.cancel());
    super.dispose();
  }
}

final authControllerProvider =
    StateNotifierProvider<AuthController, AuthState>(
  (ref) => AuthController(
    repository: ref.watch(authRepositoryProvider),
    sessionEvents: ref.watch(sessionEventsProvider),
  ),
);

/// The organisation id, needed for the private WebSocket channel names.
final currentOrganizationIdProvider = Provider<int?>(
  (ref) => ref.watch(authControllerProvider).organization?.id,
);
