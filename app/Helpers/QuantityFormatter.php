<?php

namespace App\Helpers;

/**
 * Stock is stored in the ingredient's BASE unit (gram, milliliter,
 * piece). That keeps the math sane — every deduction, return, and
 * cost calculation operates on a single scale — but humans don't
 * read "5,000,000 g" of sugar, they read "5 طن".
 *
 * This helper turns a base-unit value into the most-readable
 * (value, unit) pair for display. The conversion never changes how
 * data is stored; it's a pure render-time concern.
 *
 * Examples:
 *   smart(850, 'g')        → '850 غرام'
 *   smart(5_500, 'g')      → '5.5 كيلوغرام'
 *   smart(5_000_000, 'g')  → '5 طن'
 *   smart(750, 'ml')       → '750 مللتر'
 *   smart(2_500, 'ml')     → '2.5 لتر'
 *   smart(12, 'pcs')       → '12 قطعة'
 *
 * Falls back to "{number} {baseCode}" when the base unit isn't on
 * the known ladder (e.g., a custom base) — never crashes, just
 * displays whatever it was given.
 */
class QuantityFormatter
{
    /**
     * Tiered ladders for each base unit. Order matters: pick the
     * LARGEST tier whose threshold fits, so 5,000,000 g picks "طن"
     * rather than "كيلوغرام".
     */
    protected const LADDERS = [
        'g' => [
            ['threshold' => 1_000_000, 'name' => 'طن',         'divisor' => 1_000_000],
            ['threshold' => 1_000,     'name' => 'كيلوغرام', 'divisor' => 1_000],
            ['threshold' => 0,         'name' => 'غرام',      'divisor' => 1],
        ],
        'ml' => [
            ['threshold' => 1_000, 'name' => 'لتر',     'divisor' => 1_000],
            ['threshold' => 0,     'name' => 'مللتر', 'divisor' => 1],
        ],
        'pcs' => [
            ['threshold' => 12, 'name' => 'دستة',   'divisor' => 12],
            ['threshold' => 0,  'name' => 'قطعة', 'divisor' => 1],
        ],
    ];

    /**
     * Display string. Combines value + unit name (e.g. "5 طن").
     *
     * Numbers are trimmed to look natural — whole numbers don't show
     * decimals (5 → "5", not "5.00"), but fractional values show up
     * to 3 decimals trimmed of trailing zeros (1.5, 0.75, 0.125).
     */
    public static function smart(float $valueInBase, ?string $baseUnitCode): string
    {
        $part = self::smartParts($valueInBase, $baseUnitCode);
        return self::trimNumber($part['value']) . ' ' . $part['unit'];
    }

    /**
     * Same as smart() but returns the value + unit name separately so
     * the caller can render them in different DOM elements (e.g. big
     * number + small unit label).
     *
     * @return array{value: float, unit: string, base_code: string}
     */
    public static function smartParts(float $valueInBase, ?string $baseUnitCode): array
    {
        $code = strtolower((string) ($baseUnitCode ?? ''));
        $ladder = self::LADDERS[$code] ?? null;

        // Unknown base unit — pass through unchanged. Better to show
        // the raw number than to invent a wrong conversion.
        if (! $ladder) {
            return [
                'value'     => $valueInBase,
                'unit'      => $baseUnitCode ?: '',
                'base_code' => $baseUnitCode ?: '',
            ];
        }

        $abs = abs($valueInBase);
        foreach ($ladder as $tier) {
            if ($abs >= $tier['threshold']) {
                return [
                    'value'     => $valueInBase / $tier['divisor'],
                    'unit'      => $tier['name'],
                    'base_code' => $baseUnitCode,
                ];
            }
        }

        // Defensive fallback — the last tier has threshold 0 so we
        // should never reach here, but keep it just in case.
        $last = end($ladder);
        return [
            'value'     => $valueInBase / $last['divisor'],
            'unit'      => $last['name'],
            'base_code' => $baseUnitCode,
        ];
    }

    /**
     * "5.00" → "5", "5.50" → "5.5", "5.123" → "5.12", "0.001" → "0.001".
     * Strips trailing zeros so the display reads naturally.
     */
    protected static function trimNumber(float $value): string
    {
        // Three-decimal precision is enough for cooking ladders; more
        // precision goes into the per-base raw number on hover tooltips.
        $formatted = number_format($value, 3, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted;
    }
}
