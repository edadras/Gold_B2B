import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/formatting/money_formatter.dart';
import 'package:gold_b2b/core/formatting/weight_formatter.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/market/application/market_providers.dart';
import 'package:gold_b2b/features/market/data/market_repository.dart';
import 'package:gold_b2b/features/market/data/models/quote.dart';
import 'package:gold_b2b/features/market/presentation/widgets/depth_ladder.dart';
import 'package:gold_b2b/features/market/presentation/widgets/quote_header.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';

/// Price, depth, tape and chart for one instrument
/// (docs/07-mobile-flutter/02-screens.md §2.4).
class InstrumentDetailScreen extends ConsumerWidget {
  const InstrumentDetailScreen({required this.instrumentCode, super.key});

  final String instrumentCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final quote = ref.watch(quoteProvider(instrumentCode));
    final session = ref.watch(marketSessionProvider(instrumentCode)).valueOrNull;

    return SecureScreen(
      child: DefaultTabController(
        length: 3,
        child: Scaffold(
          appBar: AppBar(
            title: Text(instrumentCode),
            bottom: const TabBar(
              tabs: <Widget>[
                Tab(text: 'عمق'),
                Tab(text: 'معاملات'),
                Tab(text: 'نمودار'),
              ],
            ),
          ),
          body: Column(
            children: <Widget>[
              const ConnectivityBanner(),
              AsyncValueView<Quote>(
                value: quote,
                loading: const Padding(
                  padding: EdgeInsets.all(16),
                  child: SkeletonBox(width: 200, height: 40),
                ),
                onRetry: () =>
                    ref.read(quoteProvider(instrumentCode).notifier).refresh(),
                data: (value) => QuoteHeader(quote: value, session: session),
              ),
              Expanded(
                child: TabBarView(
                  children: <Widget>[
                    _DepthTab(instrumentCode: instrumentCode),
                    _TapeTab(instrumentCode: instrumentCode),
                    _ChartTab(instrumentCode: instrumentCode),
                  ],
                ),
              ),
            ],
          ),
          bottomNavigationBar: SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: FilledButton(
                      style: FilledButton.styleFrom(
                        backgroundColor: AppColors.buy,
                      ),
                      onPressed: () =>
                          context.push('/market/$instrumentCode/order/BUY'),
                      child: const Text('خرید'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: FilledButton(
                      style: FilledButton.styleFrom(
                        backgroundColor: AppColors.sell,
                      ),
                      onPressed: () =>
                          context.push('/market/$instrumentCode/order/SELL'),
                      child: const Text('فروش'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _DepthTab extends ConsumerWidget {
  const _DepthTab({required this.instrumentCode});

  final String instrumentCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) => AsyncValueView<MarketDepth>(
        value: ref.watch(depthProvider(instrumentCode)),
        loading: const ListSkeleton(rows: 8, rowHeight: 34),
        onRetry: () =>
            ref.read(depthProvider(instrumentCode).notifier).refresh(),
        data: (depth) => SingleChildScrollView(
          child: DepthLadder(
            depth: depth,
            // Tapping a level takes the user into the order form with that
            // price pre-filled (§2.4).
            onSelectPrice: (price) => context.push(
              '/market/$instrumentCode/order/BUY?price=${price.rial}',
            ),
          ),
        ),
      );
}

class _TapeTab extends ConsumerWidget {
  const _TapeTab({required this.instrumentCode});

  final String instrumentCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) =>
      AsyncValueView<List<PublicTrade>>(
        value: ref.watch(tapeProvider(instrumentCode)),
        loading: const ListSkeleton(rows: 8, rowHeight: 40),
        onRetry: () => ref.invalidate(tapeProvider(instrumentCode)),
        data: (trades) {
          if (trades.isEmpty) {
            return const EmptyState(
              title: 'معامله‌ای ثبت نشده است',
              icon: Icons.receipt_long_outlined,
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: trades.length,
            separatorBuilder: (_, __) => const ThinDivider(vertical: 4),
            itemBuilder: (context, index) {
              final trade = trades[index];
              final color =
                  trade.takerBought ? AppColors.buy : AppColors.sell;

              return Row(
                children: <Widget>[
                  Icon(
                    trade.takerBought
                        ? Icons.arrow_upward
                        : Icons.arrow_downward,
                    size: 14,
                    color: color,
                  ),
                  const SizedBox(width: 8),
                  LtrNumber(
                    MoneyFormatter.price(trade.price),
                    style: AppTypography.numeric.copyWith(color: color),
                  ),
                  const Spacer(),
                  LtrNumber(
                    WeightFormatter.compactFine(trade.quantity),
                    style: AppTypography.numericSmall,
                  ),
                  Text(' گرم', style: AppTypography.caption),
                  const SizedBox(width: 12),
                  Text(
                    JalaliFormatter.time(trade.executedAt),
                    style: AppTypography.caption,
                  ),
                ],
              );
            },
          );
        },
      );
}

class _ChartTab extends ConsumerWidget {
  const _ChartTab({required this.instrumentCode});

  final String instrumentCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) =>
      AsyncValueView<List<Candle>>(
        value: ref.watch(candlesProvider(instrumentCode)),
        onRetry: () => ref.invalidate(candlesProvider(instrumentCode)),
        data: (candles) {
          if (candles.length < 2) {
            return const EmptyState(
              title: 'داده کافی برای نمودار نیست',
              icon: Icons.show_chart,
            );
          }

          // The ONLY place prices become doubles. fl_chart works in logical
          // pixels, so the conversion happens here, at the presentation
          // boundary, and the result is never fed back into anything.
          final spots = <FlSpot>[
            for (var i = 0; i < candles.length; i++)
              FlSpot(i.toDouble(), candles[i].close.rial.toDouble()),
          ];

          return Padding(
            padding: const EdgeInsets.all(16),
            child: LineChart(
              LineChartData(
                gridData: const FlGridData(show: false),
                titlesData: const FlTitlesData(show: false),
                borderData: FlBorderData(show: false),
                lineBarsData: <LineChartBarData>[
                  LineChartBarData(
                    spots: spots,
                    isCurved: false,
                    color: AppColors.gold,
                    barWidth: 2,
                    dotData: const FlDotData(show: false),
                    belowBarData: BarAreaData(
                      show: true,
                      color: AppColors.gold.withOpacity(0.12),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      );
}
