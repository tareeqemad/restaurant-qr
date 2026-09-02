<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Deployment\SystemHealthService;
use App\Support\AdminShell;
use Illuminate\Http\Request;

class SystemHealthController extends Controller
{
    public function __invoke(Request $request, SystemHealthService $health)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return AdminShell::render('Admin/SystemHealth/Index', [
            'report' => $health->report(),
            'deployment' => [
                'healthCommand' => 'php artisan app:health',
                'deployCommand' => 'php artisan app:deploy',
                'schedulerCommand' => '* * * * * php artisan schedule:run',
                'queueCommand' => 'php artisan queue:work --stop-when-empty --tries=3',
            ],
        ]);
    }
}
