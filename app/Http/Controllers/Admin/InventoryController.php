<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);
        $q = InventoryMovement::with(['ingredient', 'user', 'unit']);
        if ($t = $request->get('type')) $q->where('type', $t);
        if ($i = $request->get('ingredient_id')) $q->where('ingredient_id', $i);
        if ($d = $request->get('from')) $q->whereDate('occurred_at', '>=', $d);
        if ($d = $request->get('to')) $q->whereDate('occurred_at', '<=', $d);
        $movements = $q->latest('occurred_at')->paginate(30)->withQueryString();
        $today = InventoryMovement::whereDate('occurred_at', today());
        $stats = [
            'today'  => (clone $today)->count(),
            'in'     => (clone $today)->where('type', 'in')->count(),
            'out'    => (clone $today)->where('type', 'out')->count(),
            'waste'  => (clone $today)->where('type', 'waste')->count(),
        ];
        return view('admin.inventory.index', [
            'movements'   => $movements,
            'ingredients' => Ingredient::orderBy('name')->get(),
            'stats'       => $stats,
        ]);
    }
}
