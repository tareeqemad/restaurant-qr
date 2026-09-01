<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchLegalProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'registered_name',
        'tax_number',
        'commercial_registration_number',
        'municipal_license_number',
        'invoice_phone',
        'invoice_email',
        'invoice_address',
        'notes',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
