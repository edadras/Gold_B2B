import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// QR scanning (docs/07-mobile-flutter/02-screens.md §2.8).
///
/// The QR on a bar or a certificate encodes a verification token. It may be
/// the bare token or a full URL such as
/// `https://goldb2b.ir/verify/<token>`; [_extractToken] accepts both, because
/// the same code has to be scannable by a member's app and by a plain phone
/// camera that will just open the browser.
class ScanScreen extends ConsumerStatefulWidget {
  const ScanScreen({super.key});

  @override
  ConsumerState<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends ConsumerState<ScanScreen> {
  final MobileScannerController _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const <BarcodeFormat>[BarcodeFormat.qrCode],
  );

  bool _handled = false;

  @override
  void dispose() {
    // ignore: discarded_futures
    _controller.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_handled) {
      return;
    }

    for (final barcode in capture.barcodes) {
      final raw = barcode.rawValue;
      if (raw == null || raw.isEmpty) {
        continue;
      }

      final token = _extractToken(raw);
      if (token == null) {
        continue;
      }

      _handled = true;
      await HapticFeedback.mediumImpact();

      if (!mounted) {
        return;
      }

      context.pushReplacement('/lots/verify/$token');

      return;
    }
  }

  /// Accepts a bare token, a `/verify/<token>` path, or a full URL.
  static String? _extractToken(String raw) {
    final trimmed = raw.trim();

    if (trimmed.contains('/verify/')) {
      final token = trimmed.split('/verify/').last.split('?').first;

      return token.isEmpty ? null : token;
    }

    // A bare token: conservative charset check so a random QR on a coffee cup
    // does not turn into an API call.
    if (RegExp(r'^[A-Za-z0-9_-]{8,128}$').hasMatch(trimmed)) {
      return trimmed;
    }

    return null;
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('اسکن'),
          actions: <Widget>[
            IconButton(
              tooltip: 'چراغ',
              onPressed: () => _controller.toggleTorch(),
              icon: const Icon(Icons.flashlight_on_outlined),
            ),
          ],
        ),
        body: Stack(
          fit: StackFit.expand,
          children: <Widget>[
            MobileScanner(controller: _controller, onDetect: _onDetect),
            // Reticle
            Center(
              child: Container(
                width: 220,
                height: 220,
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.white, width: 2),
                  borderRadius: BorderRadius.circular(16),
                ),
              ),
            ),
            Align(
              alignment: Alignment.bottomCenter,
              child: SafeArea(
                child: Container(
                  margin: const EdgeInsets.all(24),
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 12,
                  ),
                  decoration: BoxDecoration(
                    color: Colors.black54,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    'QR روی شمش یا گواهی را اسکن کنید',
                    style: AppTypography.body.copyWith(color: Colors.white),
                    textAlign: TextAlign.center,
                  ),
                ),
              ),
            ),
          ],
        ),
      );
}
