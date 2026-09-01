<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Station;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Restores the exact menu catalogue approved in the development database on
 * 2026-08-12: branches are resolved by code and every other relationship is
 * rebuilt from portable source keys, never from production database IDs.
 *
 * Operational data is deliberately out of scope. This seeder never imports
 * users, customers, orders, stock balances, suppliers, debts, payments or
 * journals, and refuses to replace a catalogue after orders exist.
 */
class RealRestaurantMenuSeeder extends Seeder
{
    /** @var array<string,mixed> */
    private array $snapshot = [];

    public function run(): void
    {
        $this->snapshot = require database_path('seeders/data/production-menu-2026-08-12.php');
        $this->assertSnapshotShape();
        $this->assertCatalogueCanBeReplaced();

        $this->command?->info('Restoring the approved development menu snapshot...');

        DB::transaction(function (): void {
            $this->syncIngredientCatalogue();
            $this->replaceMenuCatalogue();
        });

        $this->warnAboutMissingUploads();

        $meta = $this->snapshot['meta'];
        $this->command?->info(sprintf(
            'Done — %d categories, %d menu items, %d recipe lines, %d modifier groups and %d modifiers.',
            $meta['categories'],
            $meta['items'],
            $meta['recipes'],
            $meta['modifier_groups'],
            $meta['modifiers'],
        ));
    }

    private function assertSnapshotShape(): void
    {
        foreach ([
            'meta', 'categories', 'items', 'ingredients', 'recipes',
            'modifier_groups', 'modifiers', 'item_modifier_groups', 'item_allergens',
        ] as $key) {
            if (! array_key_exists($key, $this->snapshot) || ! is_array($this->snapshot[$key])) {
                throw new RuntimeException("Menu snapshot is missing the [{$key}] collection.");
            }
        }
    }

