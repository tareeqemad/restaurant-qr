<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BrandAssetController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));
Route::get('/brand-assets/{key}', BrandAssetController::class)->name('brand.asset');

Route::get('/login', [LoginController::class, 'show'])->name('login');

Route::middleware('guest')->group(function () {
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// /storage/* is auto-served from config/filesystems.php's public disk
// (serve=true). No custom route needed; the public disk's auto-route
// streams files from storage/app/public.
