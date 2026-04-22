<?php

namespace App\Services;

use App\Helpers\UnitConverter;
use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Compute stock deduction lines for a cart item (before committing).
     * Returns array of [ingredient_id, qty_in_base, unit_cost, will_be_negative]
     */
    public function previewDeductionForItem(MenuItem $item, float $quantity, array $modifierIds = []): array
    {
        $lines = [];

        foreach ($item->recipeItems as $recipe) {
            $ingredient = $recipe->ingredient;
            $qtyBase = UnitConverter::convert((float) $recipe->quantity, $recipe->unit_id, $ingredient->base_unit_id) * $quantity;
            $lines[] = [
                'ingredient_id' => $ingredient->id,
                'ingredient' => $ingredient,
                'quantity_in_base' => $qtyBase,
                'unit_cost' => (float) $ingredient->cost_per_unit,
                'current_stock' => (float) $ingredient->current_stock,
                'will_be_negative' => $ingredient->track_stock && ((float) $ingredient->current_stock - $qtyBase) < 0,
            ];
        }

        foreach (Modifier::with('recipeItems.ingredient')->findMany($modifierIds) as $modifier) {
            foreach ($modifier->recipeItems as $recipe) {
                $ingredient = $recipe->ingredient;
                $qtyBase = UnitConverter::convert((float) $recipe->quantity, $recipe->unit_id, $ingredient->base_unit_id) * $quantity;
                $lines[] = [
                    'ingredient_id' => $ingredient->id,
                    'ingredient' => $ingredient,
                    'quantity_in_base' => $qtyBase,
                    'unit_cost' => (float) $ingredient->cost_per_unit,
                    'current_stock' => (float) $ingredient->current_stock,
                    'will_be_negative' => $ingredient->track_stock && ((float) $ingredient->current_stock - $qtyBase) < 0,
                ];
            }
        }

        return $lines;
    }

    /**
     * Commit the deduction for an OrderItem (inside an outer transaction).
     */
    public function deductForOrderItem(OrderItem $orderItem): void
    {
        $modifierIds = $orderItem->modifiers()->whereNotNull('modifier_id')->pluck('modifier_id')->toArray();
        $item = $orderItem->menuItem()->with('recipeItems.ingredient')->first();
        $lines = $this->previewDeductionForItem($item, (float) $orderItem->quantity, $modifierIds);

        foreach ($lines as $line) {
            $this->recordMovement(
                ingredient: $line['ingredient'],
                type: 'out',
                qtyBase: $line['quantity_in_base'],
                unitCost: $line['unit_cost'],
                reference: $orderItem,
                reason: 'خصم طلب '.$orderItem->order->number,
            );
        }
    }

    /**
     * Return stock for a cancelled OrderItem.
     */
    public function returnForOrderItem(OrderItem $orderItem): void
    {
        $movements = InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $orderItem->id)
            ->where('type', 'out')
            ->get();

        foreach ($movements as $mv) {
            $this->recordMovement(
                ingredient: $mv->ingredient,
                type: 'return',
                qtyBase: (float) $mv->quantity_in_base,
                unitCost: (float) $mv->unit_cost,
                reference: $orderItem,
                reason: 'إرجاع - إلغاء صنف '.$orderItem->name_snapshot,
            );
        }
    }

    public function recordMovement(
        Ingredient $ingredient,
        string $type,
        float $qtyBase,
        float $unitCost = 0,
        $reference = null,
        ?string $reason = null,
        ?int $userId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($ingredient, $type, $qtyBase, $unitCost, $reference, $reason, $userId) {
            $ingredient = $ingredient->lockForUpdate()->find($ingredient->id);
            $stockBefore = (float) $ingredient->current_stock;

            $direction = in_array($type, ['out', 'waste']) ? -1 : 1;
            $delta = $qtyBase * $direction;
            $stockAfter = $stockBefore + $delta;

            if ($ingredient->track_stock) {
                $ingredient->update(['current_stock' => $stockAfter]);
            }

            $mv = InventoryMovement::create([
                'ingredient_id' => $ingredient->id,
                'type' => $type,
                'quantity' => $qtyBase,
                'unit_id' => $ingredient->base_unit_id,
                'quantity_in_base' => $qtyBase,
                'unit_cost' => $unitCost,
                'total_cost' => $qtyBase * $unitCost,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->getKey(),
                'reason' => $reason,
                'user_id' => $userId ?? auth()->id(),
                'occurred_at' => now(),
            ]);

            return $mv;
        });
    }

    public function checkStockForOrderPreview(array $cartItems): array
    {
        $issues = [];
        $aggregated = [];
        foreach ($cartItems as $ci) {
            $item = MenuItem::with('recipeItems.ingredient')->find($ci['menu_item_id']);
            if (! $item) continue;
            $modifierIds = $ci['modifier_ids'] ?? [];
            $lines = $this->previewDeductionForItem($item, (float) $ci['quantity'], $modifierIds);
            foreach ($lines as $line) {
                $key = $line['ingredient_id'];
                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = ['ingredient' => $line['ingredient'], 'qty' => 0];
                }
                $aggregated[$key]['qty'] += $line['quantity_in_base'];
            }
        }

        foreach ($aggregated as $a) {
            if ($a['ingredient']->track_stock && $a['qty'] > (float) $a['ingredient']->current_stock) {
                $issues[] = [
                    'ingredient' => $a['ingredient']->name,
                    'required' => $a['qty'],
                    'available' => (float) $a['ingredient']->current_stock,
                ];
            }
        }

        return $issues;
    }

    /**
     * Validate that every tracked ingredient needed to fulfill the given Order
     * has enough stock. Aggregates across all pending line items (so two items
     * sharing an ingredient are considered together). Returns an array of issues.
     *
     * Pass this to ::throwIfInsufficient() from OrderService.approve() to block
     * over-selling.
     */
    public function validateStockForOrder(\App\Models\Order $order): array
    {
        $items = $order->items()
            ->where('status', \App\Enums\OrderItemStatus::Pending->value)
            ->with('modifiers')
            ->get();

        $cart = [];
        foreach ($items as $oi) {
            $cart[] = [
                'menu_item_id' => $oi->menu_item_id,
                'quantity'     => (float) $oi->quantity,
                'modifier_ids' => $oi->modifiers()->whereNotNull('modifier_id')->pluck('modifier_id')->toArray(),
            ];
        }

        return $this->checkStockForOrderPreview($cart);
    }

    /**
     * Throw a descriptive exception if any stock issue exists.
     * Used as a guard before approving an order.
     */
    public function throwIfInsufficient(array $issues): void
    {
        if (empty($issues)) return;

        $lines = array_map(function ($i) {
            $req = number_format($i['required'], 2);
            $avail = number_format($i['available'], 2);
            return "  • {$i['ingredient']}: مطلوب {$req}، متاح {$avail}";
        }, $issues);

        throw new \RuntimeException(
            "لا يمكن اعتماد الطلب — المخزون غير كافٍ:\n" . implode("\n", $lines)
        );
    }
}
