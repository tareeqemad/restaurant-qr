<?php

namespace App\Services;

use App\Models\Currency;
use Illuminate\Support\Collection;

/**
 * Currency conversion & display.
 *
 * Architecture: all storage stays in base currency (ILS). This service only
 * affects what's DISPLAYED to the customer. The customer's preference is
 * saved in the session (cookie) and read back on every page.
 *
 * Rates are editable by the admin (no external API calls).
 */
class CurrencyService
{
    protected const SESSION_KEY = 'display_currency';

    /** Cache of active currencies for the duration of a single request. */
    protected ?Collection $cache = null;

    public function all(): Collection
    {
        return $this->cache ??= Currency::orderBy('display_order')->orderBy('code')->get();
    }

    public function active(): Collection
    {
        return $this->all()->where('is_active', true)->values();
    }

    public function base(): ?Currency
    {
        return $this->all()->firstWhere('is_base', true);
    }

    public function byCode(string $code): ?Currency
    {
        return $this->all()->firstWhere('code', strtoupper($code));
    }

    /**
     * Which currency should we display to the current visitor?
     * Preference: session → query-string override → base.
     */
    public function current(): Currency
    {
        $code = session(self::SESSION_KEY);
        if (!$code && request()->has('currency')) {
            $code = strtoupper(request()->get('currency'));
        }
        $currency = $code ? $this->byCode($code) : null;
        return ($currency && $currency->is_active) ? $currency : ($this->base() ?? $this->active()->first());
    }

    /** Remember a currency preference for the duration of the session. */
    public function setCurrent(string $code): void
    {
        $currency = $this->byCode($code);
        if ($currency && $currency->is_active) {
            session([self::SESSION_KEY => $currency->code]);
        }
    }

    /**
     * Convert a base-currency amount to the display currency & format it.
     * Always shows the base amount too, for transparency:
     *   "12.50 ₪ ≈ $3.40"
     * Falls back to just "12.50 ₪" if display == base.
     */
    public function display(float $baseAmount): string
    {
        $base = $this->base();
        $current = $this->current();
        if (!$base || !$current) return number_format($baseAmount, 2);

        if ($current->is_base) {
            return $current->format($baseAmount);
        }

        $foreign = $current->convertFromBase($baseAmount);
        return $base->format($baseAmount) . ' ≈ ' . $current->format($foreign);
    }

    /** Just the converted amount, no base prefix. */
    public function displayForeignOnly(float $baseAmount): string
    {
        $current = $this->current();
        if (!$current) return number_format($baseAmount, 2);
        $amount = $current->is_base ? $baseAmount : $current->convertFromBase($baseAmount);
        return $current->format($amount);
    }
}
