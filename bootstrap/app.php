<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AttachRequestContext;
use App\Http\Middleware\EnsureFirstRunSetupComplete;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetActiveBranch;
use App\Http\Middleware\TableOrderingDeviceMiddleware;
use App\Http\Middleware\TableSessionMiddleware;
use App\Http\Middleware\VerifySyncToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->group(base_path('routes/customer.php'));

        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AttachRequestContext::class,
            // Inertia middleware is inert on classic Blade
            // responses — it only shapes routes that return Inertia::render().
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'branch' => SetActiveBranch::class,
            'permission' => RequirePermission::class,
            'setup.complete' => EnsureFirstRunSetupComplete::class,
            'table.session' => TableSessionMiddleware::class,
            'table.order-owner' => TableOrderingDeviceMiddleware::class,
            'sync.token' => VerifySyncToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
