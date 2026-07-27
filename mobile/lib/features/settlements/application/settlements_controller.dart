import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/core/config/constants.dart';
import 'package:gold_b2b/core/network/api_exception.dart';
import 'package:gold_b2b/core/network/idempotency.dart';
import 'package:gold_b2b/core/providers.dart';
import 'package:gold_b2b/core/realtime/realtime_event.dart';
import 'package:gold_b2b/core/realtime/websocket_service.dart';
import 'package:gold_b2b/features/dashboard/application/balance_controller.dart';
import 'package:gold_b2b/features/settlements/data/models/netting_batch.dart';
import 'package:gold_b2b/features/settlements/data/models/settlement.dart';
import 'package:gold_b2b/features/settlements/data/settlement_repository.dart';
import 'package:gold_b2b/shared/models/paginated.dart';
import 'package:gold_b2b/shared/models/rial.dart';

/// "در انتظار اقدام من" — the queue that drives the dashboard's
/// action-required block.
///
/// Live over `settlement.status_changed`, and re-read in full on every
/// reconnect. A settlement whose deadline passed while the socket was down
/// must not keep showing a comfortable "۳ ساعت مانده".
class PendingSettlementsController
    extends StateNotifier<AsyncValue<List<Settlement>>> {
  PendingSettlementsController({
    required SettlementRepository repository,
    required WebSocketService realtime,
    required this.onLedgerMayHaveChanged,
  })  : _repository = repository,
        super(const AsyncValue<List<Settlement>>.loading()) {
    _events = realtime
        .on(RealtimeEvents.settlementStatusChanged)
        .listen(_applyRealtimeUpdate);
    unawaited(refresh());
  }

  final SettlementRepository _repository;
  final void Function() onLedgerMayHaveChanged;

  StreamSubscription<RealtimeEvent>? _events;

  Future<void> refresh() async {
    final next = await AsyncValue.guard(_repository.fetchPending);
    if (mounted) {
      state = next;
    }
  }

  void _applyRealtimeUpdate(RealtimeEvent event) {
    // A settlement transition almost always moves the ledger, and the set of
    // things "waiting for me" may have gained an entry rather than changed
    // one — so re-read rather than patch. The pending queue is short.
    unawaited(refresh());
    onLedgerMayHaveChanged();
  }

  @override
  void dispose() {
    unawaited(_events?.cancel());
    super.dispose();
  }
}

final pendingSettlementsProvider = StateNotifierProvider<
    PendingSettlementsController, AsyncValue<List<Settlement>>>(
  (ref) => PendingSettlementsController(
    repository: ref.watch(settlementRepositoryProvider),
    realtime: ref.watch(webSocketServiceProvider),
    onLedgerMayHaveChanged: () =>
        unawaited(ref.read(balanceProvider.notifier).refreshQuietly()),
  ),
);

/// The tab filters on the settlements screen (§2.5).
enum SettlementFilter { needsAction, waiting, overdue, completed, all }

extension SettlementFilterX on SettlementFilter {
  String get label => switch (this) {
        SettlementFilter.needsAction => 'نیاز به اقدام',
        SettlementFilter.waiting => 'منتظر',
        SettlementFilter.overdue => 'گذشته',
        SettlementFilter.completed => 'تکمیل‌شده',
        SettlementFilter.all => 'همه',
      };

  List<String>? get statuses => switch (this) {
        SettlementFilter.needsAction => const <String>[
            SettlementStatus.paymentPending,
            SettlementStatus.paymentDeclared,
            SettlementStatus.goldTransferring,
          ],
        SettlementFilter.waiting => const <String>[
            SettlementStatus.assetsLocked,
            SettlementStatus.paymentConfirmed,
            SettlementStatus.nettingQueue,
          ],
        SettlementFilter.overdue => const <String>[
            SettlementStatus.overdue,
            SettlementStatus.defaulted,
          ],
        SettlementFilter.completed => const <String>[
            SettlementStatus.settled,
            SettlementStatus.completed,
          ],
        SettlementFilter.all => null,
      };
}

final settlementListProvider = FutureProvider.family<Paginated<Settlement>,
    SettlementFilter>((ref, filter) async {
  final all = await ref
      .watch(settlementRepositoryProvider)
      .fetchSettlements(statuses: filter.statuses, limit: Ui.defaultPageSize);

  if (filter != SettlementFilter.needsAction) {
    return all;
  }

  // The server-side status filter cannot express "and it is my turn", so the
  // final narrowing happens here.
  return all.copyWithItems(
    all.items
        .where((settlement) => settlement.requiresMyAction)
        .toList(growable: false),
  );
});

