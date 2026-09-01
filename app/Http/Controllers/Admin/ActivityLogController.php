<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\AdminShell;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * First screen migrated onto the Wave-0 Inertia shell — same query,
     * same filters as the retired Blade view; only the renderer changed.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', ActivityLog::class);

        $q = ActivityLog::with('causer')->latest();
        if ($e = $request->get('event')) $q->where('event', 'like', "%$e%");
        if ($u = $request->get('user_id')) $q->where('causer_id', $u);
        if ($d = $request->get('date')) $q->whereDate('created_at', $d);

        $logs = $q->paginate(30)->withQueryString()->through(fn ($log) => [
            'id' => $log->id,
            'time' => $log->created_at->format('Y-m-d H:i:s'),
            'event' => $log->event,
            'description' => $log->description,
            'causer' => $log->causer?->name,
            'ip' => $log->ip_address,
        ]);

        return AdminShell::render('Admin/ActivityLogs/Index', [
            'logs' => $logs,
            'filters' => $request->only(['event', 'date']),
            'urls' => ['index' => route('admin.activity-logs.index')],
        ]);
    }
}
