<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\PurchaseOrderItem;
use App\Models\StockCountItem;
use App\Models\StorageLocation;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $referenceTypes = [
            PurchaseOrderItem::class => 'مشتريات',
            OrderItem::class => 'مبيعات وطلبات',
            StockCountItem::class => 'جرد',
            IngredientBatch::class => 'دفعات / هدر',
        ];

        $q = InventoryMovement::with([
            'ingredient.baseUnit',
            'user',
            'unit',
            'batch',
            'storageLocation',
            'reference',
        ]);

        if ($t = $request->get('type')) $q->where('type', $t);
        if ($i = $request->get('ingredient_id')) $q->where('ingredient_id', $i);
        if ($l = $request->get('storage_location_id')) $q->where('storage_location_id', $l);
        if ($r = $request->get('reference_type')) $q->where('reference_type', $r);
        if ($d = $request->get('from')) $q->whereDate('occurred_at', '>=', $d);
        if ($d = $request->get('to')) $q->whereDate('occurred_at', '<=', $d);

        $movements = $q->latest('occurred_at')->paginate(30)->withQueryString();
        $movements->getCollection()->loadMorph('reference', [
            PurchaseOrderItem::class => ['purchaseOrder'],
            OrderItem::class => ['order'],
            StockCountItem::class => ['stockCount'],
        ]);
        $movements->getCollection()->each(function (InventoryMovement $movement) {
            $movement->setAttribute('reference_meta', $this->referenceMeta($movement));
        });

        $today = InventoryMovement::whereDate('occurred_at', today());
        $stats = [
            'today'  => (clone $today)->count(),
            'in'     => (clone $today)->where('type', 'in')->count(),
            'out'    => (clone $today)->where('type', 'out')->count(),
            'waste'  => (clone $today)->where('type', 'waste')->count(),
            'return' => (clone $today)->where('type', 'return')->count(),
            'adjustment' => (clone $today)->where('type', 'adjustment')->count(),
        ];
        return view('admin.inventory.index', [
            'movements'   => $movements,
            'ingredients' => Ingredient::orderBy('name')->get(),
            'storageLocations' => StorageLocation::where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
            'referenceTypes' => $referenceTypes,
            'stats'       => $stats,
        ]);
    }

    protected function referenceMeta(InventoryMovement $movement): array
    {
        $reference = $movement->reference;

        if (! $reference) {
            return [
                'label' => $movement->reference_type ? class_basename($movement->reference_type).' #'.$movement->reference_id : 'يدوي',
                'url' => null,
                'icon' => 'bi-pencil-square',
            ];
        }

        if ($reference instanceof PurchaseOrderItem) {
            $po = $reference->purchaseOrder;

            return [
                'label' => $po ? 'أمر شراء '.$po->number : 'بند شراء #'.$reference->id,
                'url' => $po ? route('admin.purchase-orders.show', $po) : null,
                'icon' => 'bi-bag-check',
            ];
        }

        if ($reference instanceof OrderItem) {
            $order = $reference->order;

            return [
                'label' => $order ? 'طلب '.$order->number : 'بند طلب #'.$reference->id,
                'url' => $order ? route('admin.orders.show', $order) : null,
                'icon' => 'bi-receipt-cutoff',
            ];
        }

        if ($reference instanceof StockCountItem) {
            $count = $reference->stockCount;

            return [
                'label' => $count ? 'جرد '.$count->number : 'بند جرد #'.$reference->id,
                'url' => $count ? route('admin.stock-counts.show', $count) : null,
                'icon' => 'bi-clipboard2-check',
            ];
        }

        if ($reference instanceof IngredientBatch) {
            return [
                'label' => 'دفعة #'.($reference->batch_number ?: $reference->id),
                'url' => route('admin.batches.index', ['ingredient_id' => $reference->ingredient_id]),
                'icon' => 'bi-box2-heart',
            ];
        }

        return [
            'label' => class_basename($movement->reference_type).' #'.$movement->reference_id,
            'url' => null,
            'icon' => 'bi-link-45deg',
        ];
    }
}
