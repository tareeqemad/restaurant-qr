<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Exceptions\StaffMealLimitException;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\StaffMealCharge;
use App\Models\StaffMealMonthClosure;
use App\Models\User;
use App\Services\Accounting\AccountingService;
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
 * Limit-control (the "debt control" layer):
 *   - `monthly_meal_allowance` is a SOFT cap per month.
 *   - `meal_debt_ceiling` is a HARD cap across ALL months.
 *   - Setting `staff_meal_over_limit_policy` decides what happens when
 *     a charge would push the user past either cap:
 *       allow_log         → record overflow silently (legacy default)
 *       warn              → record, log a warning, return signal to UI
 *       require_approval  → record only if `$approverUserId` is supplied
 *                           with manager-or-higher role
 *       block             → throw StaffMealLimitException (no charge created)
 *
 * Month-end:
 *   - `closeMonth()` settles every open charge in that month with the
 *     chosen method (`payroll_deduction` / `cash` / `writeoff`),
 *     freezing the snapshot for the payroll cycle and creating one
 *     `StaffMealMonthClosure` row that drives the printable report.
 *
 * Accounting:
 *   - Each charge posts: DR 5050 Staff Meal Expense,
 *                        CR 1110 Staff Meal Receivable.
 *   - Each settlement reverses the receivable into cash / payroll
 *     liability / writeoff expense.
 *   - The whole accounting layer is best-effort — if the chart of
 *     accounts is missing (fresh install, tests), the charge still
 *     succeeds, we just log a warning. This mirrors how the rest of
 *     the codebase uses AccountingService.
 */
class StaffMealService
{
    public const POLICY_ALLOW_LOG        = 'allow_log';
    public const POLICY_WARN             = 'warn';
    public const POLICY_REQUIRE_APPROVAL = 'require_approval';
    public const POLICY_BLOCK            = 'block';

    public function __construct(protected ?AccountingService $accounting = null) {}

    // ───────────────────────────────────────────────────────────────
    // Limit pre-flight
    // ───────────────────────────────────────────────────────────────

    /**
     * Check whether `$amount` can be charged to `$staff` under the
     * current policy WITHOUT actually creating the charge. The UI
     * calls this before opening the order so the user can warn /
     * confirm / require manager PIN as appropriate.
     *
     * Returns an associative array:
     *   - status:    'ok' | 'warn' | 'requires_approval' | 'blocked'
     *   - reason:    human-readable message (or null when ok)
     *   - allowance: ['used', 'cap', 'remaining', 'over_by']
     *   - ceiling:   ['outstanding', 'cap', 'headroom', 'over_by']
     *   - policy:    active policy string
     */
    public function previewLimitCheck(User $staff, float $amount, ?float $alreadyOpenAmount = 0.0): array
    {
        $policy = (string) Setting::get('staff_meal_over_limit_policy', self::POLICY_WARN);

        $allowanceCap = $staff->monthly_meal_allowance !== null ? (float) $staff->monthly_meal_allowance : null;
        $allowanceUsed = $staff->staffMealUsedInMonth();
        $allowanceRemaining = $allowanceCap !== null ? round($allowanceCap - $allowanceUsed, 2) : null;
        $allowanceOver = $allowanceRemaining !== null ? max(0, $amount - $allowanceRemaining) : 0;

        $ceilingCap = $staff->meal_debt_ceiling !== null ? (float) $staff->meal_debt_ceiling : null;
        $ceilingOutstanding = $staff->staffMealOutstanding();
        $ceilingHeadroom = $ceilingCap !== null ? round($ceilingCap - $ceilingOutstanding, 2) : null;
        $ceilingOver = $ceilingHeadroom !== null ? max(0, $amount - $ceilingHeadroom) : 0;

        // The hard ceiling decides block-eligibility; the soft monthly
        // allowance only ever warns (it's expected to overflow into the
        // employee's running tab — that's literally the design).
        $hardOver = $ceilingOver > 0.01;
        $softOver = $allowanceOver > 0.01;

        $status = 'ok';
        $reason = null;

        if ($hardOver) {
            $status = match ($policy) {
                self::POLICY_BLOCK            => 'blocked',
                self::POLICY_REQUIRE_APPROVAL => 'requires_approval',
                self::POLICY_WARN             => 'warn',
                default                       => 'warn',
            };
            $reason = sprintf(
                'الموظف يتجاوز سقف الدين المسموح بـ %s. (الدين القائم: %s | السقف: %s)',
                number_format($ceilingOver, 2),
                number_format($ceilingOutstanding + $amount, 2),
                number_format($ceilingCap, 2),
            );
        } elseif ($softOver) {
            // Over the monthly allowance is "warn-worthy" by default,
            // but `allow_log` still suppresses the warning (legacy).
            $status = $policy === self::POLICY_ALLOW_LOG ? 'ok' : 'warn';
            $reason = $status === 'warn'
                ? sprintf(
                    'سيتجاوز الموظف بدله الشهري بـ %s ش.إ.',
                    number_format($allowanceOver, 2),
                )
                : null;
        }

        return [
            'status'   => $status,
            'reason'   => $reason,
            'policy'   => $policy,
            'allowance' => [
                'cap'       => $allowanceCap,
                'used'      => round($allowanceUsed, 2),
                'remaining' => $allowanceRemaining,
                'over_by'   => round($allowanceOver, 2),
            ],
            'ceiling' => [
                'cap'         => $ceilingCap,
                'outstanding' => round($ceilingOutstanding, 2),
                'headroom'    => $ceilingHeadroom,
                'over_by'     => round($ceilingOver, 2),
            ],
        ];
    }

