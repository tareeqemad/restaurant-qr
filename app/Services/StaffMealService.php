<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMealCharge;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

/**
 * Staff meal allowance — runs every employee's monthly tab.
 *
 * Lifecycle of a staff order:
 *   1. Waiter creates an order with `staff_consumer_user_id` set (no
 *      `customer_id` — this is an internal consumption).
 *   2. Order flows through the kitchen normally (approve → preparing
 *      → ready → served). Inventory deducts at the configured stage.
 *   3. `chargeOrder()` snapshots `order.total` into a
 *      `staff_meal_charges` row and marks the order Completed. NO
 *      invoice gets created — the charge row IS the bookkeeping record.
 *
 * Over-limit behavior (per operator confirmation):
 *   - The system never HARD-blocks a staff meal that exceeds the cap.
 *   - The overflow is logged on the same charge (a single row, full
 *     amount) — the dashboard surfaces "over by X" so the manager sees
 *     it and can decide between payroll deduction, cash recovery, or
 *     write-off.
 *
 * Month-end:
 *   - `closeMonth()` settles every open charge in that month with the
 *     chosen method (`payroll_deduction` / `cash` / `writeoff`),
 *     freezing the snapshot for the payroll cycle. Anything left
 *     unsettled rolls forward as outstanding debt visible on the
 *     employee's tab next month.
 */
class StaffMealService
{
    /**
     * Convert a finished staff order into a tab charge. Safe to call
     * multiple times — re-runs are short-circuited by the existence of
     * a charge row for that order.
     */
    public function chargeOrder(Order $order, ?int $settledByUserId = null): StaffMealCharge
    {
        if (! $order->staff_consumer_user_id) {
            throw new \InvalidArgumentException('هذا الطلب غير معرَّف كطلب موظف (staff_consumer_user_id فارغ).');
        }

        if ($order->status === OrderStatus::Cancelled->value) {
            throw new \RuntimeException('لا يمكن احتساب طلب ملغى على بدل الوجبات.');
        }

        return DB::transaction(function () use ($order, $settledByUserId) {
            // Idempotency: every staff order yields exactly one charge
            // — re-running just returns the existing row.
            $existing = StaffMealCharge::where('order_id', $order->id)->first();
            if ($existing) return $existing;

            // Service charge handling for staff meals is operator-
            // configurable via the `staff_meal_include_service` setting.
            //   false (default) → strip service from the tab (the
            //     employee shouldn't pay a tip to their own colleagues).
            //   true            → leave service on; useful for chains
            //     that pool service across all sales for tip payouts.
            $includeService = (bool) \App\Models\Setting::get(
                'staff_meal_include_service',
                config('restaurant.staff_meals.include_service', false),
            );

            if (! $includeService && ((float) $order->service_rate > 0 || (float) $order->service_total > 0)) {
                $order->update(['service_rate' => 0]);
                app(\App\Services\OrderService::class)->recalculateTotals($order);
                $order->refresh();
            }

            $amount = (float) $order->total;

            $charge = StaffMealCharge::create([
                'branch_id'  => $order->branch_id,
                'user_id'    => $order->staff_consumer_user_id,
                'order_id'   => $order->id,
                'amount'     => $amount,
                'charged_at' => now(),
                // settled_at left null → row is OPEN (counts toward the
                // employee's running tab).
            ]);

            if (! in_array($order->status, [
                OrderStatus::Completed->value,
                OrderStatus::Cancelled->value,
            ], true)) {
                $order->update([
                    'status'       => OrderStatus::Completed->value,
                    'completed_at' => now(),
                ]);
            }

            $staff = User::find($order->staff_consumer_user_id);
            ActivityLog::log(
                'staff_meal.charged',
                "تم احتساب طلب {$order->number} على بدل الوجبات للموظف {$staff?->name}",
                $charge,
                [
                    'amount'     => $amount,
                    'remaining'  => $staff?->staffMealRemainingThisMonth(),
                    'over_limit' => $staff?->staffMealRemainingThisMonth() !== null
                                    && $staff->staffMealRemainingThisMonth() < 0,
                ]
            );

            return $charge;
        });
    }

