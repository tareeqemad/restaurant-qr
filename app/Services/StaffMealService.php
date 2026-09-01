<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Exceptions\StaffMealLimitException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Setting;
use App\Models\StaffMealCharge;
use App\Models\StaffMealMonthClosure;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff meal allowance — runs every employee's monthly tab.
 *
 * Lifecycle of a staff order:
 *   1. Waiter creates an order with `staff_consumer_employee_id` set (no
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
    public const POLICY_ALLOW_LOG = 'allow_log';

    public const POLICY_WARN = 'warn';

    public const POLICY_REQUIRE_APPROVAL = 'require_approval';

    public const POLICY_BLOCK = 'block';

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
    public function previewLimitCheck(Employee|User $staff, float $amount, ?float $alreadyOpenAmount = 0.0): array
    {
        $staff = $this->employee($staff);
        $policy = (string) Setting::get('staff_meal_over_limit_policy', self::POLICY_WARN);

        // Editing an already-open order must check only the net increase,
        // otherwise the same cart value is counted twice by the preview.
        $netAmount = round(max(0, $amount - max(0, (float) $alreadyOpenAmount)), 2);

        $allowanceCap = $staff->monthly_meal_allowance !== null ? (float) $staff->monthly_meal_allowance : null;
        $allowanceUsed = $staff->staffMealUsedInMonth();
        $allowanceRemaining = $allowanceCap !== null ? round($allowanceCap - $allowanceUsed, 2) : null;
        $employeeDue = $allowanceRemaining !== null
            ? round(max(0, $netAmount - max(0, $allowanceRemaining)), 2)
            : $netAmount;
        $allowanceOver = $employeeDue;

        $ceilingCap = $staff->meal_debt_ceiling !== null ? (float) $staff->meal_debt_ceiling : null;
        $ceilingOutstanding = $staff->staffMealOutstanding();
        $ceilingHeadroom = $ceilingCap !== null ? round($ceilingCap - $ceilingOutstanding, 2) : null;
        $ceilingOver = $ceilingHeadroom !== null ? max(0, $employeeDue - $ceilingHeadroom) : 0;

        // The hard ceiling decides block-eligibility; the soft monthly
        // allowance only ever warns (it's expected to overflow into the
        // employee's running tab — that's literally the design).
        $hardOver = $ceilingOver > 0.01;
        $softOver = $allowanceOver > 0.01;

        $status = 'ok';
        $reason = null;

        if ($hardOver) {
            $status = match ($policy) {
                self::POLICY_BLOCK => 'blocked',
                self::POLICY_REQUIRE_APPROVAL => 'requires_approval',
                self::POLICY_WARN => 'warn',
                default => 'warn',
            };
            $reason = sprintf(
                'الموظف يتجاوز سقف الدين المسموح بـ %s. (الدين القائم: %s | السقف: %s)',
                number_format($ceilingOver, 2),
                number_format($ceilingOutstanding + $employeeDue, 2),
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
            'status' => $status,
            'reason' => $reason,
            'policy' => $policy,
            'allowance' => [
                'cap' => $allowanceCap,
                'used' => round($allowanceUsed, 2),
                'remaining' => $allowanceRemaining,
                'over_by' => round($allowanceOver, 2),
                'covered_by_restaurant' => round($netAmount - $employeeDue, 2),
                'employee_due' => $employeeDue,
            ],
            'ceiling' => [
                'cap' => $ceilingCap,
                'outstanding' => round($ceilingOutstanding, 2),
                'headroom' => $ceilingHeadroom,
                'over_by' => round($ceilingOver, 2),
            ],
        ];
    }

    /**
     * Enforce the policy by throwing when blocked. The two "soft"
     * outcomes (warn / requires_approval) just log to ActivityLog and
     * proceed — the manager-PIN flow happens at the UI layer.
     */
    protected function enforceLimit(Employee $staff, float $amount, ?int $approverUserId = null): array
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

        $approver = $approverUserId ? User::find($approverUserId) : null;
        $validApprover = $approver
            && ($approver->isManagementLevel() || $approver->hasPermission('staff_meals.waive'));

        if ($check['status'] === 'requires_approval' && ! $validApprover) {
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
     * @param  int|null  $approverUserId  Manager who approved the over-limit
     *                                    charge (only needed when the active
     *                                    policy is `require_approval`).
     */
    /**
     * @param  bool  $isGift  When true, the order is recorded but settled
     *                        immediately as method='gift' — the kitchen
     *                        still cooked, inventory still deducted, but
     *                        NOTHING lands on the employee's tab. Used
     *                        for birthday meals, employee-of-the-month
     *                        rewards, retention gifts. Limit checks are
     *                        skipped — a gift can't push the employee
     *                        over their ceiling because it's not debt.
     * @param  string|null  $giftReason  Free-text reason stored on the
     *                                   charge.notes for the audit trail.
     */
    public function chargeOrder(Order $order, ?int $settledByUserId = null, ?int $approverUserId = null, bool $isGift = false, ?string $giftReason = null): StaffMealCharge
    {
        if (! $order->staff_consumer_employee_id && ! $order->staff_consumer_user_id) {
            throw new \InvalidArgumentException('هذا الطلب غير معرَّف كطلب موظف.');
        }

        if ($order->status === OrderStatus::Cancelled->value) {
            throw new \RuntimeException('لا يمكن احتساب طلب ملغى على بدل الوجبات.');
        }

        // Idempotency: every staff order yields exactly one charge —
        // re-running just returns the existing row. Check OUTSIDE the
        // transaction so we don't enforce limits on a re-run.
        $existing = StaffMealCharge::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $settledByUserId, $approverUserId, $isGift, $giftReason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = StaffMealCharge::where('order_id', $order->id)->first();
            if ($existing) {
                return $existing;
            }

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
                // Log the strip so a manager auditing the order later can
                // explain the difference between the cashier-displayed
                // total (with service) and the eventual tab amount.
                // Without this, an order would silently drop, say, 11 ש"ח
                // of service charge and nobody could tell why.
                $strippedAmount = (float) $order->service_total;
                $strippedRate = (float) $order->service_rate;
                $order->update(['service_rate' => 0]);
                app(OrderService::class)->recalculateTotals($order);
                $order->refresh();

                ActivityLog::log(
                    'staff_meal.service_stripped',
                    "تم خصم رسوم الخدمة ({$strippedRate}% = "
                        .number_format($strippedAmount, 2).' ش.إ) '
                        ."من طلب الموظف {$order->number} قبل احتسابه على البدل",
                    $order,
                    [
                        'service_rate_before' => $strippedRate,
                        'service_total_before' => $strippedAmount,
                        'reason' => 'staff_meal_include_service=false',
                    ],
                );
            }

            $consumptionAmount = round((float) $order->total, 2);
            $staff = $order->staff_consumer_employee_id
                ? Employee::findOrFail($order->staff_consumer_employee_id)
                : $this->employee(User::findOrFail($order->staff_consumer_user_id));
            if (! $order->staff_consumer_employee_id) {
                $order->update(['staff_consumer_employee_id' => $staff->id]);
            }

            // Gifts skip limit enforcement — they're free meals, not
            // debt, so they can't push the employee over a ceiling.
            // Regular charges run the full block/warn/approve gauntlet.
            $check = $isGift
                ? ['status' => 'gift', 'allowance' => ['over_by' => 0, 'employee_due' => 0, 'covered_by_restaurant' => $consumptionAmount], 'ceiling' => ['over_by' => 0]]
                : $this->enforceLimit($staff, $consumptionAmount, $approverUserId);

            // The monthly allowance is a restaurant-paid employee benefit.
            // Only its excess is a receivable from the employee. The nominal
            // consumption remains frozen on the linked order for reporting.
            $employeeDue = $isGift ? 0.0 : round((float) ($check['allowance']['employee_due'] ?? 0), 2);
            $coveredByRestaurant = round($consumptionAmount - $employeeDue, 2);
            $automaticallyCovered = $employeeDue <= 0.001;

            $charge = StaffMealCharge::create([
                'branch_id' => $order->branch_id,
                'employee_id' => $staff->id,
                'user_id' => $staff->user_id,
                'order_id' => $order->id,
                'amount' => $employeeDue,
                'charged_at' => now(),
                'settled_at' => $automaticallyCovered ? now() : null,
                'settled_by_user_id' => $automaticallyCovered ? ($settledByUserId ?? auth()->id()) : null,
                'settlement_method' => $isGift ? 'gift' : ($automaticallyCovered ? 'allowance' : null),
                'notes' => $isGift
                    ? trim('وجبة مجانية. '.($giftReason ?? ''))
                    : ($coveredByRestaurant > 0
                        ? 'يغطي المطعم '.number_format($coveredByRestaurant, 2).' من قيمة الوجبة ضمن البدل الشهري.'
                        : null),
            ]);

            // Only excess above the allowance becomes a receivable/recovery.
            // The physical ingredient cost is posted by InventoryService.
            if ($employeeDue > 0.001) {
                $this->postChargeAccounting($charge);
            }

            // Gifts are reported separately and never create a receivable.
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
                    'consumption_amount' => $consumptionAmount,
                    'covered_by_restaurant' => $coveredByRestaurant,
                    'employee_due' => $employeeDue,
                    'is_gift' => $isGift,
                    'reason' => $giftReason,
                    'remaining' => $staff->fresh()->staffMealRemainingThisMonth(),
                    'outstanding' => $staff->fresh()->staffMealOutstanding(),
                    'over_limit' => ($check['allowance']['over_by'] ?? 0) > 0
                                  || ($check['ceiling']['over_by'] ?? 0) > 0,
                    'policy_status' => $check['status'],
                    'approver' => $approverUserId,
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
                    'settled_at' => now(),
                    'settled_by_user_id' => $userId,
                    'settlement_method' => $method,
                    'notes' => trim(($charge->notes ?? '').' '.($reason ?? '')) ?: null,
                ]);
                $this->postSettlementAccounting($charge->fresh());
                $this->logWaiver($charge, $amount, $method, $reason);

                return $charge->fresh();
            }

            // PARTIAL waiver → split: original keeps (current - amount),
            // new row carries `amount` already settled.
            $waived = StaffMealCharge::create([
                'branch_id' => $charge->branch_id,
                'employee_id' => $charge->employee_id,
                'user_id' => $charge->user_id,
                'order_id' => $charge->order_id,
                'amount' => $amount,
                'charged_at' => $charge->charged_at,
                'settled_at' => now(),
                'settled_by_user_id' => $userId,
                'settlement_method' => $method,
                'notes' => trim('إعفاء جزئي. '.($reason ?? '')),
            ]);
            $charge->update(['amount' => round($current - $amount, 2)]);
            $this->postSettlementAccounting($waived);
            $this->logWaiver($waived, $amount, $method, $reason);

            return $waived;
        });
    }

    protected function logWaiver(StaffMealCharge $charge, float $amount, string $method, ?string $reason): void
    {
        $staff = $charge->employee;
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

    public function settle(Employee|User $staff, float $amount, string $method = 'cash', ?int $settledByUserId = null, ?string $notes = null, ?StaffMealMonthClosure $closure = null, ?array $chargeIds = null): array
    {
        $staff = $this->employee($staff);
        if ($amount <= 0.001) {
            throw new \InvalidArgumentException('قيمة التسوية يجب أن تكون أكبر من صفر.');
        }

        if (! in_array($method, ['cash', 'payroll_deduction', 'writeoff', 'gift'], true)) {
            throw new \InvalidArgumentException('طريقة التسوية غير مدعومة: '.$method);
        }

        return DB::transaction(function () use ($staff, $amount, $method, $settledByUserId, $notes, $closure, $chargeIds) {
            $chargesQuery = StaffMealCharge::where('employee_id', $staff->id)
                ->whereNull('settled_at')
                ->when($chargeIds !== null, fn ($query) => $query->whereIn('id', $chargeIds))
                ->orderBy('charged_at')
                ->lockForUpdate();
            $charges = $chargesQuery->get();
            $outstanding = round((float) $charges->sum('amount'), 2);

            if ($amount - $outstanding > 0.01) {
                throw new \RuntimeException(sprintf(
                    'قيمة التسوية (%s) أكبر من إجمالي المستحق (%s) للموظف %s.',
                    number_format($amount, 2),
                    number_format($outstanding, 2),
                    $staff->name,
                ));
            }

            $remaining = round($amount, 2);
            $settled = [];

            foreach ($charges as $charge) {
                if ($remaining <= 0.001) {
                    break;
                }

                $charged = round((float) $charge->amount, 2);
                if ($remaining + 0.001 >= $charged) {
                    // Whole charge fits → settle it fully.
                    $charge->update([
                        'settled_at' => now(),
                        'settled_by_user_id' => $settledByUserId,
                        'settlement_method' => $method,
                        'month_closure_id' => $closure?->id,
                        'notes' => trim(($charge->notes ?? '').' '.($notes ?? '')) ?: null,
                    ]);
                    $this->postSettlementAccounting($charge);
                    $remaining = round($remaining - $charged, 2);
                    $settled[] = $charge->id;

                    continue;
                }

                // Partial settlement: split the row in two so the audit
                // trail keeps the original order reference intact.
                $split = StaffMealCharge::create([
                    'branch_id' => $charge->branch_id,
                    'employee_id' => $charge->employee_id,
                    'user_id' => $charge->user_id,
                    'order_id' => $charge->order_id,
                    'amount' => $remaining,
                    'charged_at' => $charge->charged_at,
                    'settled_at' => now(),
                    'settled_by_user_id' => $settledByUserId,
                    'settlement_method' => $method,
                    'month_closure_id' => $closure?->id,
                    'notes' => trim('تسوية جزئية. '.($notes ?? '')),
                ]);
                $charge->update(['amount' => round($charged - $remaining, 2)]);
                $this->postSettlementAccounting($split);
                $settled[] = $split->id;
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

    public function quickConsume(Employee|User $staff, array $lines, ?int $recordedByUserId = null, ?string $notes = null, ?int $approverUserId = null, bool $isGift = false, ?string $giftReason = null): StaffMealCharge
    {
        $staff = $this->employee($staff);
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
                'quantity' => (float) $l['quantity'],
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
                'branch_id' => $branchId,
                'order_type' => 'takeaway',
                'order_source' => 'other',
                'status' => OrderStatus::Pending->value,
                'staff_consumer_employee_id' => $staff->id,
                'staff_consumer_user_id' => $staff->user_id,
                'created_by_user_id' => $recordedByUserId ?? auth()->id(),
                'submitted_at' => now(),
                'customer_notes' => $notes,
                'tax_rate' => 0,
                'service_rate' => 0,
            ]);

            $orders = app(OrderService::class);

            foreach ($lines as $line) {
                $oi = $orders->addItem($order, [
                    'menu_item_id' => (int) $line['menu_item_id'],
                    'quantity' => (float) $line['quantity'],
                    'modifier_ids' => [],
                    'notes' => $line['notes'] ?? null,
                ]);
                $oi->update([
                    'status' => OrderItemStatus::Served->value,
                    'approved_at' => now(),
                    'served_at' => now(),
                ]);
                $inventory->ensureDeducted($oi->fresh());
            }

            $orders->recalculateTotals($order);

            $order->update([
                'status' => OrderStatus::Completed->value,
                'approved_at' => now(),
                'approved_by_user_id' => $recordedByUserId ?? auth()->id(),
                'ready_at' => now(),
                'delivered_at' => now(),
                'completed_at' => now(),
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
     * @param  int|null  $branchId  Null = close across all branches at once
     *                              (only the owner-level dashboard does this).
     */
    public function closeMonth(Carbon $month, ?int $branchId, string $method = 'payroll_deduction', ?int $closedByUserId = null, ?string $notes = null): StaffMealMonthClosure
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        // Idempotency: one closure per (branch, month).
        $existing = StaffMealMonthClosure::where('branch_id', $branchId)
            ->whereDate('month', $monthStart->toDateString())
            ->first();
        if ($existing) {
            return $existing;
        }

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
                    'branch_id' => $branchId,
                    'month' => $monthStart->toDateString(),
                    'method' => $method,
                    'total_amount' => 0,
                    'staff_count' => 0,
                    'charge_count' => 0,
                    'closed_by_user_id' => $closedByUserId,
                    'closed_at' => now(),
                    'notes' => $notes,
                ]);
            }

            $total = round((float) $openCharges->sum('amount'), 2);
            $staffCount = $openCharges->pluck('employee_id')->unique()->count();
            $count = $openCharges->count();

            $closure = StaffMealMonthClosure::create([
                'branch_id' => $branchId,
                'month' => $monthStart->toDateString(),
                'method' => $method,
                'total_amount' => $total,
                'staff_count' => $staffCount,
                'charge_count' => $count,
                'closed_by_user_id' => $closedByUserId,
                'closed_at' => now(),
                'notes' => $notes,
            ]);

            // Settle every open charge in one shot. We call settle()
            // per employee instead of bulk-updating because:
            //   1. settle() handles the FIFO + accounting side effects
            //   2. ActivityLog gets per-employee rows for traceability
            $byEmployee = $openCharges->groupBy('employee_id');
            foreach ($byEmployee as $employeeId => $charges) {
                $staff = Employee::find($employeeId);
                if (! $staff) {
                    continue;
                }
                $sum = round((float) $charges->sum('amount'), 2);
                $this->settle(
                    staff: $staff,
                    amount: $sum,
                    method: $method,
                    settledByUserId: $closedByUserId,
                    notes: "إقفال شهري {$monthStart->format('Y-m')} #".$closure->id,
                    closure: $closure,
                    chargeIds: $charges->pluck('id')->all(),
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

    public function monthSummary(Employee|User $staff, ?Carbon $month = null, ?int $branchId = null): array
    {
        $staff = $this->employee($staff);
        $month = $month?->copy()->startOfMonth() ?? now()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $allowance = $staff->monthly_meal_allowance !== null
            ? (float) $staff->monthly_meal_allowance
            : null;

        $monthCharges = StaffMealCharge::query()
            ->where('employee_id', $staff->id)
            ->whereBetween('charged_at', [$month, $end]);
        if ($branchId !== null) {
            $monthCharges->where('branch_id', $branchId);
        }

        $monthCharges = $monthCharges->with('order:id,total')->get();
        $orderGroups = $monthCharges->groupBy(
            fn (StaffMealCharge $charge) => $charge->order_id ? 'order:'.$charge->order_id : 'charge:'.$charge->id
        );
        $regularGroups = $orderGroups->reject(function (Collection $charges) {
            $createdAsGift = $charges->every(
                fn (StaffMealCharge $charge) => $charge->settlement_method === 'gift'
            ) && (float) $charges->sum('amount') <= 0.001;

            return $createdAsGift;
        });
        $usedThisMonth = (float) $regularGroups->sum(function (Collection $charges) {
            $first = $charges->first();

            return (float) ($first->order?->total ?? $charges->sum('amount'));
        });
        $employeeDueThisMonth = (float) $regularGroups->sum(
            fn (Collection $charges) => (float) $charges->sum('amount')
        );
        $coveredThisMonth = max(0, $usedThisMonth - $employeeDueThisMonth);

        // Gifts/waivers given in the month — value of meals the
        // employee got "on the house" that DIDN'T touch their tab.
        // Tracked separately so management can see giveaways vs.
        // actual consumption at a glance.
        $giftedThisMonth = (float) $monthCharges
            ->where('settlement_method', 'gift')
            ->sum(fn (StaffMealCharge $charge) => (float) $charge->amount > 0
                ? (float) $charge->amount
                : (float) ($charge->order?->total ?? 0));

        $outstandingQuery = StaffMealCharge::query()
            ->where('employee_id', $staff->id)
            ->whereNull('settled_at');
        if ($branchId !== null) {
            $outstandingQuery->where('branch_id', $branchId);
        }
        $outstanding = (float) $outstandingQuery->sum('amount');
        $overflow = $employeeDueThisMonth;
        $usagePct = $allowance !== null && $allowance > 0
            ? round($usedThisMonth / $allowance * 100, 1)
            : null;
        $ceiling = $staff->meal_debt_ceiling !== null ? (float) $staff->meal_debt_ceiling : null;

        return [
            'month' => $month->format('Y-m'),
            'allowance' => $allowance,
            'used' => round($usedThisMonth, 2),
            'covered' => round($coveredThisMonth, 2),
            'gifted' => round($giftedThisMonth, 2),
            'remaining' => $allowance !== null ? round($allowance - $usedThisMonth, 2) : null,
            'overflow' => round($overflow, 2),
            'outstanding' => round($outstanding, 2),
            'ceiling' => $ceiling,
            'ceiling_headroom' => $ceiling !== null ? round($ceiling - $outstanding, 2) : null,
            'usage_pct' => $usagePct,
            'charge_count' => $regularGroups->count(),
        ];
    }

    /**
     * Per-employee aggregate for the printable payroll sheet of a
     * specific closure. Returns one row per employee:
     *   - name, monthly_meal_allowance, used, overflow, charges_count
     */
    public function payrollSheet(StaffMealMonthClosure $closure): Collection
    {
        return StaffMealCharge::query()
            ->where('month_closure_id', $closure->id)
            ->with(['employee:id,user_id,name,job_title,monthly_meal_allowance', 'employee.user:id,name,role', 'order:id,total'])
            ->get()
            ->groupBy('employee_id')
            ->map(function ($charges) {
                $employee = $charges->first()->employee;
                $total = round((float) $charges->sum('amount'), 2);
                $consumption = round((float) $charges->whereNotNull('order_id')
                    ->unique('order_id')
                    ->sum(fn (StaffMealCharge $charge) => (float) ($charge->order?->total ?? $charge->amount)), 2);
                $cap = $employee?->monthly_meal_allowance !== null ? (float) $employee->monthly_meal_allowance : null;

                return [
                    'employee' => $employee,
                    'user' => $employee?->user,
                    'total' => $total,
                    'consumption' => $consumption,
                    'covered' => max(0, round($consumption - $total, 2)),
                    'allowance' => $cap,
                    'overflow' => $total,
                    'charges_count' => $charges->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    // ───────────────────────────────────────────────────────────────
    // Accounting integration (best-effort — never blocks operations)
    // ───────────────────────────────────────────────────────────────

    /**
     * Recognize only the portion due from the employee:
     *   DR 1110 staff receivable
     *   CR 4030 staff-meal recovery
     *
     * The restaurant-funded portion is not a retail sale. Its actual
     * ingredient cost is posted from the inventory movement to 5060.
     */
    protected function postChargeAccounting(StaffMealCharge $charge): void
    {
        $accounting = $this->accounting ?? app(AccountingService::class);

        try {
            $receivable = $accounting->accountForPostingRole('staff_meal_receivable');
            $recoveryRevenue = $accounting->accountForPostingRole('staff_meal_recovery_revenue');
            $accounting->post(
                eventType: 'staff_meal_charged',
                source: $charge,
                branchId: $charge->branch_id,
                postedOn: $charge->charged_at,
                description: "بدل وجبات الموظف #{$charge->employee_id} — مرجع طلب #{$charge->order_id}",
                lines: [
                    ['account' => $receivable, 'debit' => (float) $charge->amount, 'credit' => 0, 'description' => 'الجزء المتجاوز من بدل الموظف'],
                    ['account' => $recoveryRevenue, 'debit' => 0, 'credit' => (float) $charge->amount, 'description' => 'استرداد الجزء المتجاوز من بدل الوجبة'],
                ],
                createdBy: $charge->settled_by_user_id,
            );
        } catch (\Throwable $e) {
            \Log::warning('staff_meal.accounting.charge_failed', [
                'charge_id' => $charge->id,
                'error' => $e->getMessage(),
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
        if (! $charge->settled_at) {
            return;
        }
        $accounting = $this->accounting ?? app(AccountingService::class);

        $debitAccount = match ($charge->settlement_method) {
            'cash' => $accounting->accountForPostingRole('cash_account'),
            'payroll_deduction' => $accounting->accountForPostingRole('payroll_deductions'),
            'writeoff' => $accounting->accountForPostingRole('bad_debt_expense'),
            // A voluntary waiver reverses the recovery revenue. The actual
            // ingredient cost was already recorded as employee-benefit expense.
            'gift' => $accounting->accountForPostingRole('staff_meal_recovery_revenue'),
            default => $accounting->accountForPostingRole('cash_account'),
        };
        $description = match ($charge->settlement_method) {
            'cash' => 'تسوية نقدية لبدل وجبات الموظف',
            'payroll_deduction' => 'خصم من راتب الموظف لبدل الوجبات',
            'writeoff' => 'إعدام مستحق بدل وجبات (تنازل إداري)',
            'gift' => 'وجبة مجانية/هدية للموظف',
            default => 'تسوية بدل وجبات الموظف',
        };

        try {
            $receivable = $accounting->accountForPostingRole('staff_meal_receivable');
            $accounting->post(
                eventType: 'staff_meal_settled_'.$charge->settlement_method,
                source: $charge,
                branchId: $charge->branch_id,
                postedOn: $charge->settled_at,
                description: $description,
                lines: [
                    ['account' => $debitAccount, 'debit' => (float) $charge->amount, 'credit' => 0, 'description' => $description],
                    ['account' => $receivable, 'debit' => 0, 'credit' => (float) $charge->amount, 'description' => 'تسوية استحقاق على الموظف'],
                ],
                createdBy: $charge->settled_by_user_id,
            );
        } catch (\Throwable $e) {
            \Log::warning('staff_meal.accounting.settle_failed', [
                'charge_id' => $charge->id,
                'method' => $charge->settlement_method,
                'error' => $e->getMessage(),
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
    protected function maybeNotifyThreshold(Employee $staff, StaffMealCharge $charge): void
    {
        if ($staff->monthly_meal_allowance === null) {
            return;
        }
        $pct = $staff->staffMealUsagePct();
        if ($pct === null) {
            return;
        }

        $threshold = match (true) {
            $pct >= 120 => 120,
            $pct >= 100 => 100,
            $pct >= 80 => 80,
            default => null,
        };
        if ($threshold === null) {
            return;
        }

        // Dedupe: did we already fire THIS threshold for THIS user in
        // THIS month? ActivityLog uses metadata.threshold for the key.
        $month = now()->format('Y-m');
        $already = ActivityLog::query()
            ->where('event', 'staff_meal.threshold')
            ->where('subject_type', Employee::class)
            ->where('subject_id', $staff->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->get()
            ->contains(fn ($log) => (int) ($log->properties['threshold'] ?? 0) >= $threshold);
        if ($already) {
            return;
        }

        $severity = $threshold === 80 ? 'تنبيه' : ($threshold === 100 ? 'تحذير' : 'تجاوز');
        ActivityLog::log(
            'staff_meal.threshold',
            "{$severity}: الموظف {$staff->name} وصل إلى {$pct}% من بدله الشهري (شهر {$month}).",
            $staff,
            [
                'threshold' => $threshold,
                'pct' => $pct,
                'used' => round($staff->staffMealUsedInMonth(), 2),
                'allowance' => (float) $staff->monthly_meal_allowance,
                'charge_id' => $charge->id,
            ],
        );
    }

    /** Resolve legacy user callers into the new employee source of truth. */
    protected function employee(Employee|User $staff): Employee
    {
        return $staff instanceof Employee ? $staff : Employee::fromUser($staff);
    }
}
