<?php

namespace Tests\Feature;

use App\Helpers\Brand;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Staff login page: Vue/Inertia shell plus the server-owned authentication
 * rules shared by every role.
 */
class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    /** A fresh DB redirects /login to first-run setup — seed a real install. */
    protected function seedInstall(): User
    {
        $branch = Branch::create(['code' => 'lg', 'name' => 'LG', 'is_active' => true]);
        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        $user = User::create([
            'name' => 'w', 'username' => 'login_smoke', 'password' => bcrypt('secret123'),
            'status' => 'active', 'primary_branch_id' => $branch->id, 'role' => 'waiter',
        ]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    public function test_the_login_page_renders_lean_and_self_contained(): void
    {
        $this->seedInstall();

        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('brand.name', Brand::name())
                ->where('routes.login', route('login.store'))
                ->where('routes.forgotPassword', route('password.request'))
                ->where('oldUsername', ''));

        $html = $response->getContent();

        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html,
            'the login page must never depend on an external CDN — it has to work when the internet is down');
        $this->assertStringNotContainsString(__('ui.auth.send_verification_code'), $html,
            'the fake inline forgot-password panel (alert() button) must stay dead');
        $this->assertFileExists(resource_path('js/Pages/Auth/Login.vue'));
        $this->assertFileDoesNotExist(resource_path('views/auth/login.blade.php'));
    }

    public function test_forgot_password_is_a_vue_page_in_the_same_auth_experience(): void
    {
        $this->seedInstall();

        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ForgotPassword')
                ->where('brand.name', Brand::name())
                ->where('routes.submit', route('password.email'))
                ->where('routes.login', route('login'))
                ->where('oldIdentifier', ''));

        $this->assertFileExists(resource_path('js/Pages/Auth/ForgotPassword.vue'));
        $this->assertFileDoesNotExist(resource_path('views/auth/forgot-password.blade.php'));
    }

    public function test_a_staff_member_can_log_in_from_the_new_form(): void
    {
        $user = $this->seedInstall();

        $this->post(route('login.store'), [
            'username' => 'login_smoke',
            'password' => 'secret123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_inertia_login_crosses_to_the_admin_document_with_a_full_visit(): void
    {
        $user = $this->seedInstall();

        $this->withHeader('X-Inertia', 'true')
            ->post(route('login.store'), [
                'username' => 'login_smoke',
                'password' => 'secret123',
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_staff_member_can_log_in_with_a_normalized_mobile_number(): void
    {
        $user = $this->seedInstall();
        $user->update(['phone' => '0599123456']);

        $this->post(route('login.store'), [
            'username' => '٠٥٩٩-١٢٣-٤٥٦',
            'password' => 'secret123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_duplicate_staff_mobile_numbers_never_choose_an_account_silently(): void
    {
        $first = $this->seedInstall();
        $first->update(['phone' => '0599123456']);
        $second = User::create([
            'name' => 'w2',
            'username' => 'login_smoke_2',
            'phone' => '0599123456',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'primary_branch_id' => $first->primary_branch_id,
            'role' => 'waiter',
        ]);
        $second->branches()->attach($first->primary_branch_id);

        $this->post(route('login.store'), [
            'username' => '0599123456',
            'password' => 'secret123',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_invalid_login_returns_the_same_generic_message_and_keeps_the_identifier(): void
    {
        $this->seedInstall();

        $this->from(route('login'))->post(route('login.store'), [
            'username' => '  login_smoke  ',
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => __('ui.auth.invalid_credentials')])
            ->assertSessionHasInput('username', 'login_smoke');

        $this->assertGuest();
    }

    public function test_login_component_keeps_password_and_processing_inside_the_vue_form(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Auth/Login.vue'));

        $this->assertStringContainsString('useForm({', $source);
        $this->assertStringContainsString('form.post(props.routes.login', $source);
        $this->assertStringContainsString("form.reset('password')", $source);
        $this->assertStringContainsString('const canSubmit = computed(() => (', $source);
        $this->assertStringContainsString(':disabled="!canSubmit"', $source);
        $this->assertStringContainsString('@keydown.enter.prevent="passwordInput?.focus()"', $source);
        $this->assertStringContainsString("@input=\"form.clearErrors('username')\"", $source);
        $this->assertStringContainsString("@input=\"form.clearErrors('password')\"", $source);
        $this->assertStringContainsString("'is-processing': form.processing", $source);
        $this->assertStringContainsString("showPassword ? 'text' : 'password'", $source);
        $this->assertStringContainsString('class="identity-input"', $source);
        $this->assertStringContainsString('class="password-input"', $source);
        $this->assertSame(2, substr_count($source, 'dir="ltr"'));
        $this->assertStringContainsString('.input-shell input.identity-input,', $source);
        $this->assertStringContainsString('.input-shell input.password-input { direction: ltr;', $source);
        $this->assertStringContainsString("getModifierState?.('CapsLock')", $source);
        $this->assertStringContainsString('0592632026 أو username', $source);
        $this->assertStringNotContainsString('demoTrial', $source);
        $this->assertStringNotContainsString('هذه نسخة تجريبية', $source);
        $this->assertStringNotContainsString('window.location', $source);
    }
}
