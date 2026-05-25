<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\OptimizedAssetController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));
Route::get('/brand-assets/{key}', BrandAssetController::class)->name('brand.asset');
Route::get('/optimized-assets/{path}', OptimizedAssetController::class)
    ->where('path', '.*')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('optimized.asset');

Route::get('/login', [LoginController::class, 'show'])->name('login');

Route::middleware('guest')->group(function () {
    // throttle:5,1 → max 5 attempts per minute per IP+route. Prevents brute
    // force of admin credentials. The throttle key is derived from request
    // IP + the named route, so different IPs are limited independently.
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');

    // Forgot-password (staff). The controller also enforces its own
    // per-identifier + per-IP throttles so the SMS provider doesn't get
    // hammered on a typo loop.
    Route::get ('/forgot-password',  [ForgotPasswordController::class, 'show'])->name('password.request');
    Route::post('/forgot-password',  [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('password.email');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// /storage/* is auto-served from config/filesystems.php's public disk
// (serve=true). No custom route needed; the public disk's auto-route
// streams files from storage/app/public.
