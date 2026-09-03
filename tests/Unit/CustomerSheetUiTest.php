<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CustomerSheetUiTest extends TestCase
{
    public function test_customer_sheet_is_isolated_accessible_and_phone_safe_in_rtl(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/js/Components/WaiterPos/CustomerSheet.vue');

        $this->assertIsString($source);
        $this->assertStringContainsString('<Teleport to="body">', $source);
        $this->assertStringContainsString('aria-modal="true"', $source);
        $this->assertStringContainsString('class="customer-create-option"', $source);
        $this->assertStringContainsString('dir="ltr"', $source);
        $this->assertStringContainsString('autocomplete="tel"', $source);
        $this->assertStringContainsString('@submit.prevent="submitSearch"', $source);
        $this->assertStringNotContainsString('class="toggle"', $source);
    }

    public function test_legacy_bundle_has_a_deployment_safe_toggle_fix(): void
    {
        $root = dirname(__DIR__, 2);
        $css = file_get_contents($root.'/public/assets/dashtic/css/relax-components.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('.sheet-backdrop:has(> .sheet label.toggle)', $css);
        $this->assertStringContainsString('label.toggle > span::before', $css);
        $this->assertStringContainsString('content: none !important;', $css);
    }
}
