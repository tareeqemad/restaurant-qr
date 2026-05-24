<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch settlement of staff meal charges for a given month +
 * branch. Created by `StaffMealService::closeMonth()`, which marks
 * every open charge in that month as settled and links it back here
 * via `staff_meal_charges.month_closure_id`.
 *
 * One closure per (branch, month) — re-running closeMonth returns
 * the existing closure (idempotent). This is also what the printable
 * payroll-deduction sheet reads from so accounting can re-print last
 * month's sheet without re-aggregating individual charges.
 */
class StaffMealMonthClosure extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'month', 'method',
        'total_amount', 'staff_count', 'charge_count',
        'closed_by_user_id', 'closed_at', 'notes',
    ];

    protected $casts = [
        'month'        => 'date',
        'closed_at'    => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo  { return $this->belongsTo(Branch::class); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by_user_id'); }
    public function charges(): HasMany    { return $this->hasMany(StaffMealCharge::class, 'month_closure_id'); }
}
