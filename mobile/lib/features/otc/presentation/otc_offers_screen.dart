import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/otc/application/otc_controller.dart';
import 'package:gold_b2b/features/otc/data/otc_repository.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/widgets/async_value_view.dart';
import 'package:gold_b2b/shared/widgets/confirm_sheet.dart';
import 'package:gold_b2b/shared/widgets/connectivity_banner.dart';
import 'package:gold_b2b/shared/widgets/empty_state.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/skeletons.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';
import 'package:gold_b2b/shared/widgets/weight_display.dart';

/// Received / sent OTC offers (docs/07-mobile-flutter/02-screens.md §2.1).
class OtcOffersTab extends ConsumerWidget {
  const OtcOffersTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) => const DefaultTabController(
        length: 2,
        child: Column(
          children: <Widget>[
            TabBar(
              tabs: <Widget>[
                Tab(text: 'دریافتی'),
                Tab(text: 'ارسالی'),
              ],
            ),
            Expanded(
              child: TabBarView(
                children: <Widget>[
                  _OtcList(incoming: true),
                  _OtcList(incoming: false),
                ],
              ),
            ),
          ],
        ),
      );
}

class _OtcList extends ConsumerWidget {
  const _OtcList({required this.incoming});

  final bool incoming;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final provider =
        incoming ? incomingOtcOffersProvider : outgoingOtcOffersProvider;
    final offers = ref.watch(provider);
    final now = ref.watch(clockProvider).valueOrNull ?? DateTime.now();

    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(provider),
      child: AsyncValueView<Paginated<OtcOffer>>(
        value: offers,
        loading: const ListSkeleton(),
        onRetry: () => ref.invalidate(provider),
        data: (page) {
          if (page.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: <Widget>[
                const SizedBox(height: 80),
                EmptyState(
                  title: incoming
                      ? 'پیشنهاد دریافتی ندارید'
                      : 'پیشنهاد ارسالی ندارید',
                  icon: Icons.handshake_outlined,
                ),
              ],
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.all(16),
            physics: const AlwaysScrollableScrollPhysics(),
            itemCount: page.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, index) =>
                OtcOfferCard(offer: page.items[index], now: now),
          );
        },
      ),
    );
  }
}

class OtcOfferCard extends ConsumerWidget {
  const OtcOfferCard({required this.offer, required this.now, super.key});

  final OtcOffer offer;
  final DateTime now;

