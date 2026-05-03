<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurrencyController extends Controller
{
    public function index()
    {
        // Currencies UI has moved into /admin/settings (Currencies tab).
        // Any old bookmark to /admin/currencies now lands on the right tab.
        return redirect(route('admin.settings.index').'#tab-currencies', 302);
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
        Currency::create($data);
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
            foreach ($data['rates'] as $id => $rate) {
                $c = Currency::find($id);
                if (!$c) continue;
                if ($c->is_base) continue; // base rate is always 1

                $c->update([
                    'rate_to_base'    => (float) $rate,
                    'is_active'       => (bool) ($data['active'][$id] ?? false),
                    'rate_updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'تم تحديث أسعار الصرف.');
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
}
