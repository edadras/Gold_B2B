package ir.goldb2b.mobile

import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * Host activity.
 *
 * The only native code this app owns is the `secure_screen` channel, which
 * toggles FLAG_SECURE per screen.
 *
 * Why per screen rather than once in onCreate: FLAG_SECURE also blanks the
 * window in the recent-apps thumbnail, which is exactly what we want on a
 * balance or a settlement, and exactly what we do NOT want on the QR
 * verification screen — that one is meant to be shown to a counterparty
 * standing next to you, and a black rectangle in the task switcher is a
 * support call. `SecureScreen` in Dart reference-counts the acquire/release
 * pairs so nested secure routes behave.
 */
class MainActivity : FlutterActivity() {

    private companion object {
        const val CHANNEL = "ir.goldb2b.mobile/secure_screen"
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(
            flutterEngine.dartExecutor.binaryMessenger,
            CHANNEL,
        ).setMethodCallHandler { call, result ->
            when (call.method) {
                "enable" -> {
                    window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
                    result.success(null)
                }

                "disable" -> {
                    window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                    result.success(null)
                }

                else -> result.notImplemented()
            }
        }
    }
}
