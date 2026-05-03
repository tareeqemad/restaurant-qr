<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/**
 * Full-screen multi-branch operations monitor — owner-only.
 *
 * Designed for the office TV during peak hours: every branch in one frame,
 * orders + sales + table state ticking live. The page strips the standard
 * admin chrome (no sidebar, no header) so the data fills the screen.
 */
class LiveMonitorController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->isOwnerLevel(), 403,
            'شاشة المراقبة المباشرة متاحة للملاك فقط.');

        return view('admin.partner.live-monitor');
    }
}
