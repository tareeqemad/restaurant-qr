<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/**
 * Multi-branch overview screen for partners and super admins.
 *
 * Renders the wrapper view; the live KPI cards + alerts are handled by the
 * `<livewire:admin.partner-overview />` component which polls + listens to
 * Reverb broadcasts for cross-branch updates without a manual refresh.
 *
 * Access is gated here at the controller level (owner-level only) — staff
 * shouldn't be able to even attempt the URL, even though the component
 * itself would also reject them.
 */
class PartnerOverviewController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->isOwnerLevel(), 403,
            'هذه الشاشة متاحة للملاك فقط.');

        return view('admin.partner.overview');
    }
}
