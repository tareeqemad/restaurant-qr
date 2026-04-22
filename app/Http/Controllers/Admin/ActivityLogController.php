<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ActivityLog::class);
        $q = ActivityLog::with('causer')->latest();
        if ($e = $request->get('event')) $q->where('event', 'like', "%$e%");
        if ($u = $request->get('user_id')) $q->where('causer_id', $u);
        if ($d = $request->get('date')) $q->whereDate('created_at', $d);
        $logs = $q->paginate(30)->withQueryString();
        return view('admin.activity-logs.index', compact('logs'));
    }
}
