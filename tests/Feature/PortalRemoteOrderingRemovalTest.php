<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PortalRemoteOrderingRemovalTest extends TestCase
{
    public function test_remote_ordering_routes_are_not_registered(): void
    {
        $remoteRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn (?string $name) => $name !== null && str_starts_with($name, 'portal.order.'));

        $this->assertCount(0, $remoteRoutes);
    }

    public function test_qr_menu_routes_remain_registered(): void
    {
        $this->assertTrue(Route::has('customer.menu.open'));
        $this->assertFalse(Route::has('customer.menu'));
    }
}
