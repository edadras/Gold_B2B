import 'dart:math';

/// UUID v4 generation, hand-rolled to avoid a dependency for forty lines of
/// code.
///
/// [Random.secure] is used rather than [Random] because an idempotency key
/// that an attacker can predict lets them collide with somebody else's
/// in-flight order and read back its result.
final class Uuid {
  Uuid({Random? random}) : _random = random ?? Random.secure();

  final Random _random;

  static final Uuid _shared = Uuid();

  /// A canonical lower-case RFC 4122 version 4 UUID.
  static String v4() => _shared.generateV4();

  String generateV4() {
    final bytes = List<int>.generate(16, (_) => _random.nextInt(256));

    // Version 4: the high nibble of byte 6 is 0100.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    // Variant 10xx: the two high bits of byte 8.
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    final hex = bytes
        .map((b) => b.toRadixString(16).padLeft(2, '0'))
        .join();

    return '${hex.substring(0, 8)}-'
        '${hex.substring(8, 12)}-'
        '${hex.substring(12, 16)}-'
        '${hex.substring(16, 20)}-'
        '${hex.substring(20, 32)}';
  }
}

/// A key that identifies one logical financial intent.
///
/// THE WHOLE POINT: the key is minted once, when the user's intent is first
/// turned into a request, and is then reused for every transport-level attempt
/// at delivering that intent — the automatic retry after a dropped connection,
/// the replay after a token refresh, and the manual "try again" the user taps
/// when the first attempt timed out.
///
/// Minting a fresh key on retry is the single worst bug this app could have:
/// a response lost in transit becomes a second order at a moved price. See
/// docs/05-api/01-conventions.md §1.10 for the server's side of the contract.
final class IdempotencyKey {
  IdempotencyKey._(this.value, this.mintedAt);

  final String value;
  final DateTime mintedAt;

  /// Mint a new key. Call this exactly once per user intent.
  factory IdempotencyKey.mint() =>
      IdempotencyKey._(Uuid.v4(), DateTime.now().toUtc());

  /// Rehydrate a key that was carried across a screen or a controller.
  factory IdempotencyKey.of(String value, {DateTime? mintedAt}) =>
      IdempotencyKey._(value, mintedAt ?? DateTime.now().toUtc());

  /// Server-side keys are valid for 24 hours (§1.10). After that a retry with
  /// the same key is no longer deduplicated and would create a second order,
  /// so the UI must refuse to reuse it and ask the user to start over.
  static const Duration validity = Duration(hours: 24);

  bool isExpiredAt(DateTime now) =>
      now.toUtc().difference(mintedAt) >= validity;

  @override
  String toString() => value;

  @override
  bool operator ==(Object other) =>
      other is IdempotencyKey && other.value == value;

  @override
  int get hashCode => value.hashCode;
}
