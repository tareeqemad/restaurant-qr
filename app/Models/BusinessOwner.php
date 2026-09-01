<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessOwner extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_type',
        'name',
        'national_id',
        'tax_number',
        'commercial_registration_number',
        'phone',
        'email',
        'address',
        'notes',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_ownerships')
            ->withPivot([
                'ownership_percentage',
                'title',
                'is_primary',
                'is_authorized_signatory',
                'starts_on',
                'ends_on',
            ])
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function typeLabel(): string
    {
        return $this->owner_type === 'company' ? 'شركة / جهة اعتبارية' : 'شخص';
    }
}
