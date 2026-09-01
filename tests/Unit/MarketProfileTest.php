<?php

namespace Tests\Unit;

use App\Support\MarketProfile;
use Tests\TestCase;

class MarketProfileTest extends TestCase
{
    public function test_application_configuration_is_arabic_only(): void
    {
        $this->assertSame('ar', config('app.locale'));
        $this->assertSame('ar', config('app.fallback_locale'));
        $this->assertSame('ar_SA', config('app.faker_locale'));
        $this->assertArrayNotHasKey('locale', config('market'));
        $this->assertArrayNotHasKey('direction', config('market'));
    }

    public function test_restaurant_can_configure_currency_and_arabic_invoice_labels(): void
    {
        config([
            'restaurant.currency' => 'USD',
            'restaurant.currency_symbol' => '$',
            'restaurant.tax.label' => 'ضريبة الفاتورة',
            'restaurant.service_charge.label' => 'رسوم الخدمة',
        ]);

        $this->assertSame('USD', MarketProfile::currency());
        $this->assertSame('$', MarketProfile::currencySymbol());
        $this->assertSame('ضريبة الفاتورة', MarketProfile::taxLabel());
        $this->assertSame('رسوم الخدمة', MarketProfile::serviceLabel());
    }

    public function test_env_example_has_no_market_or_language_switch(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertIsString($envExample);
        $this->assertStringNotContainsString('MARKET_PROFILE=', $envExample);
        $this->assertStringNotContainsString('MARKET_LOCALE=', $envExample);
        $this->assertStringNotContainsString('APP_LOCALE=', $envExample);
    }
}
