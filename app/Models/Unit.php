<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'unit_type', 'factor_to_base', 'is_base'];

    protected $casts = [
        'factor_to_base' => 'decimal:8',
        'is_base' => 'boolean',
    ];
}