final settlementDetailProvider = FutureProvider.family<Settlement, int>(
  (ref, id) => ref.watch(settlementRepositoryProvider).fetchSettlement(id),
);

final settlementEventsProvider =
    FutureProvider.family<List<SettlementEvent>, int>(
  (ref, id) => ref.watch(settlementRepositoryProvider).fetchEvents(id),
);

/// Actions on a single settlement.
///
/// Each one mints its idempotency key at the point of intent and holds it for
/// the duration of the attempt, so a retry after a timeout cannot double-
/// declare a payment or double-confirm a receipt.
class SettlementActionsController extends StateNotifier<AsyncValue<void>> {
  SettlementActionsController({
    required SettlementRepository repository,
    required this.onChanged,
  })  : _repository = repository,
        super(const AsyncValue<void>.data(null));

  final SettlementRepository _repository;
  final void Function() onChanged;

  /// Held so that a retry after a failure reuses the same key.
  IdempotencyKey? _inFlightKey;

  IdempotencyKey _keyForAttempt() => _inFlightKey ??= IdempotencyKey.mint();

  void _completeAttempt() {
    _inFlightKey = null;
    onChanged();
  }

  Future<bool> declarePayment({
    required int settlementId,
    required String paymentReference,
    required Rial amount,
    required DateTime paidAt,
    int? receiptDocumentId,
    String? note,
  }) async {
    state = const AsyncValue<void>.loading();

    try {
      await _repository.declarePayment(
        settlementId: settlementId,
        paymentReference: paymentReference,
        amount: amount,
        paidAt: paidAt,
        receiptDocumentId: receiptDocumentId,
        note: note,
        idempotencyKey: _keyForAttempt(),
      );

      _completeAttempt();
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

  Future<bool> confirmPayment({
    required int settlementId,
    String? totpCode,
  }) async {
    state = const AsyncValue<void>.loading();

    try {
      await _repository.confirmPayment(
        settlementId: settlementId,
        totpCode: totpCode,
        idempotencyKey: _keyForAttempt(),
      );

      _completeAttempt();
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

  Future<bool> confirmDelivery({
    required int settlementId,
    String? totpCode,
  }) async {
    state = const AsyncValue<void>.loading();

    try {
      await _repository.confirmDelivery(
        settlementId: settlementId,
        totpCode: totpCode,
        idempotencyKey: _keyForAttempt(),
      );

      _completeAttempt();
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
}

final settlementActionsProvider =
    StateNotifierProvider<SettlementActionsController, AsyncValue<void>>(
  (ref) => SettlementActionsController(
    repository: ref.watch(settlementRepositoryProvider),
    onChanged: () {
      unawaited(ref.read(pendingSettlementsProvider.notifier).refresh());
      unawaited(ref.read(balanceProvider.notifier).refreshQuietly());
      ref.invalidate(settlementListProvider);
    },
  ),
);

// --- netting ---------------------------------------------------------------

final nettingBatchesProvider = FutureProvider<List<NettingBatch>>(
  (ref) => ref.watch(settlementRepositoryProvider).fetchNettingBatches(),
);

final nettingBatchProvider = FutureProvider.family<NettingBatch, int>(
  (ref, id) => ref.watch(settlementRepositoryProvider).fetchNettingBatch(id),
);

class NettingActionsController extends StateNotifier<AsyncValue<void>> {
  NettingActionsController({
    required SettlementRepository repository,
    required this.onChanged,
  })  : _repository = repository,
        super(const AsyncValue<void>.data(null));

  final SettlementRepository _repository;
  final void Function() onChanged;

  IdempotencyKey? _inFlightKey;

  Future<bool> accept({required int batchId, String? totpCode}) async {
    state = const AsyncValue<void>.loading();
    final key = _inFlightKey ??= IdempotencyKey.mint();

    try {
      await _repository.acceptNetting(
        batchId: batchId,
        totpCode: totpCode,
        idempotencyKey: key,
      );

      _inFlightKey = null;
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

  Future<bool> reject({required int batchId, String? reason}) async {
    state = const AsyncValue<void>.loading();
    final key = _inFlightKey ??= IdempotencyKey.mint();

    try {
      await _repository.rejectNetting(
        batchId: batchId,
        reason: reason,
        idempotencyKey: key,
      );

      _inFlightKey = null;
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
}

final nettingActionsProvider =
    StateNotifierProvider<NettingActionsController, AsyncValue<void>>(
  (ref) => NettingActionsController(
    repository: ref.watch(settlementRepositoryProvider),
    onChanged: () {
      ref.invalidate(nettingBatchesProvider);
      unawaited(ref.read(balanceProvider.notifier).refreshQuietly());
    },
  ),
);
