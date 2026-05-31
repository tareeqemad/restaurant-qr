<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row in the chart of accounts. System accounts (is_system=true)
 * are canonical fallback accounts for `AccountingService` — the accountant can edit
 * their NAME / DESCRIPTION but never their CODE or core flags. Custom
 * accounts (is_system=false) are fully editable and may be organised
 * as sub-accounts of any existing account via `parent_account_id`.
 */
class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'parent_account_id',
        'normal_balance',
        'description',
        'is_system',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'is_system'     => 'boolean',
        'is_active'     => 'boolean',
        'display_order' => 'integer',
    ];

    // ────────────────────────────────────────────────────────────────
    // Relations
    // ────────────────────────────────────────────────────────────────

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id')
            ->orderBy('display_order')
            ->orderBy('code');
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    public function normalBalanceLabel(): string
    {
        return $this->normal_balance === 'debit' ? 'مدين' : 'دائن';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'asset'          => 'أصل',
            'liability'      => 'التزام',
            'equity'         => 'حقوق ملكية',
            'revenue'        => 'إيراد',
            'contra_revenue' => 'مقابل إيراد',
            'expense'        => 'مصروف',
            default          => $this->type,
        };
    }

    /**
     * Full "1000 — الصندوق" label including any parent path.
     * Useful for dropdowns where context matters ("5101 — صيانة"
     * is meaningless without "تحت 5100 — مصاريف تشغيلية").
     */
    public function path(): string
    {
        $crumbs = [];
        $node = $this;
        while ($node) {
            array_unshift($crumbs, "{$node->code} — {$node->name}");
            $node = $node->parent;
        }
        return implode(' › ', $crumbs);
    }

    // ────────────────────────────────────────────────────────────────
    // Protection rules (used by AccountService + UI)
    // ────────────────────────────────────────────────────────────────

    /**
     * "Does the AccountingService rely on this account's code?"
     * System accounts are the canonical fallback accounts seeded by
     * migrations. Deactivating or renaming the code of any such row
     * would break fallback posting or historical reporting.
     */
    public function isProtected(): bool
    {
        return (bool) $this->is_system;
    }

    /**
     * "Can this account be deleted?" — hard NO when there's any
     * journal history. The FK `restrictOnDelete` on `journal_lines`
     * enforces this at the DB level too; we surface it earlier so
     * the UI can hide the delete button gracefully.
     */
    public function hasJournalLines(): bool
    {
        return $this->lines()->exists();
    }

    /**
     * True when the account is safe to fully remove from the chart:
     * never posted to, never marked system, no children dangling on
     * it. Falls back to "deactivate" otherwise.
     */
    public function isDeletable(): bool
    {
        return ! $this->isProtected()
            && ! $this->hasJournalLines()
            && $this->children()->count() === 0;
    }
}
