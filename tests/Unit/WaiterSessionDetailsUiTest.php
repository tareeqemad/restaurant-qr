<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WaiterSessionDetailsUiTest extends TestCase
{
    public function test_waiter_balance_has_clear_entry_points_and_an_itemized_sheet(): void
    {
        $root = dirname(__DIR__, 2);
        $page = file_get_contents($root.'/resources/js/Pages/WaiterPos/Show.vue');
        $bar = file_get_contents($root.'/resources/js/Components/WaiterPos/SessionBar.vue');
        $sheet = file_get_contents($root.'/resources/js/Components/WaiterPos/SessionDetailsSheet.vue');
        $api = file_get_contents($root.'/resources/js/waiterPosApi.js');

        $this->assertIsString($page);
        $this->assertIsString($bar);
        $this->assertIsString($sheet);
        $this->assertIsString($api);
        $this->assertStringContainsString('عرض تفاصيل الحساب', $page);
        $this->assertStringContainsString('@open-session="sessionDetailsOpen = true"', $page);
        $this->assertStringContainsString("'open-session': () => true", $bar);
        $this->assertStringContainsString("emit('open-session')", $bar);
        $this->assertStringContainsString('<Teleport to="body">', $sheet);
        $this->assertStringContainsString('مِمَّ يتكوّن الحساب؟', $sheet);
        $this->assertStringContainsString("item.modifiers.join('، ')", $sheet);
        $this->assertStringContainsString("item.exclusions.join('، ')", $sheet);
        $this->assertStringContainsString('سبب الإلغاء', $sheet);
        $this->assertStringContainsString("emit('cancel-item'", $sheet);
        $this->assertStringContainsString('export function cancelSessionItem', $api);
    }
}
