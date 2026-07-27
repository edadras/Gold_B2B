import 'package:gold_b2b/shared/extensions/json_extensions.dart';

/// The signed-in user.
final class UserProfile {
  const UserProfile({
    required this.id,
    required this.fullName,
    required this.roles,
    required this.permissions,
  });

  final int id;
  final String fullName;
  final List<String> roles;

  /// Fine-grained permissions such as `order.create`, `order.cancel.own`,
  /// `ledger.view`. The UI hides what the user cannot do rather than letting
  /// them find out from a 403 after filling in a form.
  final List<String> permissions;

  bool can(String permission) => permissions.contains(permission);

  bool hasRole(String role) => roles.contains(role);

  factory UserProfile.fromJson(Map<String, dynamic> json) => UserProfile(
        id: json.requireInt('id'),
        fullName: json.stringOr('full_name', ''),
        roles: json.stringList('roles'),
        permissions: json.stringList('permissions'),
      );

  UserProfile copyWith({
    int? id,
    String? fullName,
    List<String>? roles,
    List<String>? permissions,
  }) =>
      UserProfile(
        id: id ?? this.id,
        fullName: fullName ?? this.fullName,
        roles: roles ?? this.roles,
        permissions: permissions ?? this.permissions,
      );
}

/// The organisation the user acts on behalf of.
///
/// [status] drives whether trading is possible at all — see
/// docs/11-appendix/02-state-machines.md §2.2. A SUSPENDED organisation can
/// still sign in and look at its ledger; it cannot place an order, and the UI
/// says so up front rather than letting the request fail.
final class OrganizationSummary {
  const OrganizationSummary({
    required this.id,
    required this.displayName,
    required this.status,
    required this.verificationTier,
  });

  final int id;
  final String displayName;

  /// `PENDING`, `ACTIVE`, `RESTRICTED`, `SUSPENDED`, `CLOSING`, …
  final String status;

  /// `BRONZE` | `SILVER` | `GOLD` | `PLATINUM`.
  final String verificationTier;

  bool get canTrade => status == 'ACTIVE';

  bool get isRestricted => status == 'RESTRICTED' || status == 'SUSPENDED';

  String get statusLabel => switch (status) {
        'ACTIVE' => 'فعال',
        'PENDING' => 'در انتظار تأیید',
        'UNDER_REVIEW' => 'در حال بررسی',
        'INFO_REQUIRED' => 'نیازمند تکمیل مدارک',
        'VERIFIED' => 'تأییدشده',
        'RESTRICTED' => 'محدود',
        'SUSPENDED' => 'معلق',
        'CLOSING' => 'در حال خاتمه',
        'CLOSED' => 'بسته',
        'REJECTED' => 'ردشده',
        // §1.11 lets the server add statuses without a version bump; showing
        // the raw code is ugly but honest, and far better than pretending the
        // account is fine.
        _ => status,
      };

  factory OrganizationSummary.fromJson(Map<String, dynamic> json) =>
      OrganizationSummary(
        id: json.requireInt('id'),
        displayName: json.stringOr('display_name', ''),
        status: json.stringOr('status', 'PENDING'),
        verificationTier: json.stringOr('verification_tier', 'BRONZE'),
      );
}

/// A successful authentication.
final class AuthSession {
  const AuthSession({
    required this.accessToken,
    required this.refreshToken,
    required this.expiresIn,
    required this.user,
    required this.organization,
  });

  final String accessToken;
  final String refreshToken;

  /// Seconds until the access token expires (`900` in the documented example).
  final int expiresIn;

  final UserProfile user;
  final OrganizationSummary organization;

  factory AuthSession.fromJson(Map<String, dynamic> json) => AuthSession(
        accessToken: json.requireString('access_token'),
        refreshToken: json.stringOr('refresh_token', ''),
        expiresIn: json.intOr('expires_in', 900),
        user: UserProfile.fromJson(json.mapOrNull('user') ?? const <String, dynamic>{}),
        organization: OrganizationSummary.fromJson(
          json.mapOrNull('organization') ?? const <String, dynamic>{},
        ),
      );
}

/// A login that got as far as needing a second factor.
final class LoginChallenge {
  const LoginChallenge({
    required this.challengeToken,
    required this.methods,
  });

  final String challengeToken;

  /// `["TOTP", "SMS"]`.
  final List<String> methods;

  bool get supportsTotp => methods.contains('TOTP');

  bool get supportsSms => methods.contains('SMS');

  String get preferredMethod => supportsTotp ? 'TOTP' : (methods.isEmpty ? 'TOTP' : methods.first);

  factory LoginChallenge.fromJson(Map<String, dynamic> json) => LoginChallenge(
        challengeToken: json.stringOr('challenge_token', ''),
        methods: json.stringList('methods'),
      );
}

/// `POST /auth/login` returns either a session or a challenge. Modelling that
/// as a two-case result rather than a nullable-everything object means the
/// controller has to handle both.
sealed class LoginOutcome {
  const LoginOutcome();

  /// The documented discriminator is `requires_2fa`.
  factory LoginOutcome.fromJson(Map<String, dynamic> json) {
    if (json.boolOr('requires_2fa', fallback: false)) {
      return LoginRequiresSecondFactor(LoginChallenge.fromJson(json));
    }

    return LoginSucceeded(AuthSession.fromJson(json));
  }
}

final class LoginSucceeded extends LoginOutcome {
  const LoginSucceeded(this.session);

  final AuthSession session;
}

final class LoginRequiresSecondFactor extends LoginOutcome {
  const LoginRequiresSecondFactor(this.challenge);

  final LoginChallenge challenge;
}