    private function assertCatalogueCanBeReplaced(): void
    {
        foreach (['orders', 'order_items', 'invoices', 'payments'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'The menu snapshot may only replace a fresh catalogue. Operational rows already exist in ['.$table.']; no data was changed.',
                );
            }
        }
    }

    private function syncIngredientCatalogue(): void
    {
        $unitIds = Unit::query()->pluck('id', 'code');

        foreach ($this->snapshot['ingredients'] as $row) {
            $unitId = $unitIds[$row['base_unit_code']] ?? null;
            if (! $unitId) {
                throw new RuntimeException("Missing unit [{$row['base_unit_code']}] for ingredient [{$row['sku']}].");
            }

            $ingredient = Ingredient::withTrashed()->updateOrCreate(
                ['sku' => $row['sku']],
                [
                    'name' => $row['name'],
                    'base_unit_id' => $unitId,
                    'supplier_id' => null,
                    'measurement_type' => $row['measurement_type'],
                    'reorder_threshold' => $row['reorder_threshold'],
                    'cost_per_unit' => $row['cost_per_unit'],
                    'yield_pct' => $row['yield_pct'],
                    'track_stock' => (bool) $row['track_stock'],
                    'tracks_expiry' => (bool) $row['tracks_expiry'],
                    'default_shelf_life_days' => $row['default_shelf_life_days'],
                    'is_composite' => (bool) $row['is_composite'],
                    'composite_yield' => $row['composite_yield'],
                    'active' => (bool) $row['active'],
                    'notes' => $row['notes'],
                ],
            );

            if ($ingredient->trashed()) {
                $ingredient->restore();
            }
        }
    }

    private function replaceMenuCatalogue(): void
    {
        $this->clearExistingCatalogue();

        $branchIds = Branch::query()->pluck('id', 'code');
        $stationIds = Station::withoutGlobalScopes()
            ->get()
            ->mapWithKeys(static fn (Station $station): array => [
                $station->branch_id.':'.$station->code => $station->id,
            ]);

        $categoriesBySource = [];
        foreach ($this->snapshot['categories'] as $row) {
            $branchId = $branchIds[$row['branch_code']] ?? null;
            if (! $branchId) {
                throw new RuntimeException("Missing branch [{$row['branch_code']}] required by the menu snapshot.");
            }

            $stationId = $this->stationId($stationIds, (int) $branchId, $row['station_code']);
            $categoriesBySource[(int) $row['source_id']] = Category::withoutGlobalScopes()->create([
                'branch_id' => $branchId,
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'],
                'image' => $row['image'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'default_station_id' => $stationId,
                'display_order' => (int) $row['display_order'],
                'active' => (bool) $row['active'],
            ]);
        }

        $itemsBySource = [];
        foreach ($this->snapshot['items'] as $row) {
            $branchId = $branchIds[$row['branch_code']] ?? null;
            $category = $categoriesBySource[(int) $row['category_source_id']] ?? null;
            if (! $branchId || ! $category || (int) $category->branch_id !== (int) $branchId) {
                throw new RuntimeException("Broken category mapping for menu item [{$row['source_id']}].");
            }

            $itemsBySource[(int) $row['source_id']] = MenuItem::withoutGlobalScopes()->create([
                'branch_id' => $branchId,
                'category_id' => $category->id,
                'station_id' => $this->stationId($stationIds, (int) $branchId, $row['station_code']),
                'sku' => $row['sku'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'],
                'price' => $row['price'],
                'cost' => $row['cost'],
                'image' => $row['image'],
                'prep_time_minutes' => (int) $row['prep_time_minutes'],
                'calories' => $row['calories'],
                'is_available' => (bool) $row['is_available'],
                'is_featured' => (bool) $row['is_featured'],
                'unavailable_reason' => $row['unavailable_reason'],
                'display_order' => (int) $row['display_order'],
            ]);
        }

        $ingredientsBySku = Ingredient::withTrashed()->get()->keyBy('sku');
        $unitsByCode = Unit::query()->get()->keyBy('code');
        foreach ($this->snapshot['recipes'] as $row) {
            $item = $row['menu_source_id'] === null
                ? null
                : ($itemsBySource[(int) $row['menu_source_id']] ?? null);
            $parentIngredient = $row['parent_ingredient_sku'] === null
                ? null
                : ($ingredientsBySku[$row['parent_ingredient_sku']] ?? null);
            $ingredient = $ingredientsBySku[$row['ingredient_sku']] ?? null;
            $unit = $unitsByCode[$row['unit_code']] ?? null;

            if ((! $item && ! $parentIngredient) || ($item && $parentIngredient) || ! $ingredient || ! $unit) {
                throw new RuntimeException('Broken recipe mapping in the approved menu snapshot.');
            }
            if ($row['ingredient_unit_id'] !== null) {
                throw new RuntimeException('Portable ingredient-unit mapping is required before seeding this recipe line.');
            }

            RecipeItem::create([
                'menu_item_id' => $item?->id,
                'parent_ingredient_id' => $parentIngredient?->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => $row['quantity'],
                'unit_id' => $unit->id,
                'ingredient_unit_id' => null,
                'is_optional' => (bool) $row['is_optional'],
                'notes' => $row['notes'],
            ]);
        }

        $groupsBySource = [];
        foreach ($this->snapshot['modifier_groups'] as $row) {
            $branchId = $branchIds[$row['branch_code']] ?? null;
            if (! $branchId) {
                throw new RuntimeException("Missing branch for modifier group [{$row['source_id']}].");
            }

            $groupsBySource[(int) $row['source_id']] = DB::table('modifier_groups')->insertGetId([
                'branch_id' => $branchId,
                'slug' => $row['slug'],
                'name' => $row['name'],
                'min_select' => (int) $row['min_select'],
                'max_select' => (int) $row['max_select'],
                'required' => (bool) $row['required'],
                'display_order' => (int) $row['display_order'],
                'active' => (bool) $row['active'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($this->snapshot['modifiers'] as $row) {
            $groupId = $groupsBySource[(int) $row['group_source_id']] ?? null;
            if (! $groupId) {
                throw new RuntimeException("Broken modifier-group mapping for modifier [{$row['source_id']}].");
            }

            DB::table('modifiers')->insert([
                'modifier_group_id' => $groupId,
                'name' => $row['name'],
                'price_delta' => $row['price_delta'],
                'active' => (bool) $row['active'],
                'display_order' => (int) $row['display_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($this->snapshot['item_modifier_groups'] as $row) {
            $itemId = ($itemsBySource[(int) $row['menu_source_id']] ?? null)?->id;
            $groupId = $groupsBySource[(int) $row['group_source_id']] ?? null;
            if (! $itemId || ! $groupId) {
                throw new RuntimeException('Broken menu-item modifier-group mapping.');
            }
            DB::table('menu_item_modifier_group')->insert([
                'menu_item_id' => $itemId,
                'modifier_group_id' => $groupId,
                'display_order' => (int) $row['display_order'],
            ]);
        }

        $allergenIds = DB::table('allergens')->pluck('id', 'code');
        foreach ($this->snapshot['item_allergens'] as $row) {
            $itemId = ($itemsBySource[(int) $row['menu_source_id']] ?? null)?->id;
            $allergenId = $allergenIds[$row['allergen_code']] ?? null;
            if (! $itemId || ! $allergenId) {
                throw new RuntimeException('Broken menu-item allergen mapping.');
            }
            DB::table('menu_item_allergens')->insert([
                'menu_item_id' => $itemId,
                'allergen_id' => $allergenId,
            ]);
        }
    }

    private function clearExistingCatalogue(): void
    {
        DB::table('menu_item_allergens')->delete();
        DB::table('menu_item_modifier_group')->delete();
        DB::table('modifier_recipe_items')->delete();
        DB::table('recipe_items')->delete();
        DB::table('modifiers')->delete();
        DB::table('modifier_groups')->delete();
        MenuItem::withoutGlobalScopes()->withTrashed()->forceDelete();
        Category::withoutGlobalScopes()->withTrashed()->forceDelete();
    }

    /** @param \Illuminate\Support\Collection<string,int> $stationIds */
    private function stationId($stationIds, int $branchId, ?string $stationCode): ?int
    {
        if ($stationCode === null) {
            return null;
        }

        $stationId = $stationIds[$branchId.':'.$stationCode] ?? null;
        if (! $stationId) {
            throw new RuntimeException("Missing station [{$stationCode}] in branch [{$branchId}].");
        }

        return (int) $stationId;
    }

    private function warnAboutMissingUploads(): void
    {
        $localPaths = collect($this->snapshot['categories'])
            ->concat($this->snapshot['items'])
            ->pluck('image')
            ->filter(static fn ($path): bool => is_string($path) && $path !== '' && ! str_starts_with($path, 'http'))
            ->unique();

        $missing = $localPaths->reject(static fn (string $path): bool => Storage::disk('public')->exists($path));
        if ($missing->isNotEmpty()) {
            $this->command?->warn(sprintf(
                '%d local menu image(s) are not present under storage/app/public. Copy that directory during deployment; placeholders are used until then.',
                $missing->count(),
            ));
        }
    }
}