    /**
     * Employee paid back their tab (cash, payroll deduction, etc.).
     * Settles the oldest open charges first up to `amount` — FIFO,
     * same as the customer-debt FIFO so behavior is consistent across
     * the two ledgers.
     *
     * Returns the list of charge IDs that were settled. Throws if
     * `amount` exceeds the total open balance (the manager should
     * adjust rather than over-pay).
     */
    public function settle(User $staff, float $amount, string $method = 'cash', ?int $settledByUserId = null, ?string $notes = null): array
    {
        if ($amount <= 0.001) {
            throw new \InvalidArgumentException('قيمة التسوية يجب أن تكون أكبر من صفر.');
        }

        if (! in_array($method, ['cash', 'payroll_deduction', 'writeoff'], true)) {
            throw new \InvalidArgumentException('طريقة التسوية غير مدعومة: '.$method);
        }

        return DB::transaction(function () use ($staff, $amount, $method, $settledByUserId, $notes) {
            $outstanding = $staff->staffMealOutstanding();
            if ($amount - $outstanding > 0.01) {
                throw new \RuntimeException(sprintf(
                    'قيمة التسوية (%s) أكبر من إجمالي المستحق (%s) للموظف %s.',
                    number_format($amount, 2),
                    number_format($outstanding, 2),
                    $staff->name,
                ));
            }

            $charges = StaffMealCharge::where('user_id', $staff->id)
                ->whereNull('settled_at')
                ->orderBy('charged_at')
                ->lockForUpdate()
                ->get();

            $remaining = round($amount, 2);
            $settled = [];

            foreach ($charges as $charge) {
                if ($remaining <= 0.001) break;

                $charged = round((float) $charge->amount, 2);
                if ($remaining + 0.001 >= $charged) {
                    // Whole charge fits → settle it fully.
                    $charge->update([
                        'settled_at'         => now(),
                        'settled_by_user_id' => $settledByUserId,
                        'settlement_method'  => $method,
                        'notes'              => trim(($charge->notes ?? '').' '.($notes ?? '')) ?: null,
                    ]);
                    $remaining = round($remaining - $charged, 2);
                    $settled[] = $charge->id;
                    continue;
                }

                // Partial settlement: split the row in two so the audit
                // trail keeps the original order reference intact.
                StaffMealCharge::create([
                    'branch_id'         => $charge->branch_id,
                    'user_id'           => $charge->user_id,
                    'order_id'          => $charge->order_id,
                    'amount'            => $remaining,
                    'charged_at'        => $charge->charged_at,
                    'settled_at'        => now(),
                    'settled_by_user_id'=> $settledByUserId,
                    'settlement_method' => $method,
                    'notes'             => trim('تسوية جزئية. '.($notes ?? '')),
                ]);
                $charge->update(['amount' => round($charged - $remaining, 2)]);
                $remaining = 0;
            }

            ActivityLog::log(
                'staff_meal.settled',
                "تسوية بدل وجبات للموظف {$staff->name} بقيمة ".number_format($amount, 2)." ({$method})",
                $staff,
                ['method' => $method, 'amount' => $amount, 'charges' => $settled, 'notes' => $notes],
            );

            return $settled;
        });
    }

