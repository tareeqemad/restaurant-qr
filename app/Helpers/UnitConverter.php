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

        return self::convertUsingUnits($quantity, $from, $to);
    }

    public static function convertUsingUnits(float $quantity, Unit $from, Unit $to): float
    {
        if ($from->id === $to->id) {
            return $quantity;
        }

        if ($from->unit_type !== $to->unit_type) {
            throw new \InvalidArgumentException("لا يمكن التحويل بين {$from->name} و {$to->name}");
        }

        $inBase = $quantity * (float) $from->factor_to_base;

        return $inBase / (float) $to->factor_to_base;
    }
}
