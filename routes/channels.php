<?php

use App\Models\TableSession;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Private per-user channel (default Laravel pattern)
Broadcast::channel('App.Models.User.{id}', fn($user, $id) => (int) $user->id === (int) $id);

// Waiters hall — all waiters/managers see new pending orders.
// Chef + bartender are in too: the KDS boards subscribe to this channel
// for their live refresh, and those are exactly the people at the pass.
Broadcast::channel('waiters', function (User $user) {
    return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'waiter', 'chef', 'bartender']);
});

// Cashier
Broadcast::channel('cashiers', function (User $user) {
    return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier', 'waiter']);
});

// Per-station channels (kitchen, bar, dessert, etc.)
Broadcast::channel('station.{code}', function (User $user, string $code) {
    return $user->canAccessStation($code);
});

// Customer session channel (public presence via token)
Broadcast::channel('session.{token}', function ($user, string $token) {
    // Allow any authenticated staff OR verify token matches active session
    if ($user && $user->canAccessAdmin()) return true;
    return TableSession::where('token', $token)->where('status', 'active')->exists();
});
