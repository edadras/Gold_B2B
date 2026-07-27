import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/network/api_client.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/features/market/data/models/instrument.dart';
import 'package:gold_b2b/features/market/data/models/quote.dart';
import 'package:gold_b2b/shared/extensions/json_extensions.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';

/// Market data (docs/05-api/02-endpoints.md §2.4).
class MarketRepository {
  MarketRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<Instrument>> fetchInstruments() async {
    final page = await _api.getList('/instruments', Instrument.fromJson);

    return page.items;
  }

  Future<Instrument> fetchInstrument(String code) =>
      _api.getOne('/instruments/$code', Instrument.fromJson);

  Future<List<Quote>> fetchAllQuotes() async {
    final page = await _api.getList('/market/quotes', Quote.fromJson);

    return page.items;
  }

  Future<Quote> fetchQuote(String code) =>
      _api.getOne('/market/quotes/$code', Quote.fromJson);

  Future<MarketDepth> fetchDepth(
    String code, {
    int levels = Ui.depthLevels,
  }) =>
      _api.getOne(
        '/market/depth/$code',
        MarketDepth.fromJson,
        query: <String, dynamic>{'levels': levels},
      );

  Future<List<PublicTrade>> fetchTape(String code, {int limit = 50}) async {
    final page = await _api.getList(
      '/market/trades/$code',
      PublicTrade.fromJson,
      query: <String, dynamic>{'limit': limit},
    );

    return page.items;
  }

  Future<MarketSessionState> fetchSession(String code) =>
      _api.getOne('/market/sessions/$code', MarketSessionState.fromJson);

  /// `GET /market/reference-price` — intrinsic value and the premium over it.
  Future<ReferencePrice> fetchReferencePrice() =>
      _api.getOne('/market/reference-price', ReferencePrice.fromJson);

  /// `GET /market/candles/{code}` for the price chart.
  Future<List<Candle>> fetchCandles(
    String code, {
    String interval = '1h',
    int limit = 100,
  }) async {
    final page = await _api.getList(
      '/market/candles/$code',
      Candle.fromJson,
      query: <String, dynamic>{'interval': interval, 'limit': limit},
    );

    return page.items;
  }
}

/// Reference and intrinsic price (F6, F7).
final class ReferencePrice {
  const ReferencePrice({
    required this.referencePrice,
    this.intrinsicPrice,
    this.premiumBps,
    this.ounceUsdMicro,
    this.usdIrr,
    this.asOf,
  });

  /// What the platform treats as "the" price — the circuit-breaker baseline
  /// and the fat-finger reference (F24).
  final PricePerFineGram referencePrice;

  /// F6 — derived from the ounce price and the USD rate.
  final PricePerFineGram? intrinsicPrice;

  /// F7 — the "حباب", market over intrinsic, in basis points. Null when
  /// intrinsic is zero or unknown, per §1.11(6).
  final int? premiumBps;

  final int? ounceUsdMicro;
  final int? usdIrr;
  final DateTime? asOf;

  factory ReferencePrice.fromJson(Map<String, dynamic> json) => ReferencePrice(
        referencePrice:
            json.priceOrNull('reference_price_rial') ?? PricePerFineGram.zero,
        intrinsicPrice: json.priceOrNull('intrinsic_price_rial'),
        premiumBps: json.intOrNull('premium_bps'),
        ounceUsdMicro: json.intOrNull('ounce_usd_micro'),
        usdIrr: json.intOrNull('usd_irr'),
        asOf: json.dateTimeOrNull('as_of'),
      );
}

/// One OHLC bar.
///
/// Prices stay integer all the way to the chart; the conversion to the
/// doubles fl_chart needs happens in the chart widget, at the presentation
/// boundary and nowhere else.
final class Candle {
  const Candle({
    required this.openedAt,
    required this.open,
    required this.high,
    required this.low,
    required this.close,
    required this.volumeMg,
  });

  final DateTime openedAt;
  final PricePerFineGram open;
  final PricePerFineGram high;
  final PricePerFineGram low;
  final PricePerFineGram close;
  final int volumeMg;

  factory Candle.fromJson(Map<String, dynamic> json) => Candle(
        openedAt:
            json.dateTimeOrNull('opened_at') ?? DateTime.now().toUtc(),
        open: json.price('open_rial'),
        high: json.price('high_rial'),
        low: json.price('low_rial'),
        close: json.price('close_rial'),
        volumeMg: json.intOr('volume_mg', 0),
      );
}

final marketRepositoryProvider = Provider<MarketRepository>(
  (ref) => MarketRepository(api: ref.watch(apiClientProvider)),
);
