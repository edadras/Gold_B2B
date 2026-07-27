import 'package:flutter/material.dart';
import 'package:gold_b2b/core/formatting/jalali_formatter.dart';
import 'package:gold_b2b/core/formatting/weight_formatter.dart';
import 'package:gold_b2b/core/theme/app_colors.dart';
import 'package:gold_b2b/core/theme/app_typography.dart';
import 'package:gold_b2b/features/orders/data/models/order.dart';
import 'package:gold_b2b/shared/widgets/ltr_number.dart';
import 'package:gold_b2b/shared/widgets/money_display.dart';
import 'package:gold_b2b/shared/widgets/section_card.dart';
import 'package:gold_b2b/shared/widgets/status_badge.dart';

/// One row in the open-orders or history list.
class OrderTile extends StatelessWidget {
  const OrderTile({
    required this.order,
    this.onCancel,
    this.onTap,
    super.key,
  });

  final Order order;
  final VoidCallback? onCancel;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => SectionCard(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                SideBadge(order.side.wire, dense: true),
                const SizedBox(width: 8),
                Text(order.instrumentCode, style: AppTypography.titleMedium),
                const Spacer(),
                StatusBadge(
                  label: OrderStatus.label(order.status),
                  tone: OrderStatus.tone(order.status),
                  dense: true,
                ),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              children: <Widget>[
                LtrNumber(
                  WeightFormatter.compactFine(order.quantity),
                  style: AppTypography.numeric,
                ),
                Text(' گرم', style: AppTypography.caption),
                const SizedBox(width: 8),
                Text('@', style: AppTypography.caption),
                const SizedBox(width: 8),
                if (order.price != null)
                  PriceDisplay(order.price!)
                else
                  Text('بازار', style: AppTypography.bodyMuted),
              ],
            ),
            if (order.isPartiallyFilled) ...<Widget>[
              const SizedBox(height: 10),
              // Fill progress is the single most useful thing on an open
              // order: it tells the user how much gold is already committed
              // and therefore how much a cancel would actually release.
              ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(
                  value: order.fillRatio(),
                  minHeight: 6,
                  backgroundColor: AppColors.divider,
                ),
              ),
              const SizedBox(height: 6),
              Row(
                children: <Widget>[
                  Text('اجرا شده ', style: AppTypography.caption),
                  LtrNumber(
                    WeightFormatter.compactFine(order.filled),
                    style: AppTypography.numericSmall,
                  ),
                  Text(' از ', style: AppTypography.caption),
                  LtrNumber(
                    WeightFormatter.compactFine(order.quantity),
                    style: AppTypography.numericSmall,
                  ),
                  Text(' گرم', style: AppTypography.caption),
                ],
              ),
            ],
            const SizedBox(height: 10),
            Row(
              children: <Widget>[
                Text(order.orderCode, style: AppTypography.caption),
                const Spacer(),
                Text(
                  JalaliFormatter.smart(order.placedAt),
                  style: AppTypography.caption,
                ),
              ],
            ),
            if (onCancel != null && order.isCancellable) ...<Widget>[
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed:
                    order.status == OrderStatus.cancelling ? null : onCancel,
                icon: const Icon(Icons.close, size: 16),
                label: Text(
                  order.status == OrderStatus.cancelling
                      ? 'در حال لغو…'
                      : 'لغو سفارش',
                ),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.negative,
                ),
              ),
            ],
          ],
        ),
      );
}
