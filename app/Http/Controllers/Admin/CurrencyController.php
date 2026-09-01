<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\CurrencyExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurrencyController extends Controller
{
    public function index()
    {
        // Currency management lives inside the single billing workspace.
        // Keep old bookmarks useful without exposing a retired tab name.
        return redirect(route('admin.settings.index').'#billing', 302);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        $data = $request->validate([
            'code'          => ['required', 'string', 'size:3', 'unique:currencies,code'],
            'name'          => ['required', 'string', 'max:60'],
            'symbol'        => ['required', 'string', 'max:10'],
            'rate_to_base'  => ['required', 'numeric', 'min:0.000001'],
            'is_active'     => ['boolean'],
            'display_order' => ['nullable', 'integer'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['rate_updated_at'] = now();
        $currency = Currency::create($data);

        if (! $currency->is_base && (float) $currency->rate_to_base > 0) {
            app(ExchangeRateService::class)->record(
                currencyCode: $currency->code,
                baseCurrencyCode: $this->baseCurrencyCode(),
                rate: (float) $currency->rate_to_base,
                validFrom: now()->toDateString(),
                validTo: now()->toDateString(),
                createdBy: auth()->id(),
                source: 'daily_update',
                note: 'Created with currency',
            );
        }
        return back()->with('success', 'تم إضافة العملة.');
    }

    public function updateRates(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        $data = $request->validate([
            'rates'       => ['required', 'array'],
            'rates.*'     => ['nullable', 'numeric', 'min:0.000001'],
            'active'      => ['nullable', 'array'],
            'active.*'    => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data) {
            $exchangeRates = app(ExchangeRateService::class);

            foreach ($data['rates'] as $id => $rate) {
                $c = Currency::find($id);
                if (!$c) continue;
                if ($c->is_base) continue; // base rate is always 1
                if ($rate === null || $rate === '') continue;

                $c->update([
                    'rate_to_base'    => (float) $rate,
                    'is_active'       => (bool) ($data['active'][$id] ?? false),
                    'rate_updated_at' => now(),
                ]);

                if ((bool) ($data['active'][$id] ?? false) && (float) $rate > 0) {
                    $exchangeRates->record(
                        currencyCode: $c->code,
                        baseCurrencyCode: $this->baseCurrencyCode(),
                        rate: (float) $rate,
                        validFrom: now()->toDateString(),
                        validTo: now()->toDateString(),
                        createdBy: auth()->id(),
                        source: 'daily_update',
                        note: 'Daily settings update',
                    );
                }
            }
        });

        return back()->with('success', 'تم تحديث أسعار الصرف.');
    }

    public function storeExchangeRate(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        $data = $request->validate([
            'currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'rate' => ['required', 'numeric', 'min:0.000001', 'max:999999'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(ExchangeRateService::class)->record(
                currencyCode: $data['currency_code'],
                baseCurrencyCode: $this->baseCurrencyCode(),
                rate: (float) $data['rate'],
                validFrom: $data['valid_from'],
                validTo: $data['valid_to'] ?? null,
                createdBy: auth()->id(),
                source: 'manual',
                note: $data['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تم إضافة سعر الصرف.');
    }

    public function destroyExchangeRate(CurrencyExchangeRate $exchangeRate)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        $exchangeRate->delete();

        return back()->with('success', 'تم حذف سعر الصرف.');
    }

    public function destroy(Currency $currency)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        if ($currency->is_base) {
            return back()->with('error', 'لا يمكن حذف العملة الأساسية.');
        }
        $currency->delete();
        return back()->with('success', 'تم حذف العملة.');
    }

    /** Public (customer-facing) switcher — stores preference in session */
    public function switch(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'size:3']]);
        app(\App\Services\CurrencyService::class)->setCurrent($request->input('code'));
        return back();
    }

    private function baseCurrencyCode(): string
    {
        return app(ExchangeRateService::class)->baseCurrencyCode();
    }
}
