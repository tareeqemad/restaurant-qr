<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureFirstRunSetupComplete;
use App\Http\Middleware\EnsureValidLicense;
use App\Http\Middleware\SetActiveBranch;
use App\Http\Middleware\TableSessionMiddleware;
use App\Http\Middleware\TranslateMarketHtml;
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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->group(base_path('routes/customer.php'));

            Route::middleware('web')
                ->prefix('portal')
                ->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            TranslateMarketHtml::class,
        ]);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'branch' => SetActiveBranch::class,
            'setup.complete' => EnsureFirstRunSetupComplete::class,
            'license' => EnsureValidLicense::class,
            'table.session' => TableSessionMiddleware::class,
            'sync.token' => VerifySyncToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
