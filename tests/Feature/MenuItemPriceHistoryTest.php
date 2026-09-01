<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuItemPriceHistory;
use App\Models\MenuPromotion;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MenuItemPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $manager;

    private Category $category;

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
            ['name' => 'manager'],
            ['label' => 'مدير', 'is_system' => true],
        );
        $this->manager = User::create([
            'name' => 'مدير المطعم',
            'username' => 'price-manager',
            'password' => bcrypt('secret'),
            'status' => 'active',
            'role' => 'manager',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->manager->branches()->attach($this->branch->id, ['is_primary' => true]);

        $this->category = Category::create([
            'name' => 'الوجبات',
            'slug' => 'meals',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_base_price_changes_are_recorded_and_exposed_on_the_item_card(): void
    {
        $item = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'وجبة برجر',
            'slug' => 'burger-meal',
            'price' => 30,
            'cost' => 12,
            'is_available' => true,
        ]);

        $this->assertDatabaseHas('menu_item_price_histories', [
            'menu_item_id' => $item->id,
            'change_type' => MenuItemPriceHistory::INITIAL,
            'old_price' => null,
            'new_price' => 30,
        ]);

        $this->actingAs($this->manager)
            ->put(route('admin.menu-items.update', $item), [
                'category_id' => $this->category->id,
                'name' => 'وجبة برجر',
                'price' => 34,
                'price_change_reason' => 'ارتفاع تكلفة اللحوم',
                'is_available' => true,
                'recipe' => [],
                'allergens' => [],
                'modifier_groups' => [],
            ])
            ->assertRedirect(route('admin.menu-items.index'));

        $this->assertDatabaseHas('menu_item_price_histories', [
            'menu_item_id' => $item->id,
            'change_type' => MenuItemPriceHistory::BASE_PRICE_CHANGE,
            'old_price' => 30,
            'new_price' => 34,
            'reason' => 'ارتفاع تكلفة اللحوم',
            'changed_by_user_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.menu-items.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MenuItems/Show')
                ->where('item.id', $item->id)
                ->where('item.hasPromotion', false)
                ->has('priceHistory', 2)
                ->where('priceHistory.0.reason', 'ارتفاع تكلفة اللحوم')
                ->has('promotions')
                ->has('sales')
            );

        $this->actingAs($this->manager)
            ->getJson(route('admin.menu-items.show', $item))
            ->assertOk()
            ->assertJsonPath('item.id', $item->id)
            ->assertJsonPath('priceHistory.0.reason', 'ارتفاع تكلفة اللحوم')
            ->assertJsonStructure(['item', 'sales', 'priceHistory', 'promotions', 'can', 'urls']);
    }

    public function test_temporary_offer_changes_effective_price_without_rewriting_base_price_history(): void
    {
        $item = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'وجبة شاورما',
            'slug' => 'shawarma-meal',
            'price' => 25,
            'cost' => 10,
            'is_available' => true,
        ]);

        MenuPromotion::create([
            'branch_id' => $this->branch->id,
            'name' => 'عرض نهاية الأسبوع',
            'type' => MenuPromotion::TYPE_SALE_PRICE,
            'value' => 20,
            'target_type' => MenuPromotion::TARGET_MENU_ITEM,
            'target_id' => $item->id,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addWeek(),
            'active' => true,
            'created_by_user_id' => $this->manager->id,
        ]);

        $this->assertSame(1, $item->priceHistory()->count());
        $this->assertSame(25.0, (float) $item->fresh()->price);
        $this->assertSame(20.0, $item->fresh()->effectivePrice());

        $this->actingAs($this->manager)
            ->get(route('admin.menu-items.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('item.hasPromotion', true)
                ->where('item.promotionName', 'عرض نهاية الأسبوع')
                ->has('promotions', 1)
                ->where('promotions.0.status', 'live')
                ->where('promotions.0.hasPriceDiscount', true)
                ->has('priceHistory', 1)
            );
    }

    public function test_sale_price_can_never_raise_the_customer_price(): void
    {
        $promotion = new MenuPromotion([
            'type' => MenuPromotion::TYPE_SALE_PRICE,
            'value' => 40,
        ]);

        $this->assertSame(25.0, $promotion->applyTo(25));
    }
}
