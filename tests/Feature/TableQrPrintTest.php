<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Lookup;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableQrPrintTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'qr-print',
            'name' => 'فرع الاختبار',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        Role::create([
            'name' => 'admin',
            'label' => 'مدير النظام',
            'is_system' => true,
        ]);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'مدير الاختبار',
            'username' => 'qr_print_admin',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'status' => 'active',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_table_qr_print_is_a_scannable_multi_size_table_card(): void
    {
        $zone = Lookup::create([
            'group' => 'zones',
            'code' => 'inside',
            'label' => 'داخلي',
            'is_active' => true,
            'display_order' => 1,
        ]);
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => '13',
            'name' => 'قرب النافذة',
            'capacity' => 4,
            'zone_lookup_id' => $zone->id,
            'status' => 'available',
            'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.tables.qr-print', $table))
            ->assertOk()
            ->assertViewIs('admin.tables.qr-print')
            ->assertViewHas('qrUrl', $table->qrUrl())
            ->assertSee('بطاقة طاولة 13')
            ->assertSee('فرع الاختبار')
            ->assertSee('داخلي')
            ->assertSee('قرب النافذة')
            ->assertSee('data-paper-choice="card"', false)
            ->assertSee('data-paper-choice="a5"', false)
            ->assertSee('data-paper-choice="a4"', false)
            ->assertSee('data-print-document', false)
            ->assertSee('--sheet-margin: 7mm;', false)
            ->assertSee('--print-card-height: 194mm;', false)
            ->assertSee('--print-qr-size: 70mm;', false)
            ->assertSee('grid-template-rows: auto minmax(0, 1fr) auto;', false)
            ->assertSee('height: var(--print-card-height) !important;', false)
            ->assertSee('<svg', false)
            ->assertSee($table->qrUrl());
    }

    public function test_qr_endpoints_require_a_user_who_can_view_the_table(): void
    {
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => '14',
            'capacity' => 4,
            'status' => 'available',
            'active' => true,
        ]);

        $this->get(route('admin.tables.qr-print', $table))->assertRedirect();
        $this->get(route('admin.tables.qr', $table))->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('admin.tables.qr', $table))
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml');
    }
}
