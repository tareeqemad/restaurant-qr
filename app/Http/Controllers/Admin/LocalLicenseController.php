<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalLicenseState;
use App\Services\Licensing\LicenseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LocalLicenseController extends Controller
{
    public function index(LicenseManager $manager): View
    {
        $this->guardLocalAdmin();

        $state = LocalLicenseState::current();

        return view('admin.license-status.index', [
            'state' => $state,
            'summary' => $manager->summary($state),
            'configuredKey' => $manager->configuredKey(),
            'envKeyLocked' => filled(config('license.key')),
            'enabled' => config('license.enabled'),
            'role' => config('license.role'),
            'cloudUrl' => config('license.cloud_url'),
            'warningDays' => config('license.warning_days'),
        ]);
    }

    public function updateKey(Request $request): RedirectResponse
    {
        $this->guardLocalAdmin();

        abort_if(filled(config('license.key')), 403, 'License key is locked by the environment file.');

        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:120'],
        ]);

        LocalLicenseState::current()->forceFill([
            'license_key' => trim($data['license_key']),
            'status' => 'missing',
            'last_error' => null,
        ])->save();

        return redirect()
            ->route('admin.license-status.index')
            ->with('success', 'تم حفظ مفتاح الترخيص. اضغط فحص الآن لتحديث الحالة من السحابة.');
    }

    public function refresh(LicenseManager $manager): RedirectResponse
    {
        $this->guardLocalAdmin();

        $state = $manager->refresh();
        $summary = $manager->summary($state);

        return redirect()
            ->route('admin.license-status.index')
            ->with($summary['allows_operations'] ? 'success' : 'warning', $summary['message']);
    }

    private function guardLocalAdmin(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin']), 403);
    }
}
