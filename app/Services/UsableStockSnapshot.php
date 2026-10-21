<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\Scopes\BranchScope;

/** A fresh read-only stock projection. Never use this to authorize a deduction. */
class UsableStockSnapshot
{
    private array $batches = [];

    private array $legacy = [];

    private array $thresholds = [];

    public static function capture(array $ingredientIds): self
    {
        $snapshot = new self;
        $ingredientIds = array_values(array_unique($ingredientIds));
        if (! $ingredientIds) {
            return $snapshot;
        }

        // Keep groups even when all their batches are expired/exhausted: batch
        // history is authoritative, so zero must never fall back to legacy stock.
        $batches = IngredientBatch::withoutGlobalScope(BranchScope::class)
            ->whereIn('ingredient_id', $ingredientIds)
            ->select('ingredient_id', 'branch_id', 'storage_location_id')
            ->selectRaw('SUM(CASE WHEN remaining_qty > 0 AND (expiry_date IS NULL OR expiry_date >= ?) THEN remaining_qty ELSE 0 END) AS usable', [now()->toDateString()])
            ->groupBy('ingredient_id', 'branch_id', 'storage_location_id')->get();
        foreach ($batches as $row) {
            foreach ($snapshot->keys($row->ingredient_id, $row->branch_id, $row->storage_location_id) as $key) {
                $snapshot->batches[$key] = ($snapshot->batches[$key] ?? 0.0) + (float) $row->usable;
            }
        }

        $stocks = IngredientStock::query()
            ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
            ->whereIn('ingredient_stock.ingredient_id', $ingredientIds)
            ->get(['ingredient_stock.ingredient_id', 'storage_locations.branch_id', 'ingredient_stock.storage_location_id', 'ingredient_stock.quantity', 'ingredient_stock.reorder_threshold']);
        foreach ($stocks as $row) {
            foreach ($snapshot->keys($row->ingredient_id, $row->branch_id, $row->storage_location_id) as $key) {
                $snapshot->legacy[$key] = ($snapshot->legacy[$key] ?? 0.0) + (float) $row->quantity;
            }
            $key = $row->ingredient_id.':b:'.$row->branch_id;
            $snapshot->thresholds[$key] = ($snapshot->thresholds[$key] ?? 0.0) + (float) $row->reorder_threshold;
        }

        return $snapshot;
    }

    private function keys(int $ingredientId, ?int $branchId, ?int $locationId): array
    {
        return [$ingredientId.':all', $ingredientId.':b:'.$branchId, $ingredientId.':l:'.$locationId];
    }

    private function quantity(string $key): float
    {
        return $this->batches[$key] ?? $this->legacy[$key] ?? 0.0;
    }

    public function atLocation(int $ingredientId, int $locationId): float
    {
        return $this->quantity($ingredientId.':l:'.$locationId);
    }

    public function atBranch(int $ingredientId, int $branchId): float
    {
        return $this->quantity($ingredientId.':b:'.$branchId);
    }

    public function allBranches(int $ingredientId): float
    {
        return $this->quantity($ingredientId.':all');
    }

    public function threshold(Ingredient $ingredient, int $branchId): float
    {
        $sum = $this->thresholds[$ingredient->id.':b:'.$branchId] ?? 0.0;

        return $sum > 0 ? $sum : (float) $ingredient->reorder_threshold;
    }
}
