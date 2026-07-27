import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gold_b2b/shared/widgets/error_view.dart';

/// Renders an [AsyncValue] with the app's standard loading and error
/// treatments.
///
/// Two behaviours worth knowing about:
///
///   * While a refresh is in flight over existing data, the PREVIOUS data
///     stays on screen rather than being replaced by a skeleton. Flashing a
///     skeleton over a balance the user is already reading is worse than
///     showing a value that is one second old, and every screen pairs this
///     with a staleness indicator anyway.
///   * An error that arrives while previous data exists renders as an inline
///     banner ABOVE the data, not instead of it. Losing the last known balance
///     because a refresh failed is exactly backwards.
class AsyncValueView<T> extends StatelessWidget {
  const AsyncValueView({
    required this.value,
    required this.data,
    this.loading,
    this.onRetry,
    super.key,
  });

  final AsyncValue<T> value;
  final Widget Function(T data) data;
  final Widget? loading;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final previous = value.valueOrNull;

    if (value.hasError && previous == null) {
      return ErrorView(
        failure: asAppFailure(value.error!),
        onRetry: onRetry,
      );
    }

    if (previous == null) {
      return loading ??
          const Center(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: CircularProgressIndicator(),
            ),
          );
    }

    if (value.hasError) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: InlineError(
              failure: asAppFailure(value.error!),
              onRetry: onRetry,
            ),
          ),
          data(previous),
        ],
      );
    }

    return data(previous);
  }
}

/// Sliver form, for screens built out of a `CustomScrollView`.
class SliverAsyncValueView<T> extends StatelessWidget {
  const SliverAsyncValueView({
    required this.value,
    required this.data,
    this.loading,
    this.onRetry,
    super.key,
  });

  final AsyncValue<T> value;
  final Widget Function(T data) data;
  final Widget? loading;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) => SliverToBoxAdapter(
        child: AsyncValueView<T>(
          value: value,
          data: data,
          loading: loading,
          onRetry: onRetry,
        ),
      );
}
