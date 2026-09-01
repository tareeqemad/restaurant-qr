<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BusinessOwner;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['code' => 'hq', 'name' => 'الرئيسي', 'is_active' => true]);
        BranchContext::set($branch->id);
        Role::create(['name' => 'super_admin', 'label' => 'مدير النظام', 'is_system' => true]);
        $this->admin = User::create([
            'name' => 'مدير النظام', 'username' => 'owner_admin', 'password' => bcrypt('secret123'),
            'status' => 'active', 'role' => 'super_admin', 'primary_branch_id' => $branch->id,
        ]);
        $this->admin->branches()->attach($branch->id, ['is_primary' => true, 'joined_at' => now()]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_one_owner_record_can_be_reused_for_two_branches(): void
    {
        $first = $this->actingAs($this->admin)->post(route('admin.branches.store'), [
            'code' => 'south',
            'name' => 'فرع الجنوب',
            'is_active' => true,
            'legal' => [
                'registered_name' => 'مطاعم الهدوء المسجلة',
                'tax_number' => 'TX-7788',
                'commercial_registration_number' => 'CR-21',
                'municipal_license_number' => 'ML-9',
            ],
            'owners' => [[
                'owner_type' => 'person',
                'name' => 'أحمد علي',
                'national_id' => '900001234',
                'phone' => '0592632026',
                'ownership_percentage' => 100,
                'title' => 'مالك',
                'is_primary' => true,
                'is_authorized_signatory' => true,
            ]],
        ]);
        $first->assertRedirect(route('admin.branches.index'));

        $owner = BusinessOwner::where('national_id', '900001234')->firstOrFail();
        $south = Branch::where('code', 'south')->firstOrFail();
        $this->assertDatabaseHas('branch_legal_profiles', [
            'branch_id' => $south->id,
            'tax_number' => 'TX-7788',
            'commercial_registration_number' => 'CR-21',
        ]);

        $second = $this->actingAs($this->admin)->post(route('admin.branches.store'), [
            'code' => 'north',
            'name' => 'فرع الشمال',
            'is_active' => true,
            'owners' => [[
                'id' => $owner->id,
                'owner_type' => $owner->owner_type,
                'name' => $owner->name,
                'national_id' => $owner->national_id,
                'phone' => $owner->phone,
                'ownership_percentage' => 60,
                'title' => 'شريك رئيسي',
                'is_primary' => true,
                'is_authorized_signatory' => true,
            ]],
        ]);
        $second->assertRedirect(route('admin.branches.index'));

        $north = Branch::where('code', 'north')->firstOrFail();
        $this->assertSame(1, BusinessOwner::where('national_id', '900001234')->count());
        $this->assertDatabaseHas('branch_ownerships', ['branch_id' => $south->id, 'business_owner_id' => $owner->id]);
        $this->assertDatabaseHas('branch_ownerships', ['branch_id' => $north->id, 'business_owner_id' => $owner->id]);
        $this->assertCount(2, $owner->fresh()->branches);
    }

    public function test_a_branch_can_have_several_owners_but_not_more_than_one_hundred_percent(): void
    {
        $valid = $this->actingAs($this->admin)->post(route('admin.branches.store'), [
            'code' => 'partners',
            'name' => 'فرع الشركاء',
            'is_active' => true,
            'owners' => [
                ['owner_type' => 'person', 'name' => 'الشريك الأول', 'ownership_percentage' => 55, 'is_primary' => true],
                ['owner_type' => 'person', 'name' => 'الشريك الثاني', 'ownership_percentage' => 45],
            ],
        ]);
        $valid->assertRedirect(route('admin.branches.index'));
        $branch = Branch::where('code', 'partners')->firstOrFail();
        $this->assertCount(2, $branch->owners);

        $invalid = $this->actingAs($this->admin)->from(route('admin.branches.create'))->post(route('admin.branches.store'), [
            'code' => 'bad-shares',
            'name' => 'نسب خاطئة',
            'is_active' => true,
            'owners' => [
                ['owner_type' => 'person', 'name' => 'أ', 'ownership_percentage' => 70, 'is_primary' => true],
                ['owner_type' => 'person', 'name' => 'ب', 'ownership_percentage' => 40],
            ],
        ]);
        $invalid->assertRedirect(route('admin.branches.create'))->assertSessionHasErrors('owners');
        $this->assertDatabaseMissing('branches', ['code' => 'bad-shares']);
    }
}
