<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charge against a staff member's monthly meal tab. Lifecycle:
 *
 *   - Created when `StaffMealService::chargeOrder()` settles an order
 *     placed by/for an employee. `amount` is frozen at that moment
 *     (subsequent order edits don't drift the tab).
 *   - `settled_at` flipped when the employee pays cash, the manager
 *     writes it off, or it's deducted from payroll at month-end.
 *
 * Open rows = the employee's current outstanding debt to the
 * restaurant. The monthly allowance check sums `amount` across the
 * current month's open rows.
 */
class StaffMealCharge extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'user_id', 'order_id',
        'amount', 'charged_at', 'settled_at',
        'settled_by_user_id', 'settlement_method', 'notes',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'charged_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function user(): BelongsTo        { return $this->belongsTo(User::class); }
    public function order(): BelongsTo       { return $this->belongsTo(Order::class); }
    public function branch(): BelongsTo      { return $this->belongsTo(Branch::class); }
    public function settledBy(): BelongsTo   { return $this->belongsTo(User::class, 'settled_by_user_id'); }
}
