<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Notifications\AnnouncementForCustomer;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RetiredCustomerPortalTest extends TestCase
{
    public function test_no_customer_portal_routes_are_registered(): void
    {
        $portalRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn (?string $name) => $name !== null && str_starts_with($name, 'portal.'));

        $this->assertEmpty($portalRoutes->all());
    }

    public function test_customer_announcement_never_builds_a_link_to_the_retired_portal(): void
    {
        $plain = new Announcement([
            'title' => 'إعلان',
            'body' => 'تفاصيل الإعلان',
        ]);

        $plainNotification = new AnnouncementForCustomer($plain);

        $this->assertNull($plainNotification->actionUrl());
        $this->assertNull($plainNotification->actionLabel());

        $linked = new Announcement([
            'title' => 'إعلان برابط',
            'body' => 'تفاصيل الإعلان',
            'cta_text' => 'افتح العرض',
            'cta_url' => 'https://example.com/offer',
        ]);

        $linkedNotification = new AnnouncementForCustomer($linked);

        $this->assertSame('https://example.com/offer', $linkedNotification->actionUrl());
        $this->assertSame('افتح العرض', $linkedNotification->actionLabel());
    }
}