  @override
  Widget build(BuildContext context, WidgetRef ref) => SectionCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                SideBadge(offer.side, dense: true),
                const SizedBox(width: 8),
                if (offer.counterparty != null) ...<Widget>[
                  TierBadge(offer.counterparty!.verificationTier),
                  const SizedBox(width: 4),
                  Flexible(
                    child: Text(
                      offer.counterparty!.displayName,
                      style: AppTypography.titleMedium,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
                const Spacer(),
                StatusBadge(
                  label: OtcOfferStatus.label(offer.status),
                  tone: OtcOfferStatus.tone(offer.status),
                  dense: true,
                ),
              ],
            ),
            const SizedBox(height: 10),
            KeyValueRow(
              label: 'مقدار',
              value: WeightDisplay(offer.quantity, emphasis: WeightEmphasis.small),
            ),
            KeyValueRow(
              label: 'قیمت هر گرم',
              value: PriceDisplay(offer.price, emphasis: MoneyEmphasis.small),
            ),
            KeyValueRow(
              label: 'مبلغ کل',
              value: MoneyDisplay(
                offer.grossAmount,
                compact: true,
                emphasis: MoneyEmphasis.small,
              ),
            ),
            if (offer.note != null && offer.note!.isNotEmpty) ...<Widget>[
              const SizedBox(height: 6),
              Text(offer.note!, style: AppTypography.caption),
            ],
            if (offer.expiresAt != null) ...<Widget>[
              const SizedBox(height: 8),
              CountdownChip(deadline: offer.expiresAt!, now: now),
            ],
            if (offer.isActionable) ...<Widget>[
              const ThinDivider(vertical: 10),
              OfflineActionGuard(
                builder: (context, online) => Row(
                  children: <Widget>[
                    Expanded(
                      child: OutlinedButton(
                        onPressed: online
                            ? () => ref
                                .read(otcActionsProvider.notifier)
                                .reject(offer.id)
                            : null,
                        child: const Text('رد'),
                      ),
                    ),
                    if (offer.canCounter) ...<Widget>[
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton(
                          onPressed:
                              online ? () => _counter(context, ref) : null,
                          child: const Text('پیشنهاد متقابل'),
                        ),
                      ),
                    ],
                    const SizedBox(width: 8),
                    Expanded(
                      child: FilledButton(
                        onPressed: online ? () => _accept(context, ref) : null,
                        child: const Text('پذیرش'),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      );

  Future<void> _accept(BuildContext context, WidgetRef ref) async {
    final result = await confirmFinancialAction(
      context: context,
      ref: ref,
      title: 'پذیرش پیشنهاد ${offer.sideLabel}',
      amount: offer.grossAmount,
      confirmLabel: 'پذیرش',
      warning: 'با پذیرش، معامله قطعی می‌شود و تعهد تسویه ایجاد می‌گردد.',
      lines: <ConfirmationLine>[
        ConfirmationLine(
          label: 'طرف مقابل',
          value: Text(
            offer.counterparty?.displayName ?? '',
            style: AppTypography.body,
          ),
        ),
        ConfirmationLine(
          label: 'مقدار',
          value: WeightDisplay(offer.quantity),
        ),
        ConfirmationLine(
          label: 'قیمت هر گرم',
          value: PriceDisplay(offer.price, showUnit: true),
        ),
        moneyLine('مبلغ کل', offer.grossAmount,
            emphasis: true, dividerAbove: true),
      ],
    );

    if (!result.confirmed || !context.mounted) {
      return;
    }

    final ok = await ref.read(otcActionsProvider.notifier).accept(offer.id);

    if (!context.mounted) {
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(ok ? 'پیشنهاد پذیرفته شد.' : 'پذیرش انجام نشد.')),
    );
  }

  Future<void> _counter(BuildContext context, WidgetRef ref) async {
    final counter = await showModalBottomSheet<_CounterValues>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (sheetContext) => _CounterSheet(offer: offer),
    );

    if (counter == null || !context.mounted) {
      return;
    }

    final ok = await ref.read(otcActionsProvider.notifier).counter(
          offerId: offer.id,
          price: counter.price,
          quantity: counter.quantity,
          note: counter.note,
        );

    if (!context.mounted) {
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(ok ? 'پیشنهاد متقابل ارسال شد.' : 'ارسال انجام نشد.'),
      ),
    );
  }
}

class _CounterValues {
  const _CounterValues({
    required this.price,
    required this.quantity,
    this.note,
  });

  final PricePerFineGram price;
  final FineWeight quantity;
  final String? note;
}

class _CounterSheet extends StatefulWidget {
  const _CounterSheet({required this.offer});

  final OtcOffer offer;

  @override
  State<_CounterSheet> createState() => _CounterSheetState();
}

class _CounterSheetState extends State<_CounterSheet> {
  late final TextEditingController _price =
      TextEditingController(text: widget.offer.price.rial.toString());
  late final TextEditingController _quantity =
      TextEditingController(text: widget.offer.quantity.grams);
  final TextEditingController _note = TextEditingController();

  String? _error;

  @override
  void dispose() {
    _price.dispose();
    _quantity.dispose();
    _note.dispose();
    super.dispose();
  }

  void _submit() {
    final price = PricePerFineGram.tryFromString(_price.text);
    final quantity = FineWeight.tryFromGramsString(_quantity.text);

    if (price == null || price.isZero) {
      setState(() => _error = 'قیمت واردشده معتبر نیست.');

      return;
    }

    if (quantity == null || quantity.isZero) {
      setState(() => _error = 'مقدار واردشده معتبر نیست.');

      return;
    }

    Navigator.of(context).pop(
      _CounterValues(
        price: price,
        quantity: quantity,
        note: _note.text.trim().isEmpty ? null : _note.text.trim(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final price = PricePerFineGram.tryFromString(_price.text);
    final quantity = FineWeight.tryFromGramsString(_quantity.text);
    final total = (price != null && quantity != null)
        ? price.valueOf(quantity)
        : null;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        16,
        20,
        MediaQuery.viewInsetsOf(context).bottom + 20,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text('پیشنهاد متقابل', style: AppTypography.titleLarge),
            const SizedBox(height: 16),
            Text('مقدار (گرم خالص)', style: AppTypography.label),
            const SizedBox(height: 6),
            TextField(
              controller: _quantity,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.left,
              style: AppTypography.numeric,
              onChanged: (_) => setState(() => _error = null),
            ),
            const SizedBox(height: 16),
            Text('قیمت هر گرم (ریال)', style: AppTypography.label),
            const SizedBox(height: 6),
            TextField(
              controller: _price,
              keyboardType: TextInputType.number,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.left,
              style: AppTypography.numeric,
              onChanged: (_) => setState(() => _error = null),
            ),
            const SizedBox(height: 16),
            Text('یادداشت (اختیاری)', style: AppTypography.label),
            const SizedBox(height: 6),
            TextField(controller: _note, maxLines: 2),
            if (total != null) ...<Widget>[
              const ThinDivider(),
              KeyValueRow(
                label: 'مبلغ کل',
                emphasis: true,
                value: MoneyDisplay(total),
              ),
            ],
            if (_error != null) ...<Widget>[
              const SizedBox(height: 8),
              Text(
                _error!,
                style: AppTypography.caption.copyWith(color: Colors.red),
              ),
            ],
            const SizedBox(height: 20),
            Row(
              children: <Widget>[
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('انصراف'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton(
                    onPressed: _submit,
                    child: const Text('ارسال'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
