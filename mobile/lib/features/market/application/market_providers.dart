import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/core/realtime/websocket_service.dart';
import 'package:gold_b2b/features/market/data/market_repository.dart';
import 'package:gold_b2b/features/market/data/models/instrument.dart';
import 'package:gold_b2b/features/market/data/models/quote.dart';

final instrumentsProvider = FutureProvider<List<Instrument>>(
  (ref) => ref.watch(marketRepositoryProvider).fetchInstruments(),
);

final instrumentProvider =
    FutureProvider.family<Instrument, String>(
  (ref, code) => ref.watch(marketRepositoryProvider).fetchInstrument(code),
);

final marketSessionProvider =
    FutureProvider.family<MarketSessionState, String>(
  (ref, code) => ref.watch(marketRepositoryProvider).fetchSession(code),
);

final referencePriceProvider = FutureProvider<ReferencePrice>(
  (ref) => ref.watch(marketRepositoryProvider).fetchReferencePrice(),
);

final tapeProvider = FutureProvider.family<List<PublicTrade>, String>(
  (ref, code) => ref.watch(marketRepositoryProvider).fetchTape(code),
);

final candlesProvider = FutureProvider.family<List<Candle>, String>(
  (ref, code) => ref.watch(marketRepositoryProvider).fetchCandles(code),
);

/// Live top-of-book for one instrument.
///
/// Seeded from REST, then patched by `quote.updated`. The REST read is what
/// makes the first paint correct; the socket is what keeps it correct. On
/// reconnect the whole thing is re-read (see `realtime_sync.dart`).
class QuoteController extends StateNotifier<AsyncValue<Quote>> {
  QuoteController({
    required MarketRepository repository,
    required WebSocketService realtime,
    required this.instrumentCode,
  })  : _repository = repository,
        _realtime = realtime,
        super(const AsyncValue<Quote>.loading()) {
    unawaited(_start());
  }

  final MarketRepository _repository;
  final WebSocketService _realtime;
  final String instrumentCode;

  StreamSubscription<RealtimeEvent>? _subscription;

  Future<void> _start() async {
    await _realtime.subscribe(Channels.market(instrumentCode));

    _subscription = _realtime
        .on(RealtimeEvents.quoteUpdated)
        .where((event) => event.data['instrument'] == instrumentCode)
        .listen(_apply);

    await refresh();
  }

  Future<void> refresh() async {
    final next = await AsyncValue.guard(
      () => _repository.fetchQuote(instrumentCode),
    );

    if (mounted) {
      state = next;
    }
  }

  void _apply(RealtimeEvent event) {
    if (!mounted) {
      return;
    }

    state = AsyncValue<Quote>.data(Quote.fromJson(event.data));
  }

  @override
  void dispose() {
    unawaited(_subscription?.cancel());
    // The channel subscription itself is intentionally NOT torn down here:
    // the user is very likely to come straight back to this instrument, and
    // `WebSocketService.setMarketChannels` prunes the set when they move on
    // to a different one.
    super.dispose();
  }
}

final quoteProvider =
    StateNotifierProvider.family<QuoteController, AsyncValue<Quote>, String>(
  (ref, code) => QuoteController(
    repository: ref.watch(marketRepositoryProvider),
    realtime: ref.watch(webSocketServiceProvider),
    instrumentCode: code,
  ),
);

/// Live depth ladder.
///
/// `depth.updated` sends the whole visible book, throttled to 10/s (§3.5), so
/// each event replaces the state rather than being merged — which also means
/// a dropped frame self-heals on the next one.
class DepthController extends StateNotifier<AsyncValue<MarketDepth>> {
  DepthController({
    required MarketRepository repository,
    required WebSocketService realtime,
    required this.instrumentCode,
  })  : _repository = repository,
        _realtime = realtime,
        super(const AsyncValue<MarketDepth>.loading()) {
    unawaited(_start());
  }

  final MarketRepository _repository;
  final WebSocketService _realtime;
  final String instrumentCode;

  StreamSubscription<RealtimeEvent>? _subscription;

  Future<void> _start() async {
    await _realtime.subscribe(Channels.market(instrumentCode));

    _subscription = _realtime
        .on(RealtimeEvents.depthUpdated)
        .where((event) => event.data['instrument'] == instrumentCode)
        .listen((event) {
      if (mounted) {
        state = AsyncValue<MarketDepth>.data(
          MarketDepth.fromRealtime(event.data),
        );
      }
    });

    await refresh();
  }

  Future<void> refresh() async {
    final next = await AsyncValue.guard(
      () => _repository.fetchDepth(instrumentCode),
    );

    if (mounted) {
      state = next;
    }
  }

  @override
  void dispose() {
    unawaited(_subscription?.cancel());
    super.dispose();
  }
}

final depthProvider = StateNotifierProvider.family<DepthController,
    AsyncValue<MarketDepth>, String>(
  (ref, code) => DepthController(
    repository: ref.watch(marketRepositoryProvider),
    realtime: ref.watch(webSocketServiceProvider),
    instrumentCode: code,
  ),
);

/// The instrument the dashboard's market card and the order form default to.
///
/// Held in a plain [StateProvider] because it is a pure UI selection with no
/// side effects; changing it re-derives every family provider above.
final selectedInstrumentProvider = StateProvider<String>((ref) {
  final instruments = ref.watch(instrumentsProvider).valueOrNull;
  if (instruments == null || instruments.isEmpty) {
    return 'GOLD-995-T0';
  }

  return instruments.firstWhere(
    (instrument) => instrument.isActive,
    orElse: () => instruments.first,
  ).code;
});
