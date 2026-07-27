import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:dio/io.dart';

/// SHA-256 certificate pinning.
///
/// ## How to obtain a fingerprint
///
/// ```sh
/// openssl s_client -connect api.goldb2b.ir:443 -servername api.goldb2b.ir \
///   < /dev/null 2>/dev/null \
///   | openssl x509 -outform DER \
///   | openssl dgst -sha256 -hex
/// ```
///
/// The output goes into `AppConfig.prod().certificateFingerprints` as
/// lower-case hex with no separators.
///
/// ## Operational warning
///
/// Pin at least TWO fingerprints: the certificate currently in use and the
/// backup certificate that has already been issued for the next rotation.
/// Pinning a single leaf certificate means that the day it is rotated, every
/// installed copy of the app stops working and the only fix is an app-store
/// release. Rotate the pin set in a release BEFORE rotating the certificate on
/// the server.
///
/// ## What this deliberately does not do
///
/// It does not pin the intermediate or root, and it does not fall back to
/// system trust when the pin set is non-empty. If the presented leaf is not in
/// the set, the connection fails and the user sees `PinningFailure` — an
/// unexplained inability to connect is a far better outcome than a silently
/// intercepted trading session.
final class CertificatePinning {
  const CertificatePinning._();

  /// Build an adapter that accepts only the given [fingerprints].
  ///
  /// An empty [fingerprints] list returns the default adapter with pinning
  /// DISABLED, which is the correct configuration for dev and staging and a
  /// release blocker for production. `ApiClient` only calls this when the list
  /// is non-empty.
  static HttpClientAdapter adapter(List<String> fingerprints) {
    final accepted = fingerprints
        .map((value) => value.toLowerCase().replaceAll(':', '').trim())
        .where((value) => value.isNotEmpty)
        .toSet();

    if (accepted.isEmpty) {
      return IOHttpClientAdapter();
    }

    return IOHttpClientAdapter(
      createHttpClient: () {
        final client = HttpClient(context: SecurityContext(withTrustedRoots: true));

        // Chain validation still applies; this callback exists only so that a
        // certificate rejected by the platform is never given a second chance.
        client.badCertificateCallback = (_, __, ___) => false;

        return client;
      },
      validateCertificate: (certificate, host, port) {
        if (certificate == null) {
          return false;
        }

        return accepted.contains(fingerprintOf(certificate));
      },
    );
  }

  /// Lower-case hex SHA-256 of the DER encoding of [certificate].
  static String fingerprintOf(X509Certificate certificate) =>
      sha256.convert(certificate.der).toString();
}
