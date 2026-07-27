import 'package:flutter/material.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';

/// "There is nothing here" — as distinct from "this failed to load".
///
/// Conflating the two is a real hazard on a trading app: an empty settlement
/// queue means "you owe nobody anything", and a failed request that renders as
/// an empty list says the same thing while being completely wrong. Every list
/// in this app distinguishes them.
class EmptyState extends StatelessWidget {
  const EmptyState({
    required this.title,
    this.message,
    this.icon = Icons.inbox_outlined,
    this.action,
    super.key,
  });

  final String title;
  final String? message;
  final IconData icon;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Icon(icon, size: 44, color: AppColors.inkDisabled),
              const SizedBox(height: 12),
              Text(
                title,
                style: AppTypography.titleMedium,
                textAlign: TextAlign.center,
              ),
              if (message != null) ...<Widget>[
                const SizedBox(height: 6),
                Text(
                  message!,
                  style: AppTypography.bodyMuted,
                  textAlign: TextAlign.center,
                ),
              ],
              if (action != null) ...<Widget>[
                const SizedBox(height: 20),
                action!,
              ],
            ],
          ),
        ),
      );
}
