<?php

use App\Http\Controllers\Portal;
use Illuminate\Support\Facades\Route;

/**
 * Customer-facing portal — separate from staff /admin and from the
 * QR-table-session flow in routes/customer.php. Authenticated against
 * the `customer` guard.
 */

// Public auth pages
// throttle:5,1 on login/register limits brute force + bot signups to 5/min/IP.
// Without this, the digit-only PIN (6 digits, 10⁶ space) would be brute-
// forceable in seconds against the customer phone column.
Route::middleware('guest:customer')->group(function () {
    Route::get   ('/login',    [Portal\AuthController::class, 'showLogin'])->name('portal.login');
    Route::post  ('/login',    [Portal\AuthController::class, 'login'])
        ->middleware('throttle:5,1');
    Route::get   ('/register', [Portal\AuthController::class, 'showRegister'])->name('portal.register');
    Route::post  ('/register', [Portal\AuthController::class, 'register'])
        ->middleware('throttle:10,1');
});

// Authenticated portal
Route::middleware('auth:customer')->group(function () {
    Route::post('/logout', [Portal\AuthController::class, 'logout'])->name('portal.logout');

    Route::get('/', [Portal\DashboardController::class, 'index'])->name('portal.dashboard');

    // Reservations
    Route::get   ('/reservations',                  [Portal\ReservationController::class, 'index'])->name('portal.reservations.index');
    Route::get   ('/reservations/new',              [Portal\ReservationController::class, 'create'])->name('portal.reservations.create');
    Route::post  ('/reservations',                  [Portal\ReservationController::class, 'store'])->name('portal.reservations.store');
    Route::post  ('/reservations/{reservation}/cancel',
        [Portal\ReservationController::class, 'cancel'])->name('portal.reservations.cancel');

    // Customer notification inbox (announcements + future system messages)
    Route::get ('/notifications',                 [Portal\NotificationController::class, 'index'])->name('portal.notifications.index');
    Route::get ('/notifications/unread-count',    [Portal\NotificationController::class, 'unreadCount'])->name('portal.notifications.unread-count');
    Route::post('/notifications/{id}/read',       [Portal\NotificationController::class, 'markRead'])->name('portal.notifications.read');
    Route::post('/notifications/read-all',        [Portal\NotificationController::class, 'markAllRead'])->name('portal.notifications.read-all');

    // Reviews
    Route::get ('/reviews',                       [Portal\ReviewController::class, 'index'])->name('portal.reviews.index');
    Route::get ('/reviews/{reservation}/new',     [Portal\ReviewController::class, 'create'])->name('portal.reviews.create');
    Route::post('/reviews/{reservation}',         [Portal\ReviewController::class, 'store'])->name('portal.reviews.store');
    Route::delete('/reviews/{review}',            [Portal\ReviewController::class, 'destroy'])->name('portal.reviews.destroy');

    // Remote ordering — pickup / delivery from the customer portal.
    // Branch is part of the URL so the cart key + menu scope stay explicit;
    // a customer can order from any active branch, not just their default.
    Route::get ('/order',                              [Portal\OrderController::class, 'pickBranch'])->name('portal.order.branches');
    Route::get ('/order/history',                      [Portal\OrderController::class, 'index'])->name('portal.order.history');
    Route::get ('/order/placed/{order}',               [Portal\OrderController::class, 'placed'])->name('portal.order.placed');

    // Customer self-edit — only takes effect while the order is pending.
    // Routes are placed BEFORE the {branch} catch-all so the literal
    // segments don't get parsed as branch slugs.
    Route::get ('/order/{order}/edit',                 [Portal\OrderController::class, 'editForm'])->name('portal.order.edit');
    Route::post('/order/{order}/edit',                 [Portal\OrderController::class, 'update'])->name('portal.order.update');
    Route::post('/order/{order}/cancel',               [Portal\OrderController::class, 'cancel'])->name('portal.order.cancel');

    Route::get ('/order/{branch}',                     [Portal\OrderController::class, 'menu'])->name('portal.order.menu');
    Route::post('/order/{branch}/cart/add',            [Portal\OrderController::class, 'addToCart'])->name('portal.order.cart.add');
    Route::post('/order/{branch}/cart/remove',         [Portal\OrderController::class, 'removeFromCart'])->name('portal.order.cart.remove');
    Route::get ('/order/{branch}/checkout',            [Portal\OrderController::class, 'checkout'])->name('portal.order.checkout');
    Route::post('/order/{branch}/place',               [Portal\OrderController::class, 'place'])->name('portal.order.place');
});
