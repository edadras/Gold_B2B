import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/purity.dart';
import 'package:gold_b2b/shared/models/rial.dart';
import 'package:gold_b2b/shared/models/weight.dart';

/// Typed, forgiving accessors for decoded JSON maps.
///
/// Every DTO in this app parses by hand (there is no json_serializable), so
/// these extensions are the shared safety net. Two rules they enforce:
///
///  1. A field that is missing or null yields the documented default rather
///     than throwing, because docs/05-api/01-conventions.md §1.11 explicitly
///     permits the server to ADD fields and enum values without a version
///     bump — a client that throws on an unknown shape breaks on deploy day.
///  2. A field that is present but the wrong type DOES throw, because that is
///     a contract violation and silently coercing it is how a price ends up
///     being displayed as zero.
extension JsonMap on Map<String, dynamic> {
  /// Required int. Accepts `int` and `num` (a JSON `2.0` decodes to double).
  int requireInt(String key) {
    final value = this[key];
    if (value is int) {
      return value;
    }
    if (value is num) {
      return value.toInt();
    }
    if (value is String) {
      final parsed = int.tryParse(value);
      if (parsed != null) {
        return parsed;
      }
    }

    throw FormatException('Expected int at "$key", got ${value.runtimeType}');
  }

  int intOr(String key, int fallback) {
    final value = this[key];
    if (value == null) {
      return fallback;
    }

    return requireInt(key);
  }

  int? intOrNull(String key) {
    final value = this[key];

    return value == null ? null : requireInt(key);
  }

  String requireString(String key) {
    final value = this[key];
    if (value is String) {
      return value;
    }

    throw FormatException(
      'Expected String at "$key", got ${value.runtimeType}',
    );
  }

  String stringOr(String key, String fallback) {
    final value = this[key];

    return value is String ? value : fallback;
  }

  String? stringOrNull(String key) {
    final value = this[key];

    return value is String ? value : null;
  }

  bool boolOr(String key, {required bool fallback}) {
    final value = this[key];
    if (value is bool) {
      return value;
    }
    if (value is num) {
      return value != 0;
    }

    return fallback;
  }

  /// ISO-8601 UTC timestamp. All API times are UTC per §1.1; the returned
  /// [DateTime] is always in UTC and is converted to local exactly once, in
  /// the Jalali formatter.
  DateTime? dateTimeOrNull(String key) {
    final value = this[key];
    if (value is! String || value.isEmpty) {
      return null;
    }

    return DateTime.tryParse(value)?.toUtc();
  }

  DateTime requireDateTime(String key) {
    final value = dateTimeOrNull(key);
    if (value == null) {
      throw FormatException('Expected ISO-8601 timestamp at "$key"');
    }

    return value;
  }

  Map<String, dynamic>? mapOrNull(String key) {
    final value = this[key];

    return value is Map<String, dynamic> ? value : null;
  }

  /// A list of objects, each parsed by [parse]. Missing or null yields `[]`.
  List<T> listOf<T>(String key, T Function(Map<String, dynamic> json) parse) {
    final value = this[key];
    if (value is! List) {
      return const [];
    }

    return value
        .whereType<Map<String, dynamic>>()
        .map(parse)
        .toList(growable: false);
  }

  List<String> stringList(String key) {
    final value = this[key];
    if (value is! List) {
      return const [];
    }

    return value.whereType<String>().toList(growable: false);
  }

  // --- value object shortcuts ---------------------------------------------
  //
  // These exist so that no DTO ever writes `FineWeight.fromMilligrams(json['x']
  // as int)` inline. Weight and money cross the JSON boundary in exactly one
  // place per type, which is the only way to be sure none of them slipped
  // through as a double.

  FineWeight fineWeight(String key) =>
      FineWeight.fromMilligrams(requireInt(key));

  FineWeight fineWeightOr(String key, FineWeight fallback) {
    final value = intOrNull(key);

    return value == null ? fallback : FineWeight.fromMilligrams(value);
  }

  FineWeight? fineWeightOrNull(String key) {
    final value = intOrNull(key);

    return value == null ? null : FineWeight.fromMilligrams(value);
  }

  Weight grossWeight(String key) => Weight.fromMilligrams(requireInt(key));

  Rial rial(String key) => Rial.fromRial(requireInt(key));

  Rial rialOr(String key, Rial fallback) {
    final value = intOrNull(key);

    return value == null ? fallback : Rial.fromRial(value);
  }

  Rial? rialOrNull(String key) {
    final value = intOrNull(key);

    return value == null ? null : Rial.fromRial(value);
  }

  PricePerFineGram price(String key) =>
      PricePerFineGram.fromRial(requireInt(key));

  PricePerFineGram? priceOrNull(String key) {
    final value = intOrNull(key);

    return value == null ? null : PricePerFineGram.fromRial(value);
  }

  /// The API calls this field `purity_x10` even though the scale is 10,000.
  Purity purity(String key) => Purity.fromScaled(requireInt(key));

  Purity? purityOrNull(String key) {
    final value = intOrNull(key);

    return value == null ? null : Purity.fromScaled(value);
  }
}
