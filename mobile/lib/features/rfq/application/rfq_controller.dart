import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/features/dashboard/application/balance_controller.dart';
import 'package:gold_b2b/features/rfq/data/rfq_repository.dart';
import 'package:gold_b2b/features/settlements/application/settlements_controller.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';
import 'package:gold_b2b/shared/models/purity.dart';

final myRfqsProvider = FutureProvider<Paginated<Rfq>>(
  (ref) => ref.watch(rfqRepositoryProvider).fetchMine(),
);

final rfqInboxProvider = FutureProvider<Paginated<Rfq>>(
  (ref) => ref.watch(rfqRepositoryProvider).fetchInbox(),
);

final rfqProvider = FutureProvider.family<Rfq, int>(
  (ref, id) => ref.watch(rfqRepositoryProvider).fetchRfq(id),
);

/// Quotes for one RFQ, sorted best-first.
///
/// "Best" is by PRICE, in the direction that favours the requester: a buyer
/// wants the lowest ask, a seller the highest bid. The sort is deliberately
/// not by reputation — reordering by a composite score would hide the price
/// comparison the user came for. Reputation is shown on every row instead,
/// with a warning where it matters (§2.6).
final rfqQuotesProvider =
    FutureProvider.family<List<RfqQuote>, int>((ref, rfqId) async {
  final repository = ref.watch(rfqRepositoryProvider);
  final rfq = await repository.fetchRfq(rfqId);
  final quotes = await repository.fetchQuotes(rfqId);

  final sorted = <RfqQuote>[...quotes];
  final requesterIsBuying = rfq.side.toUpperCase() == 'BUY';

  sorted.sort((a, b) {
    final byPrice = requesterIsBuying
        ? a.price.rial.compareTo(b.price.rial)
        : b.price.rial.compareTo(a.price.rial);

    if (byPrice != 0) {
      return byPrice;
    }

    // Same price: prefer the quote that covers more of the request.
    return b.quantity.milligrams.compareTo(a.quantity.milligrams);
  });

  return sorted;
});

/// Create an RFQ, accept a quote, answer somebody else's RFQ.
class RfqActionsController extends StateNotifier<AsyncValue<void>> {
  RfqActionsController({
    required RfqRepository repository,
    required this.onChanged,
  })  : _repository = repository,
        super(const AsyncValue<void>.data(null));

  final RfqRepository _repository;
  final void Function() onChanged;

  /// One key per logical intent, held until that intent succeeds.
  final Map<String, IdempotencyKey> _keys = <String, IdempotencyKey>{};

  IdempotencyKey _keyFor(String intent) =>
      _keys[intent] ??= IdempotencyKey.mint();

  Future<Rfq?> create({
    required String instrumentCode,
    required String side,
    required FineWeight quantity,
    required String settlementType,
    required String deliveryType,
    required String visibility,
    required int expiresInMinutes,
    required bool allowPartial,
    Purity? minPurity,
    List<int>? recipientOrganizationIds,
  }) async {
    state = const AsyncValue<void>.loading();

    try {
      final rfq = await _repository.create(
        instrumentCode: instrumentCode,
        side: side,
        quantity: quantity,
        settlementType: settlementType,
        deliveryType: deliveryType,
        visibility: visibility,
        expiresInMinutes: expiresInMinutes,
        allowPartial: allowPartial,
        minPurity: minPurity,
        recipientOrganizationIds: recipientOrganizationIds,
        idempotencyKey: _keyFor('create'),
      );

      _keys.remove('create');
      onChanged();
      if (mounted) {
        state = const AsyncValue<void>.data(null);
      }

      return rfq;
    } on AppFailure catch (failure, stack) {
      if (mounted) {
        state = AsyncValue<void>.error(failure, stack);
      }

      return null;
    }
  }

  Future<bool> acceptQuote(int quoteId) async {
    state = const AsyncValue<void>.loading();

    try {
      await _repository.acceptQuote(
        quoteId: quoteId,
        idempotencyKey: _keyFor('accept-$quoteId'),
      );

      _keys.remove('accept-$quoteId');
      onChanged();
      if (mounted) {
        state = const AsyncValue<void>.data(null);
      }

      return true;
    } on AppFailure catch (failure, stack) {
      if (mounted) {
        state = AsyncValue<void>.error(failure, stack);
      }

      return false;
    }
  }

  Future<bool> submitQuote({
    required int rfqId,
    required FineWeight quantity,
    required PricePerFineGram price,
    String? note,
  }) async {
    state = const AsyncValue<void>.loading();

    try {
      await _repository.submitQuote(
        rfqId: rfqId,
        quantity: quantity,
        price: price,
        note: note,
        idempotencyKey: _keyFor('quote-$rfqId'),
      );

      _keys.remove('quote-$rfqId');
      onChanged();
      if (mounted) {
        state = const AsyncValue<void>.data(null);
      }

      return true;
    } on AppFailure catch (failure, stack) {
      if (mounted) {
        state = AsyncValue<void>.error(failure, stack);
      }

      return false;
    }
  }

  Future<bool> cancel(int rfqId) async {
    try {
      await _repository.cancel(rfqId);
      onChanged();

      return true;
    } on AppFailure catch (failure, stack) {
      if (mounted) {
        state = AsyncValue<void>.error(failure, stack);
      }

      return false;
    }
  }
}

final rfqActionsProvider =
    StateNotifierProvider<RfqActionsController, AsyncValue<void>>(
  (ref) => RfqActionsController(
    repository: ref.watch(rfqRepositoryProvider),
    onChanged: () {
      ref.invalidate(myRfqsProvider);
      ref.invalidate(rfqInboxProvider);
      unawaited(ref.read(balanceProvider.notifier).refreshQuietly());
      unawaited(ref.read(pendingSettlementsProvider.notifier).refresh());
    },
  ),
);
