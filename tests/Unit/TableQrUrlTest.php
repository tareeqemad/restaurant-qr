<?php

namespace Tests\Unit;

use App\Models\Table;
use Tests\TestCase;

/**
 * Table::qrUrl() — the printed QR must resolve to the LAN address on a
 * local/on-prem server (so customers keep ordering when the internet is
 * down), and fall back to APP_URL for pure cloud deployments.
 */
class TableQrUrlTest extends TestCase
{
    private function table(string $token): Table
    {
        // Unsaved model — qrUrl() is pure string logic, no DB needed.
        return (new Table)->forceFill(['qr_token' => $token]);
    }

    public function test_falls_back_to_app_url_when_menu_base_url_is_unset(): void
    {
        config(['restaurant.menu_base_url' => null]);

        $this->assertSame(
            url('/menu/abc123'),
            $this->table('abc123')->qrUrl(),
        );
    }

    public function test_uses_local_menu_base_url_when_set(): void
    {
        config(['restaurant.menu_base_url' => 'http://relax.local']);

        $this->assertSame(
            'http://relax.local/menu/abc123',
            $this->table('abc123')->qrUrl(),
        );
    }

    public function test_trims_trailing_slash_and_whitespace_on_base_url(): void
    {
        config(['restaurant.menu_base_url' => '  http://192.168.1.10/  ']);

        $this->assertSame(
            'http://192.168.1.10/menu/tok',
            $this->table('tok')->qrUrl(),
        );
    }
}
