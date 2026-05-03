<?php

namespace App\Notifications;

use App\Models\Ingredient;

class LowStockNotification extends BaseNotification
{
    public function __construct(public Ingredient $ingredient)
    {
        parent::__construct();
        $this->branchId = $ingredient->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'stock.low'; }

    /** Out of stock = danger; merely below threshold = warning. */
    public function severity(): string
    {
        return ((float) $this->ingredient->current_stock) <= 0
            ? 'danger'
            : 'warning';
    }

    public function icon(): string
    {
        return ((float) $this->ingredient->current_stock) <= 0
            ? 'bi-x-circle-fill'
            : 'bi-exclamation-triangle-fill';
    }

    public function title(): string
    {
        $isOut = ((float) $this->ingredient->current_stock) <= 0;
        return $isOut
            ? "نفد المخزون: {$this->ingredient->name}"
            : "مخزون منخفض: {$this->ingredient->name}";
    }

    public function body(): string
    {
        $current   = number_format((float) $this->ingredient->current_stock, 2);
        $threshold = number_format((float) $this->ingredient->reorder_threshold, 2);
        $unit      = $this->ingredient->unit?->code ?? '';
        return "المتوفّر: {$current} {$unit} · حد الطلب: {$threshold} {$unit}";
    }

    public function actionUrl(): string
    {
        return route('admin.ingredients.index', ['low_stock' => 1]);
    }

    public function actionLabel(): string
    {
        return 'افتح المكونات';
    }

    public function extra(): array
    {
        return [
            'ingredient_id' => $this->ingredient->id,
            'current_stock' => (float) $this->ingredient->current_stock,
        ];
    }
}
