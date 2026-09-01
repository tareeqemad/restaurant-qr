<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SyncState;
use App\Support\AdminShell;
use App\Sync\SyncManager;
use Illuminate\Http\RedirectResponse;

/**
 * Admin "Sync status" page: shows each stream's last outcome and offers a
 * manual "Sync now" button. Sync normally runs itself every minute (the
 * scheduler), so this is just for visibility and the occasional nudge.
 */
class SyncStatusController extends Controller
{
    public function index()
    {
        return AdminShell::render('Admin/Sync/Index', [
            'states' => SyncState::orderBy('stream')->get()->map(fn (SyncState $state) => [
                'id' => $state->id,
                'stream' => $state->stream,
                'direction' => $state->direction,
                'status' => $state->last_status ?: 'pending',
                'error' => $state->last_error,
                'count' => $state->last_count,
                'lastSyncedAt' => $state->last_synced_at?->diffForHumans(),
            ])->values(),
            'role' => config('sync.role'),
            'enabled' => (bool) config('sync.enabled'),
            'cloudUrl' => config('sync.cloud_url'),
            'urls' => [
                'run' => route('admin.sync.run'),
            ],
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
