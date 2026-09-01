<?php

use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Customer;
use Illuminate\Support\Facades\Route;

// The menu has one public door: the table QR token. There is deliberately no
// plain /menu route that can be opened or shared without a table QR.
Route::get('/menu/{token}', [Customer\MenuController::class, 'open'])
    ->middleware('table.session')
    ->name('customer.menu.open');

// Currency switcher — accessible from any customer page, doesn't require table session
Route::post('/currency', [CurrencyController::class, 'switch'])->name('customer.currency.switch');

// After session is opened (cookie set)
Route::middleware(['table.session'])->group(function () {
    Route::get('/cart', [Customer\CartController::class, 'view'])->name('customer.cart.view');
    Route::get('/track', [Customer\OrderStatusController::class, 'track'])->name('customer.track');
    Route::get('/track/data', [Customer\OrderStatusController::class, 'data'])->name('customer.track.data');
    Route::get('/track/pulse', [Customer\OrderStatusController::class, 'pulse'])->name('customer.track.pulse');
    Route::middleware('table.order-owner')->group(function () {
        Route::post('/cart/add', [Customer\CartController::class, 'add'])->name('customer.cart.add');
        Route::post('/cart/update', [Customer\CartController::class, 'update'])->name('customer.cart.update');
        Route::post('/cart/remove', [Customer\CartController::class, 'remove'])->name('customer.cart.remove');
        Route::post('/cart/submit', [Customer\CartController::class, 'submit'])->name('customer.cart.submit');
        Route::post('/orders/{order}/cancel', [Customer\OrderStatusController::class, 'cancel'])->name('customer.orders.cancel');
        Route::post('/orders/{order}/change-requests', [Customer\OrderChangeRequestController::class, 'store'])->name('customer.orders.change-requests.store');
    });
    Route::post('/call-waiter', [Customer\OrderStatusController::class, 'requestHelp'])->name('customer.help.request');
    Route::get('/bill', [Customer\BillController::class, 'show'])->name('customer.bill');
    Route::post('/bill/request', [Customer\BillController::class, 'requestBill'])->name('customer.bill.request');
    // Diner declares they paid by bank transfer → creates a pending-transfer the
    // cashier verifies against the bank app (no payment is posted until verified).
    Route::post('/bill/transfer', [Customer\BillController::class, 'declareTransfer'])->name('customer.bill.transfer');
});
