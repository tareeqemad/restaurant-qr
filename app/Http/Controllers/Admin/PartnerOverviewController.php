<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/**
 * Branches overview screen — management-level (owner / admin / manager).
 *
 * Renders the wrapper view; the live KPI cards + alerts are handled by the
 * `<livewire:admin.partner-overview />` component which polls + listens to
 * Reverb broadcasts for cross-branch updates without a manual refresh.
 *
 * Owner-level (Super Admin + Partner) sees every active branch. Branch-
 * level admin/manager sees only the branches they belong to via the
 * branch_user pivot — the Livewire component enforces the data scoping.
 * Floor staff (waiter/chef/cashier/bartender) are blocked here.
 */
class PartnerOverviewController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->isManagementLevel(), 403,
            'هذه الشاشة متاحة للإدارة فقط.');

        return view('admin.partner.overview');
    }
}
