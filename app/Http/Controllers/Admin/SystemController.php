<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DemoResetService;
use Illuminate\Http\Request;

/**
 * System administration screens — Super Admin only.
 *
 * Currently exposes the "Reset Demo Data" flow used for client handover:
 * the consultant ships the demo install, the client clicks one button to
 * wipe it, and the system is ready for production setup without ever
 * touching the CLI.
 */
class SystemController extends Controller
{
    public function index(DemoResetService $reset)
    {
        $this->guardSuperAdmin();

        return view('admin.system.index', [
            'hasDemoData' => $reset->hasDemoData(),
        ]);
    }

    /**
     * Wipe demo / operational data, keeping the acting Super Admin + system
     * reference data so the client can start from scratch inside the running
     * app. Two-step confirmation: the form must POST the literal word
     * `احذف-البيانات` to proceed.
     */
    public function resetDemo(Request $request, DemoResetService $reset)
    {
        $this->guardSuperAdmin();

        $request->validate([
            'confirmation' => ['required', 'string', 'in:احذف-البيانات'],
        ], [
            'confirmation.in' => 'لتأكيد المسح الكامل اكتب «احذف-البيانات» بالضبط.',
        ]);

        $stats = $reset->reset($request->user());

        $msg = sprintf(
            'تم مسح البيانات بنجاح: %d فرع و %d مستخدم. ابدأ الآن بإنشاء أول فرع لك.',
            $stats['branches'], $stats['users_deleted']
        );

        return redirect()->route('admin.dashboard')->with('success', $msg);
    }

    /**
     * Inline auth guard. Laravel 12 dropped the `middleware()` method on the
     * base Controller, so we enforce the SuperAdmin gate at the start of
     * each action. Keeping it inline (2 methods) is simpler than wiring a
     * HasMiddleware interface or moving the check to the route file —
     * those are worth it once a controller has 5+ actions.
     */
    protected function guardSuperAdmin(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403,
            'إدارة النظام متاحة لمدير النظام فقط.');
    }
}
