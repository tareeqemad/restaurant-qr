<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Regression: saving a menu item with a recipe row failed with
 * "The selected recipe.0.unit_id is invalid".
 *
 * The unit <select> sends a PREFIXED value — "u:5" (global Unit) or "iu:9"
 * (per-ingredient unit) — which syncRecipe() splits into the real unit_id /
 * ingredient_unit_id FKs. The validation rule was `exists:units,id`, which a
 * value like "u:5" can never satisfy, so every recipe save 422'd. The rule now
 * validates the shape (u:/iu:/bare int) instead.
 */
class MenuItemRecipeUnitTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Category $category;

    private Ingredient $ingredient;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['code' => 'm', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($branch->id);

        Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'is_system' => true]);
        $this->manager = User::create([
            'name' => 'Manager', 'username' => 'mgr',
            'password' => bcrypt('x'), 'status' => 'active',
            'role' => 'manager', 'primary_branch_id' => $branch->id,
        ]);
        $this->manager->branches()->attach($branch->id, ['is_primary' => true]);

        $this->unit = Unit::create([
            'code' => 'g', 'name' => 'غرام', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $this->category = Category::create(['name' => 'مشاوي', 'slug' => 'grill', 'active' => true]);
        $this->ingredient = Ingredient::create([
            'name' => 'لحم بقري', 'base_unit_id' => $this->unit->id, 'active' => true,
        ]);
    }

    public function test_store_accepts_prefixed_global_unit_value(): void
    {
        $response = $this->actingAs($this->manager)->post(route('admin.menu-items.store'), [
            'category_id' => $this->category->id,
            'name' => 'كباب لحم',
            'price' => 9.00,
            'recipe' => [
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 250, 'unit_id' => 'u:'.$this->unit->id],
            ],
        ]);

        $response->assertRedirect(route('admin.menu-items.index'));
        $response->assertSessionHasNoErrors();

        $item = MenuItem::where('name', 'كباب لحم')->firstOrFail();
        $this->assertDatabaseHas('recipe_items', [
            'menu_item_id' => $item->id,
            'ingredient_id' => $this->ingredient->id,
            'unit_id' => $this->unit->id,
            'ingredient_unit_id' => null,
        ]);
    }

    public function test_update_accepts_prefixed_value_and_does_not_422(): void
    {
        $item = MenuItem::create([
            'category_id' => $this->category->id, 'name' => 'صنف', 'price' => 5,
        ]);

        $response = $this->actingAs($this->manager)->put(route('admin.menu-items.update', $item), [
            'category_id' => $this->category->id,
            'name' => 'صنف معدّل',
            'price' => 6.00,
            'recipe' => [
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 100, 'unit_id' => 'u:'.$this->unit->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.menu-items.index'));
        $this->assertDatabaseHas('recipe_items', [
            'menu_item_id' => $item->id,
            'unit_id' => $this->unit->id,
        ]);
    }

    public function test_same_ingredient_twice_is_folded_not_500(): void
    {
        // recipe_items has UNIQUE(menu_item_id, ingredient_id). A duplicate
        // ingredient pick must NOT raise a 1062 duplicate-key error (was a 500).
        $item = MenuItem::create([
            'category_id' => $this->category->id, 'name' => 'مزدوج', 'price' => 5,
        ]);

        $response = $this->actingAs($this->manager)->put(route('admin.menu-items.update', $item), [
            'category_id' => $this->category->id,
            'name' => 'مزدوج',
            'price' => 6.00,
            'recipe' => [
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 100, 'unit_id' => 'u:'.$this->unit->id],
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 50, 'unit_id' => 'u:'.$this->unit->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.menu-items.index'));

        // Folded into a single row with the summed quantity.
        $this->assertSame(1, $item->recipeItems()->count());
        $this->assertEquals(150, (float) $item->recipeItems()->first()->quantity);
    }

    public function test_a_genuinely_malformed_unit_value_is_still_rejected(): void
    {
        $response = $this->actingAs($this->manager)->post(route('admin.menu-items.store'), [
            'category_id' => $this->category->id,
            'name' => 'سيئ',
            'price' => 9.00,
            'recipe' => [
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 250, 'unit_id' => 'garbage'],
            ],
        ]);

        $response->assertSessionHasErrors('recipe.0.unit_id');
    }

    public function test_incompatible_unit_type_is_rejected_with_a_clear_message(): void
    {
        // A count-based ingredient measured in قطعة; the user picks a weight
        // unit (غرام). This must be rejected up front — not stored to break
        // cost/stock later — with a message naming the ingredient and units.
        $countUnit = Unit::create([
            'code' => 'pcs', 'name' => 'قطعة', 'unit_type' => 'count',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $cola = Ingredient::create([
            'name' => 'كولا', 'base_unit_id' => $countUnit->id, 'active' => true,
        ]);

        $response = $this->actingAs($this->manager)->post(route('admin.menu-items.store'), [
            'category_id' => $this->category->id,
            'name' => 'مشروب',
            'price' => 5.00,
            'recipe' => [
                // غرام (weight) on a count-based ingredient → incompatible.
                ['ingredient_id' => $cola->id, 'quantity' => 100, 'unit_id' => 'u:'.$this->unit->id],
            ],
        ]);

        $response->assertSessionHasErrors('recipe.0.unit_id');
        $this->assertStringContainsString(
            'لا تتوافق',
            session('errors')->first('recipe.0.unit_id')
        );
        $this->assertDatabaseMissing('menu_items', ['name' => 'مشروب']);
    }

    public function test_rejected_save_re_renders_the_form_with_the_inline_error_and_old_input(): void
    {
        // The user submits an incompatible unit from the create form. They must
        // land back on the form, see the clear error, and keep what they typed.
        $countUnit = Unit::create([
            'code' => 'pcs', 'name' => 'قطعة', 'unit_type' => 'count',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $cola = Ingredient::create([
            'name' => 'كولا', 'base_unit_id' => $countUnit->id, 'active' => true,
        ]);

        $this->actingAs($this->manager)
            ->from(route('admin.menu-items.create'))
            ->post(route('admin.menu-items.store'), [
                'category_id' => $this->category->id,
                'name' => 'مشروب',
                'price' => 5.00,
                'recipe' => [
                    ['ingredient_id' => $cola->id, 'quantity' => 100, 'unit_id' => 'u:'.$this->unit->id],
                ],
            ])
            ->assertRedirect(route('admin.menu-items.create'));

        $expectedError = session('errors')->first('recipe.0.unit_id');

        // Follow the redirect back to the Inertia form and confirm the error
        // remains part of its page props. The Vue shell escapes Arabic in the
        // bootstrap JSON, so assert the component contract instead of HTML.
        $page = $this->actingAs($this->manager)->get(route('admin.menu-items.create'));
        $page->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/MenuItems/Form')
            ->where('mode', 'create')
            ->where('errors', fn ($errors) => collect($errors)->contains($expectedError))
        );
    }

    public function test_compatible_unit_type_passes(): void
    {
        // غرام and a كيلوغرام are both weight → allowed.
        $kg = Unit::create([
            'code' => 'kg', 'name' => 'كيلوغرام', 'unit_type' => 'weight',
            'factor_to_base' => 1000, 'is_base' => false,
        ]);

        $response = $this->actingAs($this->manager)->post(route('admin.menu-items.store'), [
            'category_id' => $this->category->id,
            'name' => 'لحم بالكيلو',
            'price' => 30.00,
            'recipe' => [
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 1, 'unit_id' => 'u:'.$kg->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.menu-items.index'));
    }

    public function test_added_ingredient_with_empty_unit_falls_back_to_base_unit_not_500(): void
    {
        // Adding a new ingredient row before picking its unit sends an empty
        // unit_id. That used to resolve to unit_id=0 → recipe_items.unit_id
        // foreign-key violation → 500. It now falls back to the ingredient's
        // base unit.
        $item = MenuItem::create([
            'category_id' => $this->category->id, 'name' => 'كباب', 'price' => 9,
        ]);

        $response = $this->actingAs($this->manager)->put(route('admin.menu-items.update', $item), [
            'category_id' => $this->category->id,
            'name' => 'كباب',
            'price' => 9.00,
            'recipe' => [
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 250, 'unit_id' => ''],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.menu-items.index'));
        $this->assertDatabaseHas('recipe_items', [
            'menu_item_id' => $item->id,
            'ingredient_id' => $this->ingredient->id,
            'unit_id' => $this->unit->id, // the ingredient's base unit
        ]);
    }

    public function test_iu_referencing_a_missing_ingredient_unit_is_rejected(): void
    {
        $item = MenuItem::create([
            'category_id' => $this->category->id, 'name' => 'iu-bad', 'price' => 9,
        ]);

        $response = $this->actingAs($this->manager)->put(route('admin.menu-items.update', $item), [
            'category_id' => $this->category->id,
            'name' => 'iu-bad',
            'price' => 9.00,
            'recipe' => [
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 2, 'unit_id' => 'iu:999999'],
            ],
        ]);

        $response->assertSessionHasErrors('recipe.0.unit_id');
        $this->assertDatabaseMissing('recipe_items', ['menu_item_id' => $item->id]);
    }

    public function test_legacy_bare_unit_id_still_enforces_measurement_type(): void
    {
        $countUnit = Unit::create([
            'code' => 'pcs', 'name' => 'قطعة', 'unit_type' => 'count',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $countIngredient = Ingredient::create([
            'name' => 'كولا', 'base_unit_id' => $countUnit->id, 'active' => true,
        ]);

        $response = $this->actingAs($this->manager)->post(route('admin.menu-items.store'), [
            'category_id' => $this->category->id,
            'name' => 'وحدة قديمة خاطئة',
            'price' => 5,
            'recipe' => [[
                'ingredient_id' => $countIngredient->id,
                'quantity' => 1,
                // Bare ids are accepted for legacy clients, but never bypass
                // the family check (weight cannot describe a count ingredient).
                'unit_id' => (string) $this->unit->id,
            ]],
        ]);

        $response->assertSessionHasErrors('recipe.0.unit_id');
        $this->assertDatabaseMissing('menu_items', ['name' => 'وحدة قديمة خاطئة']);
    }

    public function test_menu_index_does_not_500_on_mismatched_recipe_unit_types(): void
    {
        // A recipe line whose unit type doesn't match the ingredient's base
        // unit (e.g. a weight unit on a count-based ingredient) made
        // UnitConverter throw inside the index's stock-preview, 500ing the
        // whole menu list. The preview now tolerates the bad line.
        $weight = $this->unit; // غرام (weight) from setUp

        $countUnit = Unit::create([
            'code' => 'pcs', 'name' => 'قطعة', 'unit_type' => 'count',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $countIngredient = Ingredient::create([
            'name' => 'كولا', 'base_unit_id' => $countUnit->id,
            'active' => true, 'track_stock' => true, 'current_stock' => 0,
        ]);

        $item = MenuItem::create([
            'category_id' => $this->category->id, 'name' => 'صنف وحدة غلط', 'price' => 10,
        ]);
        // Weight unit on a count-based ingredient → conversion is impossible.
        $item->recipeItems()->create([
            'ingredient_id' => $countIngredient->id,
            'quantity' => 1,
            'unit_id' => $weight->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.menu-items.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MenuItems/Index')
                ->where('items.data.0.name', $item->name)
            );
    }

    public function test_menu_index_does_not_500_when_a_recipe_ingredient_is_soft_deleted(): void
    {
        // A tracked ingredient used by a recipe, then soft-deleted, left the
        // index page's stock-shortage preview handing null to code that
        // type-hints Ingredient → 500 on opening the menu list. The
        // recipe→ingredient relation now resolves withTrashed.
        $this->ingredient->forceFill(['track_stock' => true, 'current_stock' => 0])->save();

        $item = MenuItem::create([
            'category_id' => $this->category->id, 'name' => 'صنف بمكون محذوف', 'price' => 12,
        ]);
        $item->recipeItems()->create([
            'ingredient_id' => $this->ingredient->id,
            'quantity' => 100,
            'unit_id' => $this->unit->id,
        ]);

        $this->ingredient->delete(); // soft delete while the recipe row stays

        $this->actingAs($this->manager)
            ->get(route('admin.menu-items.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MenuItems/Index')
                ->where('items.data.0.name', $item->name)
            );
    }
}
