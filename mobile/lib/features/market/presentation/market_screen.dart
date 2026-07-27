import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:gold_b2b/core/security/secure_screen.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/market/application/market_providers.dart';
import 'package:gold_b2b/features/market/data/models/instrument.dart';
import 'package:gold_b2b/features/market/data/models/quote.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';

/// Instrument list — the entry point of the بازار tab.
class MarketScreen extends ConsumerWidget {
  const MarketScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final instruments = ref.watch(instrumentsProvider);

    return SecureScreen(
      child: Scaffold(
        appBar: AppBar(title: const Text('بازار')),
        body: Column(
          children: <Widget>[
            const ConnectivityBanner(),
            Expanded(
              child: RefreshIndicator(
                onRefresh: () async => ref.invalidate(instrumentsProvider),
                child: AsyncValueView<List<Instrument>>(
                  value: instruments,
                  loading: const ListSkeleton(),
                  onRetry: () => ref.invalidate(instrumentsProvider),
                  data: (items) {
                    if (items.isEmpty) {
                      return const EmptyState(
                        title: 'ابزاری در دسترس نیست',
                        icon: Icons.show_chart,
                      );
                    }

                    return ListView.separated(
                      padding: const EdgeInsets.all(16),
                      physics: const AlwaysScrollableScrollPhysics(),
                      itemCount: items.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 12),
                      itemBuilder: (context, index) =>
                          _InstrumentTile(instrument: items[index]),
                    );
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _InstrumentTile extends ConsumerWidget {
  const _InstrumentTile({required this.instrument});

  final Instrument instrument;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final quote = ref.watch(quoteProvider(instrument.code)).valueOrNull;

    return SectionCard(
      onTap: () => context.push('/market/${instrument.code}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Text(instrument.code, style: AppTypography.titleMedium),
              const SizedBox(width: 8),
              if (!instrument.isActive)
                Text('(غیرفعال)', style: AppTypography.caption),
              const Spacer(),
              if (quote != null) ChangeDisplay(quote.dayChangeBps),
            ],
          ),
          const SizedBox(height: 10),
          if (quote == null)
            const SkeletonBox(width: 200, height: 20)
          else
            _QuoteRow(quote: quote),
        ],
      ),
    );
  }
}

class _QuoteRow extends StatelessWidget {
  const _QuoteRow({required this.quote});

  final Quote quote;

  @override
  Widget build(BuildContext context) => Row(
        children: <Widget>[
          Expanded(
            child: _Cell(
              label: 'خرید',
              child: quote.bestBid == null
                  ? const Text('—')
                  : PriceDisplay(quote.bestBid!, color: AppColors.buy),
            ),
          ),
          Expanded(
            child: _Cell(
              label: 'فروش',
              child: quote.bestAsk == null
                  ? const Text('—')
                  : PriceDisplay(quote.bestAsk!, color: AppColors.sell),
            ),
          ),
          Expanded(
            child: _Cell(
              label: 'آخرین',
              child: quote.lastPrice == null
                  ? const Text('—')
                  : PriceDisplay(quote.lastPrice!),
            ),
          ),
        ],
      );
}

class _Cell extends StatelessWidget {
  const _Cell({required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(label, style: AppTypography.caption),
          const SizedBox(height: 2),
          child,
        ],
      );
}
