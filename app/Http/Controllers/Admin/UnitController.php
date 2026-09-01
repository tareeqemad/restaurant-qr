<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Helpers\Qty;
use App\Support\AdminShell;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Ingredient::class);
        $units = Unit::orderBy('unit_type')->orderBy('factor_to_base')->paginate(30);
        $canManage = (bool) auth()->user()?->can('manage', Ingredient::class);
        $units->through(fn (Unit $unit) => [
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'type' => $unit->unit_type,
            'typeLabel' => $this->typeLabel($unit->unit_type),
            'factor' => Qty::format($unit->factor_to_base),
            'base' => (bool) $unit->is_base,
            'used' => $this->isUsed($unit),
            'canEdit' => $canManage,
            'urls' => [
                'edit' => route('admin.units.edit', $unit),
                'destroy' => route('admin.units.destroy', $unit),
            ],
        ]);

        return AdminShell::render('Admin/Units/Index', [
            'units' => $units,
            'can' => ['manage' => $canManage],
            'urls' => ['create' => route('admin.units.create')],
        ]);
    }

    public function create()
    {
        $this->authorize('manage', Ingredient::class);
        return AdminShell::render('Admin/Units/Form', $this->formProps());
    }

    public function store(Request $request)
    {
        $this->authorize('manage', Ingredient::class);
        Unit::create($this->valid($request));
        return redirect()->route('admin.units.index')->with('success', 'تم');
    }

    public function edit(Unit $unit)
    {
        $this->authorize('manage', Ingredient::class);
        return AdminShell::render('Admin/Units/Form', $this->formProps($unit));
    }

    public function update(Request $request, Unit $unit)
    {
        $this->authorize('manage', Ingredient::class);
        $unit->update($this->valid($request, $unit->id));
        return redirect()->route('admin.units.index')->with('success', 'تم');
    }

    public function destroy(Unit $unit)
    {
        $this->authorize('manage', Ingredient::class);
        if ($this->isUsed($unit)) {
            return back()->with('error', 'لا يمكن حذف وحدة مستخدمة في مكوّن أو وصفة أو حركة مخزون. يمكنك تعديل اسمها فقط.');
        }

        $unit->delete();
        return back()->with('success', 'تم حذف وحدة القياس.');
    }

    protected function valid(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16', \Illuminate\Validation\Rule::unique('units')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'unit_type' => ['required', 'in:weight,volume,count,length'],
            'factor_to_base' => ['required', 'numeric', 'min:0.0000001'],
            'is_base' => ['sometimes', 'boolean'],
        ]);
    }

    protected function formProps(?Unit $unit = null): array
    {
        return [
            'unit' => [
                'id' => $unit?->id,
                'code' => $unit?->code ?? '',
                'name' => $unit?->name ?? '',
                'type' => $unit?->unit_type ?? 'weight',
                'factor' => $unit?->factor_to_base ?? 1,
                'base' => $unit ? (bool) $unit->is_base : false,
                'used' => $unit ? $this->isUsed($unit) : false,
            ],
            'types' => [
                ['value' => 'weight', 'label' => 'وزن', 'baseExample' => 'غرام', 'example' => 'كيلوغرام = 1000 غرام'],
                ['value' => 'volume', 'label' => 'حجم', 'baseExample' => 'ملليلتر', 'example' => 'لتر = 1000 مل'],
                ['value' => 'count', 'label' => 'عدد', 'baseExample' => 'قطعة', 'example' => 'دزينة = 12 قطعة'],
                ['value' => 'length', 'label' => 'طول', 'baseExample' => 'سنتيمتر', 'example' => 'متر = 100 سم'],
            ],
            'urls' => [
                'index' => route('admin.units.index'),
                'submit' => $unit ? route('admin.units.update', $unit) : route('admin.units.store'),
            ],
        ];
    }

    protected function typeLabel(string $type): string
    {
        return match ($type) {
            'weight' => 'وزن', 'volume' => 'حجم', 'count' => 'عدد', 'length' => 'طول', default => $type,
        };
    }

    protected function isUsed(Unit $unit): bool
    {
        foreach ([
            ['ingredients', 'base_unit_id'], ['recipe_items', 'unit_id'],
            ['modifier_recipe_items', 'unit_id'], ['inventory_movements', 'unit_id'],
            ['purchase_order_items', 'unit_id'], ['purchase_receipt_items', 'unit_id'],
            ['supplier_invoice_items', 'unit_id'], ['ingredient_supplier_prices', 'unit_id'],
        ] as [$table, $column]) {
            if (DB::table($table)->where($column, $unit->id)->exists()) return true;
        }

        return false;
    }
}
