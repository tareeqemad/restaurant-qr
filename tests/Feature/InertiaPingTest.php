<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase-0 gate (MIGRATION-PILOT.md §5): the full Inertia chain must work —
 * route → auth middleware → HandleInertiaRequests → inertia.blade root →
 * page component resolution — before sol's Phase-2 lane opens.
 */
class InertiaPingTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $branch = Branch::create(['code' => 'iv', 'name' => 'IV', 'is_active' => true]);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);
        $user = User::create([
            'name' => 'a', 'username' => 'inertia_admin', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $branch->id, 'role' => 'admin',
        ]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    public function test_the_ping_page_serves_a_vue_component_with_shared_props(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.inertia.ping'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ping')
                ->has('now')
                ->where('auth.user.role', 'admin')
                ->has('theme'));
    }

    /** The pilot rides the SAME auth wall as the rest of the admin. */
    public function test_guests_are_bounced_to_login(): void
    {
        $this->get('/admin/inertia-ping')->assertRedirect();
    }
}
