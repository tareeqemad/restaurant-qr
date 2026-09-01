<?php

use App\Http\Controllers\Admin\CashierVueController;
use Illuminate\Support\Facades\Route;

// Loaded inside routes/admin.php, which already owns the /admin prefix,
// admin. name prefix, and operational middleware. The cashier page is now
// the official Vue screen; JSON commands live under /cashier/api.
Route::get('cashier', [CashierVueController::class, 'index'])->name('cashier.index');
Route::get('cashier/session/{session}', [CashierVueController::class, 'show'])->name('cashier.show');

Route::prefix('cashier/api')->name('cashier.api.')->group(function () {
    Route::get('state', [CashierVueController::class, 'state'])->name('state');
    Route::post('sessions/{session}/invoice', [CashierVueController::class, 'issueSession'])->name('sessions.invoice');
    Route::post('orders/{order}/invoice', [CashierVueController::class, 'issueOrder'])->name('orders.invoice');
    Route::post('invoices/{invoice}/payments', [CashierVueController::class, 'pay'])->name('payments.store');
    Route::post('invoices/{invoice}/refunds', [CashierVueController::class, 'refund'])->name('refunds.store');
    Route::post('sessions/{session}/discounts', [CashierVueController::class, 'discountSession'])->name('sessions.discounts.store');
    Route::post('orders/{order}/discounts', [CashierVueController::class, 'discountOrder'])->name('orders.discounts.store');
    Route::post('discounts/{discount}/remove', [CashierVueController::class, 'removeDiscount'])->name('discounts.remove');
    Route::post('invoices/{invoice}/splits', [CashierVueController::class, 'splitInvoice'])->name('splits.store');
    Route::post('invoices/{invoice}/splits/clear', [CashierVueController::class, 'clearSplits'])->name('splits.clear');
    Route::post('invoices/{invoice}/splits/{split}/pay', [CashierVueController::class, 'paySplit'])->name('splits.pay');
    Route::post('sessions/{session}/transfers', [CashierVueController::class, 'recordTransfer'])->name('transfers.store');
    Route::post('transfers/{transfer}/verify', [CashierVueController::class, 'verifyTransfer'])->name('transfers.verify');
    Route::post('transfers/{transfer}/reject', [CashierVueController::class, 'rejectTransfer'])->name('transfers.reject');
    Route::post('payments/{payment}/void', [CashierVueController::class, 'voidPayment'])->name('payments.void');
    Route::post('invoices/{invoice}/settle-on-account', [CashierVueController::class, 'settleOnAccount'])->name('invoices.settle-on-account');
    Route::post('invoices/{invoice}/unpark', [CashierVueController::class, 'unparkInvoice'])->name('invoices.unpark');
    Route::post('invoices/{invoice}/writeoff', [CashierVueController::class, 'writeOffInvoice'])->name('invoices.writeoff');
    Route::post('invoices/{invoice}/cancel', [CashierVueController::class, 'cancelInvoice'])->name('invoices.cancel');
    Route::post('sessions/{session}/close-empty', [CashierVueController::class, 'closeEmptySession'])->name('sessions.close-empty');
    Route::post('orders', [CashierVueController::class, 'createOrder'])->name('orders.store');
    Route::post('customers', [CashierVueController::class, 'createCustomer'])->name('customers.store');
    Route::get('customers/lookup', [CashierVueController::class, 'lookupCustomer'])->name('customers.lookup');
    Route::post('customers/advances', [CashierVueController::class, 'depositCustomerAdvance'])->name('customers.advances.store');
    Route::post('customers/advances/{transaction}/reverse', [CashierVueController::class, 'reverseCustomerAdvance'])->name('customers.advances.reverse');
    Route::post('orders/{order}/approve', [CashierVueController::class, 'approveOrder'])->name('orders.approve');
});
