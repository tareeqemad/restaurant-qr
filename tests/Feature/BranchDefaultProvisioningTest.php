<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Day-zero trap: BranchController@store used to provision NOTHING, so a
 * fresh branch had no storage location (the first ingredient form dead-ends
 * — location is required) and no station (no menu item could ever reach a
 * KDS). Store must now seed a default «المخزن الرئيسي» location and a
 * «المطبخ» station atomically with the branch itself.
 */
class BranchDefaultProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Bootstrap branch so middleware/branch-context behaves like a live
        // install; the branches we create through HTTP below are separate.
        $this->branch = Branch::create(['code' => 'hq', 'name' => 'HQ', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'super_admin', 'label' => 'Super Admin', 'is_system' => true]);
        $this->superAdmin = User::create([
            'name' => 'SA', 'username' => 'sa_bp', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);
        $this->superAdmin->branches()->attach($this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_store_provisions_default_storage_location_and_station(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.branches.store'), [
                'code' => 'gaza-2', 'name' => 'فرع غزة ٢', 'is_active' => 1,
                'owners' => [$this->ownerPayload('مالك غزة')],
            ])
            ->assertRedirect(route('admin.branches.index'));

        $branch = Branch::where('code', 'gaza-2')->firstOrFail();

        $location = StorageLocation::withoutGlobalScopes()
            ->where('branch_id', $branch->id)->first();
        $this->assertNotNull($location, 'a fresh branch must get a default storage location');
        $this->assertSame('المخزن الرئيسي', $location->name);
        $this->assertSame('main-gaza-2', $location->code);
        $this->assertTrue((bool) $location->is_default);
        $this->assertTrue((bool) $location->active);

        $station = Station::withoutGlobalScopes()
            ->where('branch_id', $branch->id)->first();
        $this->assertNotNull($station, 'a fresh branch must get a default kitchen station');
        $this->assertSame('kitchen', $station->code);
        $this->assertSame('المطبخ', $station->name);
        $this->assertTrue((bool) $station->active);
        $this->assertSame($location->id, $station->storage_location_id,
            'the kitchen station should point at the provisioned default location');
    }

    /** Each branch gets exactly ONE of each default — never duplicates. */
    public function test_provisioning_is_per_branch_and_never_duplicates(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.branches.store'), [
            'code' => 'north', 'name' => 'فرع الشمال', 'is_active' => 1,
            'owners' => [$this->ownerPayload('مالك الشمال')],
        ]);
        $this->actingAs($this->superAdmin)->post(route('admin.branches.store'), [
            'code' => 'south', 'name' => 'فرع الجنوب', 'is_active' => 1,
            'owners' => [$this->ownerPayload('مالك الجنوب')],
        ]);

        foreach (['north', 'south'] as $code) {
            $branch = Branch::where('code', $code)->firstOrFail();
            $this->assertSame(1, StorageLocation::withoutGlobalScopes()
                ->where('branch_id', $branch->id)->count());
            $this->assertSame(1, Station::withoutGlobalScopes()
                ->where('branch_id', $branch->id)->count());
        }

        // Globally-unique location codes must not collide across branches.
        $this->assertSame(
            2,
            StorageLocation::withoutGlobalScopes()
                ->whereIn('code', ['main-north', 'main-south'])->count()
        );
    }

    private function ownerPayload(string $name): array
    {
        return [
            'owner_type' => 'person',
            'name' => $name,
            'ownership_percentage' => 100,
            'is_primary' => true,
            'is_authorized_signatory' => true,
        ];
    }
}
