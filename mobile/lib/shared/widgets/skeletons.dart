import 'package:flutter/material.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';

/// Loading placeholders.
///
/// docs/07-mobile-flutter/02-screens.md §2.10 asks for skeletons rather than
/// spinners, and there is a substantive reason beyond taste: a skeleton
/// preserves the layout, so when the balance card resolves it does not shove
/// the "action required" list — the thing the user actually came for — down
/// the page under their thumb.
class SkeletonBox extends StatefulWidget {
  const SkeletonBox({
    required this.width,
    required this.height,
    this.radius = 6,
    super.key,
  });

  const SkeletonBox.line({double width = double.infinity, Key? key})
      : this(width: width, height: 14, key: key);

  final double width;
  final double height;
  final double radius;

  @override
  State<SkeletonBox> createState() => _SkeletonBoxState();
}

class _SkeletonBoxState extends State<SkeletonBox>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: _controller,
        builder: (context, _) => Opacity(
          opacity: 0.35 + _controller.value * 0.35,
          child: Container(
            width: widget.width,
            height: widget.height,
            decoration: BoxDecoration(
              color: AppColors.divider,
              borderRadius: BorderRadius.circular(widget.radius),
            ),
          ),
        ),
      );
}

/// Balance card placeholder — matches the real card's height so the dashboard
/// does not reflow.
class BalanceCardSkeleton extends StatelessWidget {
  const BalanceCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppColors.divider),
        ),
        child: const Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            SkeletonBox(width: 90, height: 12),
            SizedBox(height: 12),
            SkeletonBox(width: 180, height: 28),
            SizedBox(height: 8),
            SkeletonBox(width: 130, height: 14),
            SizedBox(height: 16),
            Divider(height: 1, color: AppColors.divider),
            SizedBox(height: 12),
            SkeletonBox.line(),
            SizedBox(height: 8),
            SkeletonBox.line(),
          ],
        ),
      );
}

/// Placeholder for a list of rows.
class ListSkeleton extends StatelessWidget {
  const ListSkeleton({this.rows = 5, this.rowHeight = 68, super.key});

  final int rows;
  final double rowHeight;

  @override
  Widget build(BuildContext context) => ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: rows,
        separatorBuilder: (_, __) => const SizedBox(height: 12),
        itemBuilder: (_, __) => Container(
          height: rowHeight,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppColors.divider),
          ),
          child: const Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: <Widget>[
              SkeletonBox(width: 140, height: 14),
              SkeletonBox(width: 200, height: 12),
            ],
          ),
        ),
      );
}
