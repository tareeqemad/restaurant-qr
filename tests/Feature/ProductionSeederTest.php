<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seeding_creates_only_reference_data(): void
    {
        $this->seed(ProductionSeeder::class);
        foreach (['branches', 'categories', 'menu_items', 'ingredients', 'recipe_items', 'modifier_groups', 'modifiers', 'users', 'tables', 'orders', 'payments', 'ingredient_stock', 'journal_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('accounts', ['code' => '1160', 'is_active' => true]);
        $this->assertDatabaseHas('account_mappings', ['context' => 'payment_method', 'key' => 'bank_transfer']);
    }

    public function test_production_reseeding_preserves_an_owner_catalogue(): void
    {
        $this->seed(ProductionSeeder::class);
        $branch = Branch::create(['name' => 'Owner branch', 'code' => 'owner', 'is_active' => true]);
        $category = Category::create(['branch_id' => $branch->id, 'slug' => 'owner-meals', 'name' => 'Owner meals', 'active' => true]);
        $item = MenuItem::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'slug' => 'owner-item', 'name' => 'Owner item', 'price' => 23.50, 'is_available' => true]);
        $this->seed(ProductionSeeder::class);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('menu_items', 1);
        $this->assertSame('23.50', $item->fresh()->price);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Owner meals']);
    }
}