    /**
     * "Quick consume" — the cashier/manager records that an employee
     * grabbed something from the kitchen/bar during their shift (a
     * cola, a snack, a water). No table, no kitchen ticket, no KDS —
     * the food is already in the staff's hand. Bypasses the usual
     * approve→preparing→ready→served workflow because there's nothing
     * for the kitchen to do.
     *
     * Side effects (all atomic):
     *   1. Create an order with `staff_consumer_user_id` set, status
     *      jumps straight to Completed.
     *   2. Each line records `unit_price` from the menu item's CURRENT
     *      price — a price change next month doesn't drift the
     *      historical record (`order_items.unit_price` is a snapshot).
     *   3. Inventory deducts immediately via `ensureDeducted` (skips
     *      the configured `deduction_stage` because the item is
     *      already gone from the shelf).
     *   4. Tab charge created — service stripped per the
     *      `staff_meal_include_service` setting.
     *
     * @param array<int,array{menu_item_id:int, quantity:float|int, notes?:?string}> $lines
     */
    public function quickConsume(User $staff, array $lines, ?int $recordedByUserId = null, ?string $notes = null): StaffMealCharge
    {
        if (empty($lines)) {
            throw new \InvalidArgumentException('قائمة الأصناف فارغة.');
        }

        if ($staff->monthly_meal_allowance === null) {
            throw new \RuntimeException('هذا الموظف ليس له بدل وجبات مفعّل. عدّل ملفه أولاً.');
        }

        return DB::transaction(function () use ($staff, $lines, $recordedByUserId, $notes) {
            $branchId = BranchContext::current();

            // Stock guard — refuse to record consumption of items the
            // kitchen doesn't actually have. Otherwise the manager
            // ends up over-claiming and the inventory goes negative.
            $inventory = app(\App\Services\InventoryService::class);
            $stockCheck = collect($lines)->map(fn ($l) => [
                'menu_item_id' => (int) $l['menu_item_id'],
                'quantity'     => (float) $l['quantity'],
                'modifier_ids' => [],
            ])->all();
            $issues = $inventory->checkStockForOrderPreview($stockCheck);
            if (! empty($issues)) {
                $short = collect($issues)
                    ->map(fn ($i) => $i['ingredient'].' (متاح '.rtrim(rtrim(number_format($i['available'], 2), '0'), '.').')')
                    ->take(3)
                    ->join('، ');
                throw new \RuntimeException("نفد المخزون من: {$short}.");
            }

            // Build the order. No table/session — this isn't a sit-
            // down meal. order_source='other' keeps reports from
            // counting these as real revenue.
            $order = Order::create([
                'branch_id'              => $branchId,
                'order_type'             => 'takeaway',
                'order_source'           => 'other',
                'status'                 => OrderStatus::Pending->value,
                'staff_consumer_user_id' => $staff->id,
                'created_by_user_id'     => $recordedByUserId ?? auth()->id(),
                'submitted_at'           => now(),
                'customer_notes'         => $notes,
                'tax_rate'               => 0,
                'service_rate'           => 0,    // strip up-front; chargeOrder won't need to recompute
            ]);

            $orders = app(\App\Services\OrderService::class);

            foreach ($lines as $line) {
                $oi = $orders->addItem($order, [
                    'menu_item_id' => (int) $line['menu_item_id'],
                    'quantity'     => (float) $line['quantity'],
                    'modifier_ids' => [],
                    'notes'        => $line['notes'] ?? null,
                ]);
                // Items skip the kitchen flow entirely.
                $oi->update([
                    'status'      => OrderItemStatus::Served->value,
                    'approved_at' => now(),
                    'served_at'   => now(),
                ]);
                // Deduct stock NOW regardless of `deduction_stage` —
                // the staff already took the item, the shelf is
                // already empty.
                $inventory->ensureDeducted($oi->fresh());
            }

            $orders->recalculateTotals($order);

            $order->update([
                'status'         => OrderStatus::Completed->value,
                'approved_at'    => now(),
                'approved_by_user_id' => $recordedByUserId ?? auth()->id(),
                'ready_at'       => now(),
                'delivered_at'   => now(),
                'completed_at'   => now(),
            ]);

            return $this->chargeOrder($order->fresh(), $recordedByUserId);
        });
    }

    /**
     * Per-employee monthly summary for the dashboard / payroll report.
     * Defaults to the current month.
     */
    public function monthSummary(User $staff, ?\Carbon\Carbon $month = null): array
    {
        $month = $month?->copy()->startOfMonth() ?? now()->startOfMonth();
        $end   = $month->copy()->endOfMonth();

        $allowance = $staff->monthly_meal_allowance !== null
            ? (float) $staff->monthly_meal_allowance
            : null;

        $usedThisMonth = (float) StaffMealCharge::query()
            ->where('user_id', $staff->id)
            ->whereNull('settled_at')
            ->whereBetween('charged_at', [$month, $end])
            ->sum('amount');

        $outstanding   = $staff->staffMealOutstanding();
        $overflow      = $allowance !== null ? max(0, $usedThisMonth - $allowance) : 0;

        return [
            'month'         => $month->format('Y-m'),
            'allowance'     => $allowance,
            'used'          => round($usedThisMonth, 2),
            'remaining'     => $allowance !== null ? round($allowance - $usedThisMonth, 2) : null,
            'overflow'      => round($overflow, 2),
            'outstanding'   => round($outstanding, 2),     // across ALL months
            'charge_count'  => StaffMealCharge::where('user_id', $staff->id)
                                ->whereNull('settled_at')
                                ->whereBetween('charged_at', [$month, $end])
                                ->count(),
        ];
    }
}
