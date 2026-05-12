<?php

use App\Http\Controllers\Customer;
use Illuminate\Support\Facades\Route;

// Customer scans QR
Route::get('/menu/{token}', [Customer\MenuController::class, 'open'])->name('customer.menu.open');

// Currency switcher — accessible from any customer page, doesn't require table session
Route::post('/currency', [\App\Http\Controllers\Admin\CurrencyController::class, 'switch'])->name('customer.currency.switch');

// After session is opened (cookie set)
Route::middleware('table.session')->group(function () {
    Route::get('/menu', [Customer\MenuController::class, 'menu'])->name('customer.menu');
    Route::post('/menu/dismiss-promo/{id}', [Customer\MenuController::class, 'dismissGuestPromo'])
        ->name('customer.menu.dismissGuestPromo');
    Route::post('/cart/add', [Customer\CartController::class, 'add'])->name('customer.cart.add');
    Route::post('/cart/update', [Customer\CartController::class, 'update'])->name('customer.cart.update');
    Route::post('/cart/remove', [Customer\CartController::class, 'remove'])->name('customer.cart.remove');
    Route::get('/cart', [Customer\CartController::class, 'view'])->name('customer.cart.view');
    Route::post('/cart/submit', [Customer\CartController::class, 'submit'])->name('customer.cart.submit');
    Route::get('/orders', [Customer\OrderStatusController::class, 'index'])->name('customer.orders');
    Route::get('/track', [Customer\OrderStatusController::class, 'track'])->name('customer.track');
    Route::post('/track/signup', [Customer\OrderStatusController::class, 'signup'])->name('customer.track.signup');
    Route::post('/track/signup/dismiss', [Customer\OrderStatusController::class, 'dismissSignup'])->name('customer.track.signup.dismiss');
    Route::post('/orders/{order}/cancel', [Customer\OrderStatusController::class, 'cancel'])->name('customer.orders.cancel');
    Route::post('/profile', [Customer\OrderStatusController::class, 'saveProfile'])->name('customer.profile');
    Route::get('/bill', [Customer\BillController::class, 'show'])->name('customer.bill');
    Route::post('/bill/request', [Customer\BillController::class, 'requestBill'])->name('customer.bill.request');
});
