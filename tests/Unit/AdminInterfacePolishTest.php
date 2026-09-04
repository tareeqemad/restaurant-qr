<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminInterfacePolishTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    private function source(string $path): string
    {
        $source = file_get_contents($this->root.'/'.$path);
        $this->assertIsString($source);

        return $source;
    }

    public function test_every_admin_validation_error_has_an_accessible_summary(): void
    {
        $component = $this->source('resources/js/Components/Ui/FormErrorSummary.vue');
        $layout = $this->source('resources/js/Layouts/AdminLayout.vue');

        $this->assertStringContainsString('role="alert"', $component);
        $this->assertStringContainsString('aria-live="assertive"', $component);
        $this->assertStringContainsString('new Set(', $component);
        $this->assertStringContainsString("import FormErrorSummary from '../Components/Ui/FormErrorSummary.vue'", $layout);
        $this->assertStringContainsString(':errors="page.props.errors"', $layout);
    }

    public function test_notifications_use_inertia_and_expose_loading_and_failure_states(): void
    {
        $source = $this->source('resources/js/Pages/Admin/Notifications/Index.vue');

        $this->assertStringContainsString('router.visit(item.action_url', $source);
        $this->assertStringNotContainsString('window.location.href', $source);
        $this->assertStringContainsString(':aria-busy="loading"', $source);
        $this->assertStringContainsString('role="alert"', $source);
        $this->assertStringContainsString('useToast', $source);
        $this->assertStringContainsString('لا توجد نتائج مطابقة', $source);
    }

    public function test_dense_operational_pages_have_laptop_and_tablet_fallbacks(): void
    {
        $dashboard = $this->source('resources/js/Pages/Admin/Inventory/Dashboard.vue');
        $index = $this->source('resources/js/Pages/Admin/PurchaseOrders/Index.vue');
        $receive = $this->source('resources/js/Pages/Admin/PurchaseOrders/Receive.vue');

        $this->assertStringContainsString('@media(min-width:1181px) and (max-width:1440px)', $dashboard);
        $this->assertStringContainsString('class="po-filters"', $index);
        $this->assertStringContainsString('class="po-loading"', $index);
        $this->assertStringContainsString('min-width: 1020px', $index);
        $this->assertStringContainsString('min-width: 1040px', $receive);
        $this->assertStringContainsString('position: sticky', $receive);
    }

    public function test_end_of_day_tables_scroll_on_screen_and_expand_for_print(): void
    {
        $source = $this->source('resources/js/Pages/Admin/Reports/EndOfDay.vue');

        $this->assertGreaterThanOrEqual(4, substr_count($source, 'class="eod-table-scroll'));
        $this->assertStringContainsString('@media (max-width: 1399px)', $source);
        $this->assertStringContainsString('.eod-table-scroll { overflow: visible !important; }', $source);
        $this->assertStringContainsString('.eod-table-scroll .table { min-width: 0 !important; }', $source);
    }

    public function test_cart_removal_uses_the_application_confirmation_dialog(): void
    {
        $source = $this->source('resources/js/Components/WaiterPos/CartLine.vue');
        $page = $this->source('resources/js/Pages/WaiterPos/Show.vue');

        $this->assertStringContainsString('useConfirm', $source);
        $this->assertStringNotContainsString('window.confirm', $source);
        $this->assertStringContainsString("danger: true", $source);
        $this->assertStringContainsString("import ConfirmHost from '../../Components/Ui/ConfirmHost.vue'", $page);
        $this->assertStringContainsString('<ConfirmHost />', $page);
    }
}
