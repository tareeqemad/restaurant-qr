<?php

namespace App\Helpers;

/**
 * Quantity / stock formatter.
 *
 * The DB stores quantities at 4 decimals so unit-conversions don't lose
 * precision (1 oz → 28.3495 g), but rendering "520.0000" everywhere on
 * screen is visual noise — almost no real-world quantity needs four
 * decimals to read. Default to two and trim trailing zeros so whole
 * numbers stay clean:
 *
 *   520        → "520"
 *   520.5      → "520.5"
 *   520.55     → "520.55"
 *   520.554    → "520.55"   (rounded to max decimals)
 *   0          → "0"
 *
 * Pass `decimals: 4` only where the precision really matters (e.g. tiny
 * unit costs of fractions of a cent).
 */
class Qty
{
    public static function format(float|int|string|null $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $s = number_format((float) $value, $decimals, '.', '');
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
