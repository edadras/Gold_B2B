import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/features/dashboard/application/balance_controller.dart';
import 'package:gold_b2b/features/otc/data/otc_repository.dart';
import 'package:gold_b2b/features/settlements/application/settlements_controller.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/price_per_fine_gram.dart';

/// Offers received (the ones that need an answer) and offers sent.
final incomingOtcOffersProvider = FutureProvider<Paginated<OtcOffer>>(
  (ref) => ref.watch(otcRepositoryProvider).fetchOffers(incoming: true),
);

final outgoingOtcOffersProvider = FutureProvider<Paginated<OtcOffer>>(
  (ref) => ref.watch(otcRepositoryProvider).fetchOffers(incoming: false),
);

final otcOfferProvider = FutureProvider.family<OtcOffer, int>(
  (ref, id) => ref.watch(otcRepositoryProvider).fetchOffer(id),
);

/// Accept / counter / reject.
///
/// Accepting an OTC offer creates a trade and a settlement obligation in one
/// step, so it gets the same idempotency treatment as an order: the key is
/// minted at the point of intent and reused for every delivery attempt.
class OtcActionsController extends StateNotifier<AsyncValue<void>> {
  OtcActionsController({
    required OtcRepository repository,
    required this.onChanged,
  })  : _repository = repository,
        super(const AsyncValue<void>.data(null));

  final OtcRepository _repository;
  final void Function() onChanged;

  final Map<int, IdempotencyKey> _keysByOffer = <int, IdempotencyKey>{};

  IdempotencyKey _keyFor(int offerId) =>
      _keysByOffer[offerId] ??= IdempotencyKey.mint();

  Future<bool> accept(int offerId) async {
    state = const AsyncValue<void>.loading();

    try {
      await _repository.accept(
        offerId: offerId,
        idempotencyKey: _keyFor(offerId),
      );

      _keysByOffer.remove(offerId);
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

  Future<bool> counter({
    required int offerId,
    required PricePerFineGram price,
    required FineWeight quantity,
    String? note,
  }) async {
    state = const AsyncValue<void>.loading();

    try {
      await _repository.counter(
        offerId: offerId,
        price: price,
        quantity: quantity,
        note: note,
        idempotencyKey: _keyFor(offerId),
      );

      _keysByOffer.remove(offerId);
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

  Future<bool> reject(int offerId) async {
    try {
      await _repository.reject(offerId);
      onChanged();

      return true;
    } on AppFailure catch (failure, stack) {
      if (mounted) {
        state = AsyncValue<void>.error(failure, stack);
      }

      return false;
    }
  }

  Future<bool> cancel(int offerId) async {
    try {
      await _repository.cancel(offerId);
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

final otcActionsProvider =
    StateNotifierProvider<OtcActionsController, AsyncValue<void>>(
  (ref) => OtcActionsController(
    repository: ref.watch(otcRepositoryProvider),
    onChanged: () {
      ref.invalidate(incomingOtcOffersProvider);
      ref.invalidate(outgoingOtcOffersProvider);
      unawaited(ref.read(balanceProvider.notifier).refreshQuietly());
      unawaited(ref.read(pendingSettlementsProvider.notifier).refresh());
    },
  ),
);
