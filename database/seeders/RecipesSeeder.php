<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\StorageLocation;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Attaches a sensible recipe to every menu item that doesn't already have
 * one, so the inventory-deduction flow actually has something to consume
 * when an order is approved.
 *
 * The mapping is keyword-based (`detectRecipe`) — good enough for demo /
 * dev. Real recipes should be set per-item by the operator from the menu
 * admin once the system is live.
 *
 * Also refills `ingredient_stock` for the default storage location, since
 * the inventory tables sometimes get reset between runs and the chef can't
 * approve a ticket against an empty stockroom.
 *
 * Safe to re-run: each insert is guarded so existing recipes / stock rows
 * aren't duplicated.
 */
class RecipesSeeder extends Seeder
{
    /** Resolved ingredients keyed by SKU (so the recipe map stays readable). */
    protected array $ing = [];

    /** Base units for the recipe lines. */
    protected ?Unit $gram = null;
    protected ?Unit $ml = null;
    protected ?Unit $pcs = null;

    public function run(): void
    {
        $this->bootCaches();

        if (empty($this->ing)) {
            $this->command->warn('RecipesSeeder: no ingredients found — skipping. Run MenuSeeder first.');
            return;
        }

        $this->refillStock();
        $this->seedRecipes();
    }

    /**
     * Ensure every tracked ingredient has a non-zero quantity at the
     * default storage location. Without this, every recipe would fail
     * `strict_stock` validation on the first order.
     */
    protected function refillStock(): void
    {
        $defaultLocId = StorageLocation::where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->value('id');

        // Demo dataset doesn't always carry a storage location (none of
        // the earlier seeders create one explicitly — they expect the
        // admin UI to). Bootstrap one against the first branch so the
        // stock refill below has somewhere to write to.
        if (! $defaultLocId) {
            $branch = \App\Models\Branch::orderBy('id')->first();
            if (! $branch) {
                $this->command->warn('RecipesSeeder: no branch found — skipping stock refill.');
                return;
            }
            $loc = StorageLocation::create([
                'branch_id'   => $branch->id,
                'code'        => 'main-storage',
                'name'        => 'المخزن الرئيسي',
                'is_default'  => true,
                'active'      => true,
            ]);
            $defaultLocId = $loc->id;
            $this->command->info('  ✓ Bootstrapped a default storage location for the demo.');
        }

        // Generous default quantities so the cashier can actually run a
        // handful of test orders without hitting low-stock blocks.
        $perUnitDefault = ['g' => 50000, 'ml' => 50000, 'pcs' => 200];

        foreach ($this->ing as $ingredient) {
            $unitCode = $ingredient->baseUnit?->code ?? 'pcs';
            $qty = $perUnitDefault[$unitCode] ?? 100;

            $stockRow = IngredientStock::firstOrNew([
                'ingredient_id'       => $ingredient->id,
                'storage_location_id' => $defaultLocId,
            ]);

            // Only top up when the location row is empty / missing — never
            // overwrite a real, manually-set quantity.
            if (! $stockRow->exists || (float) $stockRow->quantity <= 0.001) {
                $stockRow->quantity = $qty;
                $stockRow->reorder_threshold = $stockRow->reorder_threshold ?: 0;
                $stockRow->save();
            }

            // Mirror the per-location truth back to the denormalized global
            // counter (InventoryService::recordMovement keeps this in sync
            // in production; here we set it manually since we're bypassing
            // the service for a one-shot seed).
            $globalQty = IngredientStock::where('ingredient_id', $ingredient->id)->sum('quantity');
            $ingredient->update(['current_stock' => $globalQty]);
        }

        $this->command->info('  ✓ Stock refilled across '.count($this->ing).' ingredients.');
    }

    protected function seedRecipes(): void
    {
        $items = MenuItem::doesntHave('recipeItems')
            ->where('sku', 'not like', 'NET-%')   // Internet cards never consume stock
            ->with('category')
            ->get();

        if ($items->isEmpty()) {
            $this->command->info('  ✓ All non-internet items already have recipes — nothing to do.');
            return;
        }

        $added = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $recipe = $this->detectRecipe($item);
            if (empty($recipe)) {
                $skipped++;
                continue;
            }

            foreach ($recipe as $line) {
                [$sku, $qty, $unitCode] = $line;
                $ingredient = $this->ing[$sku] ?? null;
                if (! $ingredient) continue;

                $unit = match ($unitCode) {
                    'g'   => $this->gram,
                    'ml'  => $this->ml,
                    'pcs' => $this->pcs,
                    default => $ingredient->baseUnit,
                };
                if (! $unit) continue;

                RecipeItem::updateOrCreate(
                    ['menu_item_id' => $item->id, 'ingredient_id' => $ingredient->id],
                    ['quantity' => $qty, 'unit_id' => $unit->id],
                );
            }

            $added++;
        }

