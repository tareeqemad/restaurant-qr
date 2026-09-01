<?php

namespace Tests\Feature;

use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_restores_the_approved_catalogue_without_operational_data(): void
    {
        $this->seed(ProductionSeeder::class);

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('categories', 9);
        $this->assertDatabaseCount('menu_items', 81);
        $this->assertDatabaseCount('ingredients', 15);
        $this->assertDatabaseCount('recipe_items', 222);
        $this->assertDatabaseCount('modifier_groups', 2);
        $this->assertDatabaseCount('modifiers', 6);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);

        $this->assertDatabaseHas('menu_items', [
            'sku' => 'APP-01',
            'price' => 2.50,
            'deleted_at' => null,
        ]);

        $this->assertSame(3, DB::table('menu_items')
            ->join('branches', 'branches.id', '=', 'menu_items.branch_id')
            ->where('branches.code', 'gaza')
            ->where('menu_items.sku', 'like', 'NET-%')
            ->count());

        $this->assertDatabaseHas('accounts', ['code' => '1160', 'is_active' => true]);
        $this->assertDatabaseHas('account_mappings', [
            'context' => 'payment_method',
            'key' => 'bank_transfer',
        ]);
    }

    public function test_reseeding_the_fresh_catalogue_is_idempotent(): void
    {
        $this->seed(ProductionSeeder::class);
        $this->seed(ProductionSeeder::class);

        $this->assertDatabaseCount('categories', 9);
        $this->assertDatabaseCount('menu_items', 81);
        $this->assertDatabaseCount('recipe_items', 222);
        $this->assertDatabaseCount('modifier_groups', 2);
        $this->assertDatabaseCount('modifiers', 6);
    }
}

