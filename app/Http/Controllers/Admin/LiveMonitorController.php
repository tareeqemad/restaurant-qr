<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/**
 * Full-screen live operations monitor — management-level access.
 *
 * Designed for the office TV during peak hours: every accessible branch in
 * one frame, orders + sales + table state ticking live. The page strips
 * the standard admin chrome (no sidebar, no header) so the data fills
 * the screen.
 *
 * Owner-level sees every active branch. Branch admin/manager sees only
 * the branches they belong to (the Livewire component enforces it).
 */
class LiveMonitorController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->isManagementLevel(), 403,
            'شاشة المراقبة المباشرة متاحة للإدارة فقط.');

        return view('admin.partner.live-monitor');
    }
}
