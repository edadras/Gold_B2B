import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/features/lots/data/lot_repository.dart';
import 'package:gold_b2b/shared/models/fine_weight.dart';
import 'package:gold_b2b/shared/models/paginated.dart';

final lotsProvider = FutureProvider<Paginated<GoldLot>>(
  (ref) => ref.watch(lotRepositoryProvider).fetchLots(),
);

final lotProvider = FutureProvider.family<GoldLot, int>(
  (ref, id) => ref.watch(lotRepositoryProvider).fetchLot(id),
);

final lotLineageProvider = FutureProvider.family<LotLineage, int>(
  (ref, id) => ref.watch(lotRepositoryProvider).fetchLineage(id),
);

final lotVerificationProvider =
    FutureProvider.family<LotVerification, String>(
  (ref, token) => ref.watch(lotRepositoryProvider).verify(token),
);

/// Aggregate figures for the header of the lots screen: total fine weight,
/// piece count, and the split between vault and self-custody.
final class LotsSummary {
  const LotsSummary({
    required this.totalFine,
    required this.inVaultFine,
    required this.selfCustodyFine,
    required this.pieceCount,
  });

  final FineWeight totalFine;
  final FineWeight inVaultFine;
  final FineWeight selfCustodyFine;
  final int pieceCount;

  static LotsSummary of(List<GoldLot> lots) {
    var total = FineWeight.zero;
    var inVault = FineWeight.zero;
    var self = FineWeight.zero;

    for (final lot in lots) {
      if (lot.status == LotStatus.consumed ||
          lot.status == LotStatus.withdrawn) {
        // A consumed lot survives only for lineage; counting it would
        // double-count the gold that came out of it.
        continue;
      }

      total = total + lot.fineWeight;
      if (lot.isInVault) {
        inVault = inVault + lot.fineWeight;
      } else {
        self = self + lot.fineWeight;
      }
    }

    return LotsSummary(
      totalFine: total,
      inVaultFine: inVault,
      selfCustodyFine: self,
      pieceCount: lots
          .where(
            (lot) =>
                lot.status != LotStatus.consumed &&
                lot.status != LotStatus.withdrawn,
          )
          .length,
    );
  }
}

final lotsSummaryProvider = Provider<LotsSummary?>((ref) {
  final lots = ref.watch(lotsProvider).valueOrNull;

  return lots == null ? null : LotsSummary.of(lots.items);
});

/// Assay requests and vault operations.
class LotActionsController extends StateNotifier<AsyncValue<void>> {
  LotActionsController({
    required LotRepository repository,
    required this.onChanged,
  })  : _repository = repository,
        super(const AsyncValue<void>.data(null));

  final LotRepository _repository;
  final void Function() onChanged;

  final Map<String, IdempotencyKey> _keys = <String, IdempotencyKey>{};

  Future<bool> requestAssay(int lotId) async {
    state = const AsyncValue<void>.loading();
    final key = _keys['assay-$lotId'] ??= IdempotencyKey.mint();

    try {
      await _repository.requestAssay(lotId: lotId, idempotencyKey: key);
      _keys.remove('assay-$lotId');
      onChanged();
      if (mounted) {
        state = const AsyncValue<void>.data(null);
      }

      return true;
    } on Object catch (error, stack) {
      if (mounted) {
        state = AsyncValue<void>.error(error, stack);
      }

      return false;
    }
  }

  Future<bool> requestWithdrawal({
    required int lotId,
    String? totpCode,
    String? note,
  }) async {
    state = const AsyncValue<void>.loading();
    final key = _keys['withdraw-$lotId'] ??= IdempotencyKey.mint();

    try {
      await _repository.requestWithdrawal(
        lotId: lotId,
        totpCode: totpCode,
        note: note,
        idempotencyKey: key,
      );
      _keys.remove('withdraw-$lotId');
      onChanged();
      if (mounted) {
        state = const AsyncValue<void>.data(null);
      }

      return true;
    } on Object catch (error, stack) {
      if (mounted) {
        state = AsyncValue<void>.error(error, stack);
      }

      return false;
    }
  }
}

final lotActionsProvider =
    StateNotifierProvider<LotActionsController, AsyncValue<void>>(
  (ref) => LotActionsController(
    repository: ref.watch(lotRepositoryProvider),
    onChanged: () => ref.invalidate(lotsProvider),
  ),
);
