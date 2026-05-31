<?php

namespace Tests\Unit;

use App\Support\MarketProfile;
use Tests\TestCase;

class MarketProfileTest extends TestCase
{
    public function test_us_market_profile_uses_ltr_us_defaults(): void
    {
        config([
            'market.profile' => 'us',
            'market.locale' => 'en',
            'market.direction' => 'ltr',
            'market.timezone' => 'America/New_York',
            'restaurant.currency' => 'USD',
            'restaurant.currency_symbol' => '$',
            'restaurant.tax.label' => 'Sales tax',
            'restaurant.service_charge.label' => 'Gratuity',
        ]);

        $this->assertTrue(MarketProfile::isUs());
        $this->assertSame('en', MarketProfile::lang());
        $this->assertSame('ltr', MarketProfile::direction());
        $this->assertSame('assets/dashtic/libs/bootstrap/css/bootstrap.min.css', MarketProfile::bootstrapCssPath());
        $this->assertSame('USD', MarketProfile::currency());
        $this->assertSame('$', MarketProfile::currencySymbol());
        $this->assertSame('Sales tax', MarketProfile::taxLabel());
        $this->assertSame('Gratuity', MarketProfile::serviceLabel());
    }

    public function test_palestine_profile_uses_rtl_bootstrap(): void
    {
        config([
            'market.profile' => 'palestine',
            'market.locale' => 'ar',
            'market.direction' => 'rtl',
        ]);

        $this->assertFalse(MarketProfile::isUs());
        $this->assertSame('ar', MarketProfile::lang());
        $this->assertSame('rtl', MarketProfile::direction());
        $this->assertSame('assets/dashtic/libs/bootstrap/css/bootstrap.rtl.min.css', MarketProfile::bootstrapCssPath());
    }

    public function test_language_and_direction_follow_profile_not_standalone_overrides(): void
    {
        config([
            'market.profile' => 'us',
            'market.locale' => 'ar',
            'market.direction' => 'rtl',
        ]);

        $this->assertSame('en', MarketProfile::lang());
        $this->assertSame('ltr', MarketProfile::direction());
    }

    public function test_env_example_has_no_separate_language_switch(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertIsString($envExample);
        $this->assertStringNotContainsString('MARKET_LOCALE', $envExample);
        $this->assertStringNotContainsString('APP_LOCALE=', $envExample);
    }
}
