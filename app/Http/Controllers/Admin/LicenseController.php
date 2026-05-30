<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class LicenseController extends Controller
{
    public function index(): View
    {
        $this->guardCloudAdmin();

        return view('admin.licenses.index', [
            'licenses' => License::query()
                ->latest('expires_at')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function create(): View
    {
        $this->guardCloudAdmin();

        return view('admin.licenses.create', [
            'license' => new License([
                'period_months' => 12,
                'starts_at' => now()->toDateString(),
                'expires_at' => now()->addYear()->subDay()->toDateString(),
                'grace_days' => config('license.grace_days', 14),
                'max_branches' => 1,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardCloudAdmin();

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:191'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'customer_email' => ['nullable', 'email', 'max:191'],
            'restaurant_name' => ['nullable', 'string', 'max:191'],
            'period_months' => ['required', 'integer', 'in:6,12'],
            'starts_at' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'max_branches' => ['required', 'integer', 'min:1', 'max:250'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $startsAt = Carbon::parse($data['starts_at'])->startOfDay();
        $periodMonths = (int) $data['period_months'];

        $license = License::create([
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'restaurant_name' => $data['restaurant_name'] ?? null,
            'period_months' => $periodMonths,
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->copy()->addMonthsNoOverflow($periodMonths)->subDay(),
            'grace_days' => (int) $data['grace_days'],
            'max_branches' => (int) $data['max_branches'],
            'notes' => $data['notes'] ?? null,
            'last_payment_at' => now(),
        ]);

        $license->payments()->create([
            'period_months' => $periodMonths,
            'amount' => $data['amount'] ?? null,
            'paid_at' => now(),
            'method' => 'cash',
            'received_by_user_id' => $request->user()?->id,
            'starts_at' => $startsAt,
            'expires_at' => $license->expires_at,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()
            ->route('admin.licenses.show', $license)
            ->with('success', 'تم إنشاء الترخيص. انسخ مفتاح الترخيص إلى جهاز العميل.');
    }

    public function show(License $license): View
    {
        $this->guardCloudAdmin();

        return view('admin.licenses.show', [
            'license' => $license->load('payments.receivedBy'),
        ]);
    }

    public function renew(Request $request, License $license): RedirectResponse
    {
        $this->guardCloudAdmin();

        $data = $request->validate([
            'period_months' => ['required', 'integer', 'in:6,12'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $license->renew(
            periodMonths: (int) $data['period_months'],
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            paidAt: isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
            receivedByUserId: $request->user()?->id,
            notes: $data['notes'] ?? null,
            reference: $data['reference'] ?? null,
        );

        return redirect()
            ->route('admin.licenses.show', $license)
            ->with('success', 'تم تجديد الترخيص وتسجيل دفعة نقدية.');
    }

    public function suspend(License $license): RedirectResponse
    {
        $this->guardCloudAdmin();

        $license->forceFill(['status' => License::STATUS_SUSPENDED])->save();

        return back()->with('success', 'تم إيقاف الترخيص.');
    }

    public function activate(License $license): RedirectResponse
    {
        $this->guardCloudAdmin();

        $license->forceFill(['status' => License::STATUS_ACTIVE])->save();

        return back()->with('success', 'تم تفعيل الترخيص.');
    }

    private function guardCloudAdmin(): void
    {
        abort_unless(config('license.role') === 'cloud' && auth()->user()?->isSuperAdmin(), 403);
    }
}