    /**
     * Enforce the policy by throwing when blocked. The two "soft"
     * outcomes (warn / requires_approval) just log to ActivityLog and
     * proceed — the manager-PIN flow happens at the UI layer.
     */
    protected function enforceLimit(User $staff, float $amount, ?int $approverUserId = null): array
    {
        $check = $this->previewLimitCheck($staff, $amount);

        if ($check['status'] === 'blocked') {
            throw new StaffMealLimitException(
                staff: $staff,
                limitType: 'debt_ceiling',
                attempted: $amount,
                headroom: $check['ceiling']['headroom'] ?? 0,
                policy: $check['policy'],
            );
        }

        if ($check['status'] === 'requires_approval' && ! $approverUserId) {
            throw new StaffMealLimitException(
                staff: $staff,
                limitType: 'debt_ceiling',
                attempted: $amount,
                headroom: $check['ceiling']['headroom'] ?? 0,
                policy: $check['policy'],
                message: 'الموظف يتجاوز سقف الدين — هذا الطلب يحتاج موافقة مدير.',
            );
        }

        return $check;
    }

    // ───────────────────────────────────────────────────────────────
    // Charge an order
    // ───────────────────────────────────────────────────────────────

    /**
     * Convert a finished staff order into a tab charge. Safe to call
     * multiple times — re-runs are short-circuited by the existence of
     * a charge row for that order.
     *
     * @param int|null $approverUserId Manager who approved the over-limit
     *                                 charge (only needed when the active
     *                                 policy is `require_approval`).
     */
    /**
     * @param bool $isGift  When true, the order is recorded but settled
     *                      immediately as method='gift' — the kitchen
     *                      still cooked, inventory still deducted, but
     *                      NOTHING lands on the employee's tab. Used
     *                      for birthday meals, employee-of-the-month
     *                      rewards, retention gifts. Limit checks are
     *                      skipped — a gift can't push the employee
     *                      over their ceiling because it's not debt.
     * @param string|null $giftReason  Free-text reason stored on the
     *                                 charge.notes for the audit trail.
     */
    public function chargeOrder(Order $order, ?int $settledByUserId = null, ?int $approverUserId = null, bool $isGift = false, ?string $giftReason = null): StaffMealCharge
    {
        if (! $order->staff_consumer_user_id) {
            throw new \InvalidArgumentException('هذا الطلب غير معرَّف كطلب موظف (staff_consumer_user_id فارغ).');
        }

        if ($order->status === OrderStatus::Cancelled->value) {
            throw new \RuntimeException('لا يمكن احتساب طلب ملغى على بدل الوجبات.');
        }

        // Idempotency: every staff order yields exactly one charge —
        // re-running just returns the existing row. Check OUTSIDE the
        // transaction so we don't enforce limits on a re-run.
        $existing = StaffMealCharge::where('order_id', $order->id)->first();
        if ($existing) return $existing;

        return DB::transaction(function () use ($order, $settledByUserId, $approverUserId, $isGift, $giftReason) {
            // Service charge handling for staff meals is operator-
            // configurable via the `staff_meal_include_service` setting.
            //   false (default) → strip service from the tab (the
            //     employee shouldn't pay a tip to their own colleagues).
            //   true            → leave service on; useful for chains
            //     that pool service across all sales for tip payouts.
            $includeService = (bool) Setting::get(
                'staff_meal_include_service',
                config('restaurant.staff_meals.include_service', false),
            );

            if (! $includeService && ((float) $order->service_rate > 0 || (float) $order->service_total > 0)) {
                $order->update(['service_rate' => 0]);
                app(OrderService::class)->recalculateTotals($order);
                $order->refresh();
            }

            $amount = (float) $order->total;
            $staff  = User::findOrFail($order->staff_consumer_user_id);

            // Gifts skip limit enforcement — they're free meals, not
            // debt, so they can't push the employee over a ceiling.
            // Regular charges run the full block/warn/approve gauntlet.
            $check = $isGift
                ? ['status' => 'gift', 'allowance' => ['over_by' => 0], 'ceiling' => ['over_by' => 0]]
                : $this->enforceLimit($staff, $amount, $approverUserId);

            $charge = StaffMealCharge::create([
                'branch_id'         => $order->branch_id,
                'user_id'           => $staff->id,
                'order_id'          => $order->id,
                'amount'            => $amount,
                'charged_at'        => now(),
                'settled_at'        => $isGift ? now() : null,
                'settled_by_user_id'=> $isGift ? ($settledByUserId ?? auth()->id()) : null,
                'settlement_method' => $isGift ? 'gift' : null,
                'notes'             => $isGift
                    ? trim('وجبة مجانية. '.($giftReason ?? ''))
                    : null,
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

            // Post the journal entry (DR expense, CR receivable). Failures
            // here are non-fatal — accounting can be reposted later, but
            // the operational charge must succeed so the kitchen records
            // stay consistent.
            $this->postChargeAccounting($charge);

            // For a gift, ALSO post the gift settlement leg so the
            // receivable nets to zero in one shot and 5060 carries
            // the giveaway expense for reporting.
            if ($isGift) {
                $this->postSettlementAccounting($charge);
            }

            // Threshold notifications only matter for actual debt-
            // bearing charges. Gifts don't count toward the employee's
            // usage of their monthly allowance.
            if (! $isGift) {
                $this->maybeNotifyThreshold($staff->fresh(), $charge);
            }

            ActivityLog::log(
                $isGift ? 'staff_meal.gifted' : 'staff_meal.charged',
                $isGift
                    ? "تم إعطاء طلب {$order->number} كوجبة مجانية للموظف {$staff->name}"
                        .($giftReason ? " (السبب: {$giftReason})" : '')
                    : "تم احتساب طلب {$order->number} على بدل الوجبات للموظف {$staff->name}",
                $charge,
                [
                    'amount'      => $amount,
                    'is_gift'     => $isGift,
                    'reason'      => $giftReason,
                    'remaining'   => $staff->fresh()->staffMealRemainingThisMonth(),
                    'outstanding' => $staff->fresh()->staffMealOutstanding(),
                    'over_limit'  => ($check['allowance']['over_by'] ?? 0) > 0
                                  || ($check['ceiling']['over_by']   ?? 0) > 0,
                    'policy_status' => $check['status'],
                    'approver'    => $approverUserId,
                ]
            );

            return $charge;
        });
    }

    // ───────────────────────────────────────────────────────────────
    // Per-charge waiver (forgive part/all of ONE specific charge)
    // ───────────────────────────────────────────────────────────────

    /**
     * Waive an amount from a specific OPEN charge — partial or full.
     * Different from `settle('writeoff')` in two ways:
     *
     *   1. Targets ONE charge instead of walking FIFO. The manager
     *      explicitly picks which order they're forgiving.
     *   2. Uses settlement_method='gift' (or 'writeoff' if `$asGift`
     *      is false) so the dashboard can separate "gestures of
     *      goodwill" from "bad debt write-offs" in reporting.
     *
     * Partial waivers split the row in two: the original keeps the
     * remaining balance, a new row carries the waived portion already
     * settled (preserving the audit trail and accounting symmetry).
     *
     * Re-running with the same `$amount` on an already-fully-settled
     * charge throws — managers should explicitly pick a different
     * open charge or use `settle()` for the cumulative path.
     */
    public function waiveCharge(StaffMealCharge $charge, float $amount, ?int $userId = null, ?string $reason = null, bool $asGift = true): StaffMealCharge
    {
        if ($amount <= 0.001) {
            throw new \InvalidArgumentException('قيمة الإعفاء يجب أن تكون أكبر من صفر.');
        }
        if ($charge->settled_at) {
            throw new \RuntimeException('هذه الحركة سُويت سابقاً — لا يمكن إعفاء قيمة منها مجدداً.');
        }
        $current = round((float) $charge->amount, 2);
        if ($amount - $current > 0.01) {
            throw new \RuntimeException(sprintf(
                'قيمة الإعفاء (%s) أكبر من قيمة الحركة (%s).',
                number_format($amount, 2),
                number_format($current, 2),
            ));
        }

        $method = $asGift ? 'gift' : 'writeoff';

        return DB::transaction(function () use ($charge, $amount, $userId, $reason, $method, $current) {
            // FULL waiver → just settle the existing row.
            if (abs($current - $amount) < 0.01) {
                $charge->update([
                    'settled_at'         => now(),
                    'settled_by_user_id' => $userId,
                    'settlement_method'  => $method,
                    'notes'              => trim(($charge->notes ?? '').' '.($reason ?? '')) ?: null,
                ]);
                $this->postSettlementAccounting($charge->fresh());
                $this->logWaiver($charge, $amount, $method, $reason);
                return $charge->fresh();
            }

            // PARTIAL waiver → split: original keeps (current - amount),
            // new row carries `amount` already settled.
            $waived = StaffMealCharge::create([
                'branch_id'         => $charge->branch_id,
                'user_id'           => $charge->user_id,
                'order_id'          => $charge->order_id,
                'amount'            => $amount,
                'charged_at'        => $charge->charged_at,
                'settled_at'        => now(),
                'settled_by_user_id'=> $userId,
                'settlement_method' => $method,
                'notes'             => trim('إعفاء جزئي. '.($reason ?? '')),
            ]);
            $charge->update(['amount' => round($current - $amount, 2)]);
            $this->postSettlementAccounting($waived);
            $this->logWaiver($waived, $amount, $method, $reason);
            return $waived;
        });
    }

    protected function logWaiver(StaffMealCharge $charge, float $amount, string $method, ?string $reason): void
    {
        $staff = $charge->user;
        $label = $method === 'gift' ? 'إعفاء/هدية' : 'شطب';
        ActivityLog::log(
            'staff_meal.waived',
            "{$label} بقيمة ".number_format($amount, 2)." من حركة #{$charge->id} للموظف {$staff?->name}",
            $charge,
            ['method' => $method, 'amount' => $amount, 'reason' => $reason],
        );
    }

    // ───────────────────────────────────────────────────────────────
    // Settle (FIFO)
    // ───────────────────────────────────────────────────────────────

    public function settle(User $staff, float $amount, string $method = 'cash', ?int $settledByUserId = null, ?string $notes = null, ?StaffMealMonthClosure $closure = null): array
    {
        if ($amount <= 0.001) {
            throw new \InvalidArgumentException('قيمة التسوية يجب أن تكون أكبر من صفر.');
        }

        if (! in_array($method, ['cash', 'payroll_deduction', 'writeoff', 'gift'], true)) {
            throw new \InvalidArgumentException('طريقة التسوية غير مدعومة: '.$method);
        }

        return DB::transaction(function () use ($staff, $amount, $method, $settledByUserId, $notes, $closure) {
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
                        'month_closure_id'   => $closure?->id,
                        'notes'              => trim(($charge->notes ?? '').' '.($notes ?? '')) ?: null,
                    ]);
                    $this->postSettlementAccounting($charge);
                    $remaining = round($remaining - $charged, 2);
                    $settled[] = $charge->id;
                    continue;
                }

                // Partial settlement: split the row in two so the audit
                // trail keeps the original order reference intact.
                $split = StaffMealCharge::create([
                    'branch_id'         => $charge->branch_id,
                    'user_id'           => $charge->user_id,
                    'order_id'          => $charge->order_id,
                    'amount'            => $remaining,
                    'charged_at'        => $charge->charged_at,
                    'settled_at'        => now(),
                    'settled_by_user_id'=> $settledByUserId,
                    'settlement_method' => $method,
                    'month_closure_id'  => $closure?->id,
                    'notes'             => trim('تسوية جزئية. '.($notes ?? '')),
                ]);
                $charge->update(['amount' => round($charged - $remaining, 2)]);
                $this->postSettlementAccounting($split);
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

    // ───────────────────────────────────────────────────────────────
    // Quick consume — same as before but routes through enforceLimit
    // ───────────────────────────────────────────────────────────────

    public function quickConsume(User $staff, array $lines, ?int $recordedByUserId = null, ?string $notes = null, ?int $approverUserId = null, bool $isGift = false, ?string $giftReason = null): StaffMealCharge
    {
        if (empty($lines)) {
            throw new \InvalidArgumentException('قائمة الأصناف فارغة.');
        }

        // Gifts can go to ANY employee (even one without a configured
        // allowance) since they're not against any cap. Regular tab
        // consumption still requires the allowance setup as a guard
        // against accidentally charging an employee who isn't enrolled.
        if (! $isGift && $staff->monthly_meal_allowance === null) {
            throw new \RuntimeException('هذا الموظف ليس له بدل وجبات مفعّل. عدّل ملفه أولاً.');
        }

        return DB::transaction(function () use ($staff, $lines, $recordedByUserId, $notes, $approverUserId, $isGift, $giftReason) {
            $branchId = BranchContext::current();

            // Stock guard — refuse to record consumption of items the
            // kitchen doesn't actually have.
            $inventory = app(InventoryService::class);
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
                'service_rate'           => 0,
            ]);

            $orders = app(OrderService::class);

            foreach ($lines as $line) {
                $oi = $orders->addItem($order, [
                    'menu_item_id' => (int) $line['menu_item_id'],
                    'quantity'     => (float) $line['quantity'],
                    'modifier_ids' => [],
                    'notes'        => $line['notes'] ?? null,
                ]);
                $oi->update([
                    'status'      => OrderItemStatus::Served->value,
                    'approved_at' => now(),
                    'served_at'   => now(),
                ]);
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

            return $this->chargeOrder($order->fresh(), $recordedByUserId, $approverUserId, $isGift, $giftReason);
        });
    }

    // ───────────────────────────────────────────────────────────────
    // Month-end close → batch settle + printable report
    // ───────────────────────────────────────────────────────────────

    /**
     * Close a month's tab for every employee with open charges.
     * Settles each open charge in the given month using `$method`
     * (default `payroll_deduction`), creates a single
     * `StaffMealMonthClosure` row, links every settled charge to it,
     * and returns the closure for the printable report.
     *
     * Idempotent: re-running for the same (branch, month) returns the
     * existing closure unchanged — important for accountants who
     * re-print the sheet after the books are closed.
     *
     * @param int|null $branchId  Null = close across all branches at once
     *                            (only the owner-level dashboard does this).
     */
    public function closeMonth(\Carbon\Carbon $month, ?int $branchId, string $method = 'payroll_deduction', ?int $closedByUserId = null, ?string $notes = null): StaffMealMonthClosure
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd   = $month->copy()->endOfMonth();

        // Idempotency: one closure per (branch, month).
        $existing = StaffMealMonthClosure::where('branch_id', $branchId)
            ->whereDate('month', $monthStart->toDateString())
            ->first();
        if ($existing) return $existing;

        return DB::transaction(function () use ($monthStart, $monthEnd, $branchId, $method, $closedByUserId, $notes) {
            // Collect every employee with open charges in this month.
            $openQuery = StaffMealCharge::query()
                ->whereNull('settled_at')
                ->whereBetween('charged_at', [$monthStart, $monthEnd]);
            if ($branchId !== null) {
                $openQuery->where('branch_id', $branchId);
            }
            $openCharges = $openQuery->lockForUpdate()->get();

            if ($openCharges->isEmpty()) {
                // Still record the closure so the dashboard shows
                // "month X closed (no charges)" rather than letting a
                // manager re-click and wonder if anything happened.
                return StaffMealMonthClosure::create([
                    'branch_id'         => $branchId,
                    'month'             => $monthStart->toDateString(),
                    'method'            => $method,
                    'total_amount'      => 0,
                    'staff_count'       => 0,
                    'charge_count'      => 0,
                    'closed_by_user_id' => $closedByUserId,
                    'closed_at'         => now(),
                    'notes'             => $notes,
                ]);
            }

            $total      = round((float) $openCharges->sum('amount'), 2);
            $staffCount = $openCharges->pluck('user_id')->unique()->count();
            $count      = $openCharges->count();

            $closure = StaffMealMonthClosure::create([
                'branch_id'         => $branchId,
                'month'             => $monthStart->toDateString(),
                'method'            => $method,
                'total_amount'      => $total,
                'staff_count'       => $staffCount,
                'charge_count'      => $count,
                'closed_by_user_id' => $closedByUserId,
                'closed_at'         => now(),
                'notes'             => $notes,
            ]);

            // Settle every open charge in one shot. We call settle()
            // per employee instead of bulk-updating because:
            //   1. settle() handles the FIFO + accounting side effects
            //   2. ActivityLog gets per-employee rows for traceability
            $byUser = $openCharges->groupBy('user_id');
            foreach ($byUser as $userId => $charges) {
                $staff = User::find($userId);
                if (! $staff) continue;
                $sum = round((float) $charges->sum('amount'), 2);
                $this->settle(
                    staff:           $staff,
                    amount:          $sum,
                    method:          $method,
                    settledByUserId: $closedByUserId,
                    notes:           "إقفال شهري {$monthStart->format('Y-m')} #".$closure->id,
                    closure:         $closure,
                );
            }

            ActivityLog::log(
                'staff_meal.month_closed',
                "إقفال شهر {$monthStart->format('Y-m')} لبدل وجبات الموظفين — {$count} حركة بقيمة ".number_format($total, 2),
                $closure,
                ['method' => $method, 'branch_id' => $branchId, 'staff_count' => $staffCount],
            );

            return $closure->fresh();
        });
    }

