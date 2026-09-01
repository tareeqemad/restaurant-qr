<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen allocation for a staff meal. Lifecycle:
 *
 *   - Created after a staff order is physically served. The linked order
 *     freezes nominal consumption; `amount` stores only what exceeds the
 *     restaurant-funded monthly allowance and is due from the employee.
 *   - `settled_at` flipped when the employee pays cash, the manager
 *     writes it off, or it's deducted from payroll at month-end.
 *
 * Open rows = the employee's current outstanding debt to the restaurant.
 * Settling a row never erases the linked order from monthly consumption.
 */
class StaffMealCharge extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'employee_id', 'user_id', 'order_id',
        'amount', 'charged_at', 'settled_at',
        'settled_by_user_id', 'settlement_method',
        'month_closure_id', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'charged_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $charge): void {
            if (! $charge->employee_id && $charge->user_id) {
                $user = User::find($charge->user_id);
                $charge->employee_id = $user ? Employee::fromUser($user)->id : null;
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Legacy login relation retained for imported history only. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    public function monthClosure(): BelongsTo
    {
        return $this->belongsTo(StaffMealMonthClosure::class, 'month_closure_id');
    }

    /** Frozen nominal value of the consumed meal. */
    public function consumptionAmount(): float
    {
        // Do not partially hydrate the relation: callers often continue from
        // this value to the order workflow/status in the same request.
        $this->loadMissing('order');

        return round((float) ($this->order?->total ?? $this->amount), 2);
    }

    /** Portion paid by the restaurant under the monthly allowance/gift. */
    public function coveredAmount(): float
    {
        return round(max(0, $this->consumptionAmount() - (float) $this->amount), 2);
    }
}
