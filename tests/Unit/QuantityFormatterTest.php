<?php

namespace Tests\Unit;

use App\Helpers\QuantityFormatter;
use PHPUnit\Framework\TestCase;

/**
 * QuantityFormatter — turn a base-unit number into the friendliest
 * (value, unit) representation. The math is so foundational (every
 * stock display in the system runs through this) that explicit table-
 * driven coverage is worth the noise.
 */
class QuantityFormatterTest extends TestCase
{
    /** @dataProvider weightCases */
    public function test_weight_ladder_picks_the_largest_fitting_unit(float $grams, string $expected): void
    {
        $this->assertSame($expected, QuantityFormatter::smart($grams, 'g'));
    }

    public static function weightCases(): array
    {
        return [
            'pinch — under 1kg stays in grams' => [850.0,         '850 غرام'],
            'whole kg — clean'                  => [1_000.0,       '1 كيلوغرام'],
            'kg with fraction'                  => [5_500.0,       '5.5 كيلوغرام'],
            'rounds up to ton at exactly 1M'    => [1_000_000.0,   '1 طن'],
            'operator example: 5 tons'          => [5_000_000.0,   '5 طن'],
            'fractional ton'                    => [2_750_000.0,   '2.75 طن'],
            'half a gram'                       => [0.5,           '0.5 غرام'],
            'zero'                              => [0.0,           '0 غرام'],
        ];
    }

    /** @dataProvider volumeCases */
    public function test_volume_ladder_uses_litres_above_1000ml(float $ml, string $expected): void
    {
        $this->assertSame($expected, QuantityFormatter::smart($ml, 'ml'));
    }

    public static function volumeCases(): array
    {
        return [
            'small pour'        => [750.0,   '750 مللتر'],
            'one litre'         => [1_000.0, '1 لتر'],
            'fractional litre'  => [2_500.0, '2.5 لتر'],
            'big bucket'        => [50_000.0,'50 لتر'],
        ];
    }

    public function test_pcs_uses_dozen_at_12_or_more(): void
    {
        $this->assertSame('5 قطعة', QuantityFormatter::smart(5, 'pcs'));
        $this->assertSame('1 دستة',  QuantityFormatter::smart(12, 'pcs'));
        $this->assertSame('2 دستة',  QuantityFormatter::smart(24, 'pcs'));
    }

    /** Unknown base unit → pass-through; never invents a wrong conversion. */
    public function test_unknown_base_unit_falls_back_to_raw_value(): void
    {
        $this->assertSame('99 widget', QuantityFormatter::smart(99, 'widget'));
        $this->assertSame('99 ', QuantityFormatter::smart(99, null));
    }

    /** Parts form lets the caller split value vs. unit name in DOM. */
    public function test_smart_parts_returns_value_and_unit_separately(): void
    {
        $parts = QuantityFormatter::smartParts(5_500_000.0, 'g');
        $this->assertEqualsWithDelta(5.5, $parts['value'], 0.0001);
        $this->assertSame('طن', $parts['unit']);
        $this->assertSame('g',  $parts['base_code']);
    }
}
