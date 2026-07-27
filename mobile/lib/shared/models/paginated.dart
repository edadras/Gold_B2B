/// A page of results plus the cursors needed to walk forward and back.
///
/// docs/05-api/01-conventions.md §1.8: large lists are cursor paginated and
/// the cursor is an opaque base64 blob. Nothing in the client may decode or
/// construct one — it is passed back verbatim.
final class Paginated<T> {
  const Paginated({
    required this.items,
    this.nextCursor,
    this.previousCursor,
    this.count,
  });

  final List<T> items;

  /// Opaque cursor for the next page, or null when this is the last page.
  final String? nextCursor;

  /// Opaque cursor for the previous page, or null at the start.
  final String? previousCursor;

  /// `meta.count` when the server sent one.
  final int? count;

  const Paginated.empty()
      : items = const [],
        nextCursor = null,
        previousCursor = null,
        count = 0;

  bool get hasMore => nextCursor != null;

  bool get isEmpty => items.isEmpty;

  bool get isNotEmpty => items.isNotEmpty;

  int get length => items.length;

  /// Append a freshly fetched page to this one, for infinite-scroll lists.
  Paginated<T> followedBy(Paginated<T> next) => Paginated<T>(
        items: <T>[...items, ...next.items],
        nextCursor: next.nextCursor,
        previousCursor: previousCursor,
        count: (count ?? items.length) + (next.count ?? next.items.length),
      );

  Paginated<T> copyWithItems(List<T> newItems) => Paginated<T>(
        items: newItems,
        nextCursor: nextCursor,
        previousCursor: previousCursor,
        count: count,
      );

  Paginated<R> map<R>(R Function(T item) transform) => Paginated<R>(
        items: items.map(transform).toList(growable: false),
        nextCursor: nextCursor,
        previousCursor: previousCursor,
        count: count,
      );
}
