<?php

namespace Tests\Feature;

use App\Models\Allergen;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MenuAdminVueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'main',
            'name' => 'الفرع الرئيسي',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(
            ['name' => 'super_admin'],
            ['label' => 'مالك النظام', 'is_system' => true],
        );
        $this->owner = User::create([
            'name' => 'المالك',
            'username' => 'menu-owner',
            'password' => bcrypt('secret'),
            'status' => 'active',
            'role' => 'super_admin',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->owner->branches()->attach($this->branch->id, ['is_primary' => true]);
    }

    public function test_menu_management_routes_are_inertia_vue_pages(): void
    {
        $routes = [
            'admin.menu-items.index' => 'Admin/MenuItems/Index',
            'admin.menu-items.create' => 'Admin/MenuItems/Form',
            'admin.categories.index' => 'Admin/MenuCatalog/Categories',
            'admin.modifiers.index' => 'Admin/MenuCatalog/Modifiers',
            'admin.allergens.index' => 'Admin/MenuCatalog/Allergens',
            'admin.promotions.index' => 'Admin/MenuCatalog/Promotions',
            'admin.stations.index' => 'Admin/MenuCatalog/Stations',
        ];

        foreach ($routes as $routeName => $component) {
            $this->actingAs($this->owner)
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->has('navigation')
                );
        }
    }

    public function test_catalog_edit_routes_reuse_the_same_inline_workspace(): void
    {
        $category = Category::create(['name' => 'المشاوي', 'slug' => 'grill', 'active' => true]);
        $group = ModifierGroup::create([
            'name' => 'حجم الوجبة',
            'min_select' => 0,
            'max_select' => 1,
            'active' => true,
        ]);
        $allergen = Allergen::create(['code' => 'milk', 'name' => 'الحليب', 'active' => true]);
        $station = Station::create([
            'branch_id' => $this->branch->id,
            'code' => 'kitchen',
            'name' => 'المطبخ',
            'active' => true,
        ]);

        $cases = [
            [route('admin.categories.edit', $category), 'Admin/MenuCatalog/Categories', 'editor.category.id', $category->id],
            [route('admin.modifiers.edit', $group), 'Admin/MenuCatalog/Modifiers', 'editor.group.id', $group->id],
            [route('admin.allergens.edit', $allergen), 'Admin/MenuCatalog/Allergens', 'editor.allergen.id', $allergen->id],
            [route('admin.stations.edit', $station), 'Admin/MenuCatalog/Stations', 'editor.station.id', $station->id],
        ];

        foreach ($cases as [$url, $component, $prop, $id]) {
            $this->actingAs($this->owner)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where($prop, $id)
                );
        }
    }

    public function test_used_catalog_records_are_disabled_instead_of_being_deleted(): void
    {
        $category = Category::create(['name' => 'الأطباق', 'slug' => 'plates', 'active' => true]);
        $station = Station::create([
            'branch_id' => $this->branch->id,
            'code' => 'hot',
            'name' => 'الساخن',
            'active' => true,
        ]);
        $group = ModifierGroup::create([
            'name' => 'الإضافات',
            'min_select' => 0,
            'max_select' => 2,
            'active' => true,
        ]);
        $item = MenuItem::create([
            'category_id' => $category->id,
            'station_id' => $station->id,
            'name' => 'طبق اختبار',
            'price' => 10,
        ]);
        $item->modifierGroups()->attach($group->id);

        $this->actingAs($this->owner)
            ->delete(route('admin.categories.destroy', $category))
            ->assertSessionHas('error');
        $this->actingAs($this->owner)
            ->delete(route('admin.modifiers.destroy', $group))
            ->assertSessionHas('error');
        $this->actingAs($this->owner)
            ->delete(route('admin.stations.destroy', $station))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('modifier_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('stations', ['id' => $station->id]);
    }

    public function test_categories_workspace_ships_one_ordered_collection_with_inline_action_urls(): void
    {
        $station = Station::create([
            'branch_id' => $this->branch->id,
            'code' => 'kitchen',
            'name' => 'المطبخ',
            'active' => true,
        ]);
        $later = Category::create([
            'name' => 'الحلويات',
            'default_station_id' => $station->id,
            'display_order' => 20,
            'active' => false,
        ]);
        $first = Category::create([
            'name' => 'المشروبات',
            'display_order' => 10,
            'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MenuCatalog/Categories')
                ->has('categories', 2)
                ->where('categories.0.id', $first->id)
                ->where('categories.0.urls.toggle', route('admin.categories.toggle', $first))
                ->where('categories.0.urls.move', route('admin.categories.move', $first))
                ->where('categories.1.id', $later->id)
                ->where('categories.1.station', 'المطبخ')
                ->where('categories.1.active', false)
                ->where('can.update', true));
    }

    public function test_category_create_update_toggle_move_and_delete_are_json_first(): void
    {
        $station = Station::create([
            'branch_id' => $this->branch->id,
            'code' => 'bar',
            'name' => 'البار',
            'active' => true,
        ]);
        $first = Category::create([
            'name' => 'الأطباق',
            'display_order' => 10,
            'active' => true,
        ]);

        $created = $this->actingAs($this->owner)
            ->postJson(route('admin.categories.store'), [
                'name' => 'المشروبات',
                'description' => 'باردة وساخنة',
                'default_station_id' => $station->id,
                'color' => '#1f6b50',
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('category.name', 'المشروبات')
            ->assertJsonPath('category.station', 'البار')
            ->assertJsonPath('category.displayOrder', 20);

        $categoryId = $created->json('category.id');
        $category = Category::findOrFail($categoryId);

        $this->actingAs($this->owner)
            ->putJson(route('admin.categories.update', $category), [
                'name' => 'المشروبات الباردة',
                'description' => 'عصائر ومياه',
                'default_station_id' => $station->id,
                'color' => '#166534',
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('category.name', 'المشروبات الباردة')
            ->assertJsonPath('category.description', 'عصائر ومياه');

        $this->actingAs($this->owner)
            ->patchJson(route('admin.categories.toggle', $category))
            ->assertOk()
            ->assertJsonPath('category.active', false);

        $this->actingAs($this->owner)
            ->postJson(route('admin.categories.move', $category), ['direction' => 'up'])
            ->assertOk()
            ->assertJsonPath('categories.0.id', $category->id)
            ->assertJsonPath('categories.1.id', $first->id);

        $this->actingAs($this->owner)
            ->deleteJson(route('admin.categories.destroy', $category))
            ->assertOk()
            ->assertJsonPath('id', $category->id);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_category_json_delete_explains_why_a_used_category_is_protected(): void
    {
        $category = Category::create(['name' => 'المقليات', 'active' => true]);
        MenuItem::create([
            'category_id' => $category->id,
            'name' => 'بطاطا',
            'price' => 3,
        ]);

        $this->actingAs($this->owner)
            ->deleteJson(route('admin.categories.destroy', $category))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'القسم مرتبط بأصناف. عطّله أو انقل الأصناف أولاً.');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_category_cannot_receive_a_station_from_another_branch(): void
    {
        $otherBranch = Branch::create([
            'code' => 'other',
            'name' => 'فرع آخر',
            'is_active' => true,
        ]);
        $foreignStation = Station::create([
            'branch_id' => $otherBranch->id,
            'code' => 'foreign',
            'name' => 'محطة فرع آخر',
            'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('admin.categories.store'), [
                'name' => 'محاولة مزورة',
                'default_station_id' => $foreignStation->id,
                'color' => '#166534',
                'active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_station_id');

        $this->assertDatabaseMissing('categories', ['name' => 'محاولة مزورة']);
    }

    public function test_categories_vue_keeps_every_mutation_inside_the_mounted_page(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/MenuCatalog/Categories.vue'));
        $navigation = file_get_contents(resource_path('js/Components/MenuAdmin/MenuWorkspaceNav.vue'));

        $this->assertStringContainsString('await axios.post', $source);
        $this->assertStringContainsString('await axios.patch', $source);
        $this->assertStringContainsString('await axios.delete', $source);
        $this->assertStringNotContainsString('<Pagination', $source);
        $this->assertStringNotContainsString('router.', $source);
        $this->assertStringContainsString("import { Link } from '@inertiajs/vue3';", $navigation);
        $this->assertStringContainsString('<Link v-for="link in links"', $navigation);
    }

    public function test_menu_item_card_opens_inside_the_catalog_without_page_navigation(): void
    {
        $index = file_get_contents(resource_path('js/Pages/Admin/MenuItems/Index.vue'));
        $card = file_get_contents(resource_path('js/Components/MenuAdmin/MenuItemCard.vue'));

        $this->assertStringContainsString('@click="openItemCard(item)"', $index);
        $this->assertStringContainsString("Accept: 'application/json'", $index);
        $this->assertStringContainsString('<MenuItemCard v-else-if="itemCard"', $index);
        $this->assertStringContainsString('wide mobile-bottom', $index);
        $this->assertStringContainsString("const activeTab = ref('summary')", $card);
        $this->assertStringContainsString('سجل غير قابل لإعادة الكتابة', $card);
    }
}