    // ───────────────────────────────────────────────────────────────
    // Reporting
    // ───────────────────────────────────────────────────────────────

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

        // Gifts/waivers given in the month — value of meals the
        // employee got "on the house" that DIDN'T touch their tab.
        // Tracked separately so management can see giveaways vs.
        // actual consumption at a glance.
        $giftedThisMonth = (float) StaffMealCharge::query()
            ->where('user_id', $staff->id)
            ->where('settlement_method', 'gift')
            ->whereBetween('charged_at', [$month, $end])
            ->sum('amount');

        $outstanding = $staff->staffMealOutstanding();
        $overflow    = $allowance !== null ? max(0, $usedThisMonth - $allowance) : 0;

        return [
            'month'         => $month->format('Y-m'),
            'allowance'     => $allowance,
            'used'          => round($usedThisMonth, 2),
            'gifted'        => round($giftedThisMonth, 2),
            'remaining'     => $allowance !== null ? round($allowance - $usedThisMonth, 2) : null,
            'overflow'      => round($overflow, 2),
            'outstanding'   => round($outstanding, 2),
            'ceiling'       => $staff->meal_debt_ceiling !== null ? (float) $staff->meal_debt_ceiling : null,
            'ceiling_headroom' => $staff->staffMealCeilingHeadroom(),
            'usage_pct'     => $staff->staffMealUsagePct(),
            'charge_count'  => StaffMealCharge::where('user_id', $staff->id)
                                ->whereNull('settled_at')
                                ->whereBetween('charged_at', [$month, $end])
                                ->count(),
        ];
    }

    /**
     * Per-employee aggregate for the printable payroll sheet of a
     * specific closure. Returns one row per employee:
     *   - name, monthly_meal_allowance, used, overflow, charges_count
     */
    public function payrollSheet(StaffMealMonthClosure $closure): \Illuminate\Support\Collection
    {
        return StaffMealCharge::query()
            ->where('month_closure_id', $closure->id)
            ->with('user:id,name,role,monthly_meal_allowance')
            ->get()
            ->groupBy('user_id')
            ->map(function ($charges) {
                $user = $charges->first()->user;
                $total = round((float) $charges->sum('amount'), 2);
                $cap   = $user?->monthly_meal_allowance !== null ? (float) $user->monthly_meal_allowance : null;
                return [
                    'user'           => $user,
                    'total'          => $total,
                    'allowance'      => $cap,
                    'overflow'       => $cap !== null ? max(0, round($total - $cap, 2)) : 0,
                    'charges_count'  => $charges->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    // ───────────────────────────────────────────────────────────────
    // Accounting integration (best-effort — never blocks operations)
    // ───────────────────────────────────────────────────────────────

    /**
     * Post the journal entry when a staff meal is charged. Standard
     * double-entry: a receivable is created and recognized as internal
     * meal revenue.
     *
     *   DR 1110 مستحقات على الموظفين (retail amount)
     *   CR 4030 إيرادات بدل وجبات الموظفين (retail amount)
     *
     * Why 4030 (revenue) and not 5050 (expense)?
     *   - The food cost is ALREADY in 5000 COGS via inventory deduction.
     *   - Charging the meal at retail creates a real receivable, mirrored
     *     by recognizing the corresponding retail value as internal
     *     revenue — the SAME pattern a customer invoice uses (DR 1100 /
     *     CR 4000).
     *   - When the employee pays back (cash / payroll), 4030 stays at
     *     retail and 1110 is cleared. Net P&L = retail - COGS = margin.
     *   - For a gift, the receivable is cleared into 5060 perks expense.
     *     Net P&L = 5060 (retail) + 5000 (cogs) - 4030 (retail) = COGS.
     *     The "loss" is just the food cost, not a phantom amount.
     *
     * Safe against missing chart of accounts (returns null silently
     * and logs a notice). Idempotent per charge via the event_type +
     * source uniqueness inside AccountingService::post.
     */
    protected function postChargeAccounting(StaffMealCharge $charge): void
    {
        $accounting = $this->accounting ?? app(AccountingService::class);

        try {
            $accounting->post(
                eventType:   'staff_meal_charged',
                source:      $charge,
                branchId:    $charge->branch_id,
                postedOn:    $charge->charged_at,
                description: "بدل وجبات الموظف #{$charge->user_id} — مرجع طلب #{$charge->order_id}",
                lines: [
                    ['account' => '1110', 'debit' => (float) $charge->amount, 'credit' => 0, 'description' => 'استحقاق على الموظف'],
                    ['account' => '4030', 'debit' => 0, 'credit' => (float) $charge->amount, 'description' => 'إيراد وجبة موظف بالقيمة الاسمية'],
                ],
                createdBy: $charge->settled_by_user_id,
            );
        } catch (\Throwable $e) {
            \Log::warning('staff_meal.accounting.charge_failed', [
                'charge_id' => $charge->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Post the settlement journal entry. Method decides where the
     * receivable clears to:
     *   cash               → DR 1000 Cash       , CR 1110 Receivable
     *   payroll_deduction  → DR 2110 Payroll    , CR 1110 Receivable
     *   writeoff           → DR 5200 Bad Debt   , CR 1110 Receivable
     *
     * Event type embeds the method so re-settling under a different
     * method (rare but possible during reconciliation) doesn't
     * collide with the idempotency guard.
     */
    protected function postSettlementAccounting(StaffMealCharge $charge): void
    {
        if (! $charge->settled_at) return;
        $accounting = $this->accounting ?? app(AccountingService::class);

        $debitAccount = match ($charge->settlement_method) {
            'cash'              => '1000',
            'payroll_deduction' => '2110',
            'writeoff'          => '5200',
            'gift'              => '5060',
            default             => '1000',
        };
        $description = match ($charge->settlement_method) {
            'cash'              => 'تسوية نقدية لبدل وجبات الموظف',
            'payroll_deduction' => 'خصم من راتب الموظف لبدل الوجبات',
            'writeoff'          => 'إعدام مستحق بدل وجبات (تنازل إداري)',
            'gift'              => 'وجبة مجانية/هدية للموظف',
            default             => 'تسوية بدل وجبات الموظف',
        };

        try {
            $accounting->post(
                eventType:   'staff_meal_settled_'.$charge->settlement_method,
                source:      $charge,
                branchId:    $charge->branch_id,
                postedOn:    $charge->settled_at,
                description: $description,
                lines: [
                    ['account' => $debitAccount, 'debit' => (float) $charge->amount, 'credit' => 0, 'description' => $description],
                    ['account' => '1110',         'debit' => 0, 'credit' => (float) $charge->amount, 'description' => 'تسوية استحقاق على الموظف'],
                ],
                createdBy: $charge->settled_by_user_id,
            );
        } catch (\Throwable $e) {
            \Log::warning('staff_meal.accounting.settle_failed', [
                'charge_id' => $charge->id,
                'method'    => $charge->settlement_method,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ───────────────────────────────────────────────────────────────
    // Threshold notifications
    // ───────────────────────────────────────────────────────────────

    /**
     * Log an alert when the employee crosses 80%, 100%, or 120% of
     * their monthly allowance. ActivityLog is the destination — the
     * dashboard pulls warnings from there and surfaces them as
     * badges/banners. We dedupe per (user, threshold, month) so the
     * 4th charge doesn't spam four 80%-alerts.
     */
    protected function maybeNotifyThreshold(User $staff, StaffMealCharge $charge): void
    {
        if ($staff->monthly_meal_allowance === null) return;
        $pct = $staff->staffMealUsagePct();
        if ($pct === null) return;

        $threshold = match (true) {
            $pct >= 120 => 120,
            $pct >= 100 => 100,
            $pct >= 80  => 80,
            default     => null,
        };
        if ($threshold === null) return;

        // Dedupe: did we already fire THIS threshold for THIS user in
        // THIS month? ActivityLog uses metadata.threshold for the key.
        $month = now()->format('Y-m');
        $already = ActivityLog::query()
            ->where('event', 'staff_meal.threshold')
            ->where('subject_type', User::class)
            ->where('subject_id', $staff->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->get()
            ->contains(fn ($log) => (int) ($log->properties['threshold'] ?? 0) >= $threshold);
        if ($already) return;

        $severity = $threshold === 80 ? 'تنبيه' : ($threshold === 100 ? 'تحذير' : 'تجاوز');
        ActivityLog::log(
            'staff_meal.threshold',
            "{$severity}: الموظف {$staff->name} وصل إلى {$pct}% من بدله الشهري (شهر {$month}).",
            $staff,
            [
                'threshold' => $threshold,
                'pct'       => $pct,
                'used'      => round($staff->staffMealUsedInMonth(), 2),
                'allowance' => (float) $staff->monthly_meal_allowance,
                'charge_id' => $charge->id,
            ],
        );
    }
}
