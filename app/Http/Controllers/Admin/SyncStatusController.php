<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SyncState;
use App\Sync\SyncManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Admin "Sync status" page: shows each stream's last outcome and offers a
 * manual "Sync now" button. Sync normally runs itself every minute (the
 * scheduler), so this is just for visibility and the occasional nudge.
 */
class SyncStatusController extends Controller
{
    public function index(): View
    {
        return view('admin.sync.index', [
            'states' => SyncState::orderBy('stream')->get(),
            'role' => config('sync.role'),
            'enabled' => (bool) config('sync.enabled'),
            'cloudUrl' => config('sync.cloud_url'),
        ]);
    }

    public function run(SyncManager $manager): RedirectResponse
    {
        $report = $manager->run();

        if (! empty($report['skipped'])) {
            $message = 'المزامنة غير مفعّلة على هذا الجهاز: '.$report['reason'];
        } elseif (! empty($report['offline'])) {
            $message = 'تعذّر الوصول للسحابة الآن — سيُعاد المحاولة تلقائياً كل دقيقة.';
        } else {
            $allOk = collect($report['streams'] ?? [])->every(fn ($r) => $r['ok']);
            $message = $allOk
                ? 'تمت المزامنة بنجاح.'
                : 'تمت المزامنة مع بعض الأخطاء — راجع الحالة بالأسفل.';
        }

        return redirect()->route('admin.sync.index')->with('success', $message);
    }
}
