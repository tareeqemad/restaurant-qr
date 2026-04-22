<?php

namespace App\Helpers;

use App\Models\Unit;

class UnitConverter
{
    public static function convert(float $quantity, int $fromUnitId, int $toUnitId): float
    {
        if ($fromUnitId === $toUnitId) {
            return $quantity;
        }

        $from = Unit::findOrFail($fromUnitId);
        $to = Unit::findOrFail($toUnitId);

        if ($from->unit_type !== $to->unit_type) {
            throw new \InvalidArgumentException("لا يمكن التحويل بين {$from->name} و {$to->name}");
        }

        $inBase = $quantity * (float) $from->factor_to_base;

        return $inBase / (float) $to->factor_to_base;
    }
}
