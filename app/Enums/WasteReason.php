<?php

namespace App\Enums;

/**
 * Why a piece of stock was wasted. Displayed in the waste-log dropdown
 * and used as a grouping dimension in the waste-analysis report. Pick
 * categories that map to operational fixes:
 *
 *   - Expired   → reorder less / shorter cycle
 *   - Damaged   → handling/storage training
 *   - Spilled   → kitchen process review
 *   - PrepLoss  → recipe yield review
 *   - Theft     → security review
 *   - Other     → free-text in `reason` field
 */
enum WasteReason: string
{
    case Expired  = 'expired';
    case Damaged  = 'damaged';
    case Spilled  = 'spilled';
    case PrepLoss = 'prep_loss';
    case Theft    = 'theft';
    case Other    = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Expired  => 'انتهت الصلاحية',
            self::Damaged  => 'تلف',
            self::Spilled  => 'انسكاب / كسر',
            self::PrepLoss => 'فقد في التحضير',
            self::Theft    => 'سرقة / فقد',
            self::Other    => 'أخرى',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Expired  => 'bi-calendar-x-fill',
            self::Damaged  => 'bi-box2-heart',
            self::Spilled  => 'bi-droplet-half',
            self::PrepLoss => 'bi-knife',
            self::Theft    => 'bi-shield-exclamation',
            self::Other    => 'bi-question-circle-fill',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Expired  => 'warning',
            self::Damaged  => 'danger',
            self::Spilled  => 'info',
            self::PrepLoss => 'secondary',
            self::Theft    => 'danger',
            self::Other    => 'secondary',
        };
    }

    /** Human-friendly options for select dropdowns. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->toArray();
    }
}
