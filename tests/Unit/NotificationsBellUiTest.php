<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NotificationsBellUiTest extends TestCase
{
    public function test_header_notifications_are_anchored_compact_and_accessible(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/js/Components/AdminShell/NotificationsBell.vue');

        $this->assertIsString($source);
        $this->assertStringContainsString('aria-controls="notifications-popover"', $source);
        $this->assertStringContainsString('role="dialog"', $source);
        $this->assertStringContainsString('class="notifications-scroll"', $source);
        $this->assertStringContainsString('كل شيء تحت السيطرة', $source);
        $this->assertStringContainsString('max-height: min(58vh, 430px);', $source);
        $this->assertStringContainsString('width: min(390px, calc(100vw - 1rem)) !important;', $source);
        $this->assertStringContainsString('position: fixed !important;', $source);
        $this->assertStringNotContainsString('<a href="#" class="header-link"', $source);
    }
}
