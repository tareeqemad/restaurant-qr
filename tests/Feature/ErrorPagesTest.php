<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_404_is_polite_and_does_not_send_the_customer_to_admin(): void
    {
        config(['app.debug' => false]);

        $this->get('/a-page-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('لم نجد هذه الصفحة')
            ->assertSee('الصفحة الرئيسية')
            ->assertDontSee('العودة للوحة التحكم');
    }

    public function test_authenticated_admin_404_offers_the_dashboard(): void
    {
        config(['app.debug' => false]);
        $branch = Branch::create(['code' => 'errors', 'name' => 'Errors', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $user = User::create([
            'name' => 'Error Admin',
            'username' => 'error-admin',
            'role' => 'admin',
            'status' => 'active',
            'password' => bcrypt('password'),
            'primary_branch_id' => $branch->id,
        ]);
        $user->branches()->attach($branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $this->actingAs($user)
            ->get('/admin/a-page-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('لم نجد هذه الصفحة')
            ->assertSee('لوحة التحكم');
    }

    public function test_retry_error_pages_use_safe_shared_actions(): void
    {
        foreach (['errors.419', 'errors.500', 'errors.503'] as $view) {
            $html = view($view)->render();

            $this->assertStringNotContainsString('href="javascript:', $html);
            $this->assertStringNotContainsString('onclick=', $html);
            $this->assertStringContainsString('data-error-reload', $html);
            $this->assertStringContainsString("querySelectorAll('[data-error-reload]')", $html);
        }
    }

    public function test_error_page_displays_the_same_request_reference_used_by_logging(): void
    {
        request()->attributes->set('request_reference', 'trace9a8b7c6');

        $html = view('errors.500')->render();

        $this->assertStringContainsString('trace9a8b7c6', $html);
    }

    public function test_normal_web_responses_expose_a_searchable_request_reference_header(): void
    {
        $response = $this->get('/forgot-password')->assertOk();
        $reference = $response->headers->get('X-Request-Reference');

        $this->assertNotNull($reference);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $reference);
    }
}
