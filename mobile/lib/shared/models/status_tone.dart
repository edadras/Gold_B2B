/// Severity of a domain state, independent of Flutter.
///
/// Lives in `shared/models` rather than next to the badge widget so that the
/// data layer can map its own status strings onto a tone without importing
/// `package:flutter/material.dart`. Colours and icons for each tone are
/// attached in `shared/widgets/status_badge.dart`.
enum StatusTone { neutral, positive, warning, negative, info }