        $this->command->info("  ✓ Recipes added to {$added} items".($skipped ? " (skipped {$skipped} with no detectable category)" : '').'.');
    }

    /**
     * Map a menu item to a small set of ingredients based on its category
     * + Arabic name keywords. Returns array of [sku, quantity, unitCode].
     *
     * Keep this conservative — 2 to 4 lines per item is enough to prove
     * deduction works without inventing fake "salt and pepper" lines for
     * every single dish.
     */
    protected function detectRecipe(MenuItem $item): array
    {
        $name = $item->name;
        $cat  = strtolower((string) ($item->category?->slug ?? ''));

        // ─── Sandwiches: bread + protein + veg ─────────────────────────
        if (str_contains($cat, 'sandwich') || str_contains($cat, 'sndwch') || str_contains($cat, 'sndw')) {
            $protein = str_contains($name, 'دجاج') ? 'ING-001'
                     : (str_contains($name, 'لحم') || str_contains($name, 'برجر')  ? 'ING-002'
                     : (str_contains($name, 'جبنة') ? 'ING-008'
                     : 'ING-001'));   // default to chicken
            return [
                ['ING-005', 1,   'pcs'],   // bread
                [$protein, 150,  'g'],
                ['ING-003', 30,  'g'],     // tomato
                ['ING-004', 20,  'g'],     // onion
            ];
        }

        // ─── Grills ─────────────────────────────────────────────────────
        if (str_contains($cat, 'grill') || str_contains($cat, 'mshawi') || str_contains($name, 'مشوي') || str_contains($name, 'كباب') || str_contains($name, 'كفتة')) {
            $protein = str_contains($name, 'دجاج') || str_contains($name, 'فراخ') || str_contains($name, 'صدر')  ? 'ING-001' : 'ING-002';
            return [
                [$protein, 250, 'g'],
                ['ING-006', 10, 'ml'],     // olive oil
                ['ING-007', 5,  'g'],      // salt
            ];
        }

        // ─── Main dishes: large meat/chicken portions ───────────────────
        if (str_contains($cat, 'main')) {
            $protein = str_contains($name, 'دجاج') || str_contains($name, 'مسخن') || str_contains($name, 'شيش')  ? 'ING-001' : 'ING-002';
            return [
                [$protein, 300, 'g'],
                ['ING-004', 40, 'g'],      // onion
                ['ING-006', 15, 'ml'],     // oil
            ];
        }

        // ─── Salads ─────────────────────────────────────────────────────
        if (str_contains($cat, 'salad') || str_contains($name, 'سلطة') || str_contains($name, 'فتوش') || str_contains($name, 'تبولة')) {
            $lines = [
                ['ING-003', 80, 'g'],      // tomato
                ['ING-009', 60, 'g'],      // cucumber
                ['ING-006', 10, 'ml'],     // olive oil
            ];
            if (str_contains($name, 'دجاج') || str_contains($name, 'سيزر')) {
                $lines[] = ['ING-001', 100, 'g'];
            }
            return $lines;
        }

        // ─── Drinks ─────────────────────────────────────────────────────
        if (str_contains($cat, 'drink') || str_contains($cat, 'beverag')) {
            if (str_contains($name, 'قهوة') || str_contains($name, 'كابتشينو') || str_contains($name, 'لاتيه')) {
                return [
                    ['ING-012', 12,  'g'],     // coffee beans
                    ['ING-014', 200, 'ml'],    // water
                    ['ING-013', 50,  'ml'],    // milk (latte/cappuccino mainly)
                ];
            }
            if (str_contains($name, 'شاي')) {
                return [
                    ['ING-014', 250, 'ml'],
                    ['ING-011', 10,  'g'],     // sugar
                ];
            }
            if (str_contains($name, 'عصير') || str_contains($name, 'مانجو') || str_contains($name, 'فراولة') || str_contains($name, 'برتقال')) {
                return [
                    ['ING-014', 100, 'ml'],
                    ['ING-011', 15,  'g'],
                    ['ING-015', 1,   'pcs'],
                ];
            }
            if (str_contains($name, 'مياه') || str_contains($name, 'مياة') || str_contains($name, 'ماء')) {
                return [['ING-014', 500, 'ml']];
            }
            if (str_contains($name, 'ميلك') || str_contains($name, 'شيك')) {
                return [
                    ['ING-013', 250, 'ml'],
                    ['ING-011', 20,  'g'],
                ];
            }
            // Soft drinks etc. — treat as bottled water for stock purposes.
            return [['ING-014', 330, 'ml']];
        }

        // ─── Desserts ───────────────────────────────────────────────────
        if (str_contains($cat, 'dessert')) {
            $lines = [
                ['ING-011', 40, 'g'],      // sugar
                ['ING-013', 80, 'ml'],     // milk
            ];
            if (str_contains($name, 'نوتيلا') || str_contains($name, 'براوني') || str_contains($name, 'تشيز')) {
                $lines[] = ['ING-010', 30, 'g'];   // nutella
            }
            return $lines;
        }

        // ─── Appetizers ─────────────────────────────────────────────────
        if (str_contains($cat, 'appetiz') || str_contains($cat, 'maqbil')) {
            if (str_contains($name, 'بطاطا')) {
                return [
                    ['ING-006', 30, 'ml'],     // oil
                    ['ING-007', 3,  'g'],
                ];
            }
            // Default appetizer recipe: tomato + oil + bread
            return [
                ['ING-003', 50, 'g'],
                ['ING-006', 10, 'ml'],
                ['ING-005', 1,  'pcs'],
            ];
        }

        // ─── Fallback ───────────────────────────────────────────────────
        // Anything we couldn't classify: one piece of bread + small
        // tomato. Ensures every item deducts SOMETHING so the demo flow
        // doesn't silently no-op.
        return [
            ['ING-005', 1,  'pcs'],
            ['ING-003', 30, 'g'],
        ];
    }

    protected function bootCaches(): void
    {
        $this->gram = Unit::where('code', 'g')->first();
        $this->ml   = Unit::where('code', 'ml')->first();
        $this->pcs  = Unit::where('code', 'pcs')->first();

        $this->ing = Ingredient::with('baseUnit')->get()->keyBy('sku')->all();
    }
}
