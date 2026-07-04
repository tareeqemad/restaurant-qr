@extends('layouts.admin')
@section('title','تعديل: '.$ingredient->name)
@section('content')
<x-admin.breadcrumb title="تعديل: {{ $ingredient->name }}" icon="bi-pencil-square"
    :crumbs="[['label' => 'المكونات', 'url' => route('admin.ingredients.index')]]" />

<x-admin.data-panel title="النموذج" icon="bi-pencil-square">
    <x-slot:actions>
        <a href="{{ route('admin.ingredients.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.ingredients.update', $ingredient) }}">
            @method('PUT')
            @include('admin.ingredients._form')
        </form>
</x-admin.data-panel>

{{-- ════════════════════════════════════════════════════════════════
     Alternate units (pack sizes) — every ingredient may have one or
     more purchase/sale pack sizes on top of its canonical base unit.
     Examples: cola sold by can but bought by 24-can carton; flour
     stored by gram but bought in 50kg sacks.
     ════════════════════════════════════════════════════════════════ --}}
@php
    $existingUnits = $ingredient->units;
    $baseUnitCode  = $ingredient->baseUnit?->code ?? '';
@endphp

<x-admin.data-panel title="وحدات الصنف (أحجام العبوات)" icon="bi-boxes" class="mt-3">
    <div class="alert alert-info small">
        <i class="bi bi-info-circle"></i>
        الوحدة الأساسية: <strong>{{ $ingredient->baseUnit?->name ?? '—' }} ({{ $baseUnitCode }})</strong>.
        أضف هنا أحجام عبوات أخرى تشتري/تبيع بها (مثلاً: <em>كرتون 24 علبة</em>،
        <em>بالة 144 علبة</em>). عمود <strong>"تعادل"</strong> = كم وحدة أساسية في عبوة واحدة.
        المخزون يبقى محسوب بالوحدة الأساسية دائماً — العبوات شيء مختصر للشراء فقط.
    </div>

    <form method="POST" action="{{ route('admin.ingredients.units.update', $ingredient) }}"
          x-data="ingredientUnitsEditor({{ $existingUnits->count() }})">
        @csrf

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>اسم الوحدة <span class="text-danger">*</span></th>
                        <th class="text-end" style="width:120px">تعادل ({{ $baseUnitCode }})</th>
                        <th style="width:160px">باركود</th>
                        <th class="text-end" style="width:120px">سعر الشراء</th>
                        <th class="text-end" style="width:120px">سعر البيع</th>
                        <th class="text-center" style="width:90px">افتراضي شراء</th>
                        <th style="width:50px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($existingUnits as $idx => $u)
                        <tr>
                            <td>
                                <input type="text" name="units[{{ $idx }}][name]"
                                       value="{{ $u->name }}" required
                                       class="form-control form-control-sm">
                            </td>
                            <td>
                                <input type="number" step="0.0001" min="0.0001"
                                       name="units[{{ $idx }}][factor_to_base]"
                                       value="{{ rtrim(rtrim((string) $u->factor_to_base, '0'), '.') }}" required
                                       class="form-control form-control-sm text-end">
                            </td>
                            <td>
                                <input type="text" name="units[{{ $idx }}][barcode]"
                                       value="{{ $u->barcode }}" dir="ltr"
                                       class="form-control form-control-sm"
                                       placeholder="—">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       name="units[{{ $idx }}][purchase_price]"
                                       value="{{ $u->purchase_price !== null ? rtrim(rtrim((string) $u->purchase_price, '0'), '.') : '' }}"
                                       class="form-control form-control-sm text-end"
                                       placeholder="—">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       name="units[{{ $idx }}][sale_price]"
                                       value="{{ $u->sale_price !== null ? rtrim(rtrim((string) $u->sale_price, '0'), '.') : '' }}"
                                       class="form-control form-control-sm text-end"
                                       placeholder="—">
                            </td>
                            <td class="text-center">
                                <input type="hidden" name="units[{{ $idx }}][is_default_purchase]" value="0">
                                <input type="radio" name="default_purchase_idx"
                                       value="{{ $idx }}"
                                       @checked($u->is_default_purchase)
                                       onchange="document.querySelectorAll('input[name$=\'[is_default_purchase]\']').forEach(el => el.value = '0'); this.previousElementSibling.value = '1';"
                                       class="form-check-input">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="this.closest('tr').remove()">
                                    <i class="bi bi-x"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <template x-for="i in extraRows" :key="i">
                        <tr>
                            @php $base = $existingUnits->count(); @endphp
                            <td>
                                <input type="text"
                                       :name="`units[${ {{ $base }} + i - 1 }][name]`"
                                       required class="form-control form-control-sm"
                                       placeholder="مثلاً: كرتون 24 علبة">
                            </td>
                            <td>
                                <input type="number" step="0.0001" min="0.0001"
                                       :name="`units[${ {{ $base }} + i - 1 }][factor_to_base]`"
                                       required class="form-control form-control-sm text-end"
                                       placeholder="24">
                            </td>
                            <td>
                                <input type="text"
                                       :name="`units[${ {{ $base }} + i - 1 }][barcode]`"
                                       dir="ltr" class="form-control form-control-sm">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       :name="`units[${ {{ $base }} + i - 1 }][purchase_price]`"
                                       class="form-control form-control-sm text-end">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       :name="`units[${ {{ $base }} + i - 1 }][sale_price]`"
                                       class="form-control form-control-sm text-end">
                            </td>
                            <td class="text-center">
                                <input type="hidden" :name="`units[${ {{ $base }} + i - 1 }][is_default_purchase]`" value="0">
                                <input type="radio" name="default_purchase_idx"
                                       :value="`${ {{ $base }} + i - 1 }`"
                                       class="form-check-input"
                                       onchange="document.querySelectorAll('input[name$=\'[is_default_purchase]\']').forEach(el => el.value = '0'); this.previousElementSibling.value = '1';">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="removeRow(i)">
                                    <i class="bi bi-x"></i>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="d-flex gap-2">
            <button type="button" @click="addRow()" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus"></i> إضافة وحدة جديدة
            </button>
            <button class="btn btn-sm btn-success">
                <i class="bi bi-check2"></i> حفظ وحدات الصنف
            </button>
        </div>
    </form>
</x-admin.data-panel>

@push('scripts')
<script>
function ingredientUnitsEditor(initialCount) {
    return {
        extraRows: [],
        nextId: 1,
        addRow() { this.extraRows.push(this.nextId++); },
        removeRow(id) { this.extraRows = this.extraRows.filter(i => i !== id); },
    };
}
</script>
@endpush

{{-- Sub-recipe editor — visible only when the ingredient is flagged as
     composite. The flag is set in the form above; after saving you can
     come back here and build the sub-recipe lines. --}}
@if($ingredient->is_composite)
    @php
        $allIngredients = \App\Models\Ingredient::where('id', '!=', $ingredient->id)
            ->where('active', true)
            ->orderBy('name')->get();
        $allUnits       = \App\Models\Unit::orderBy('name')->get();
        $existingLines  = $ingredient->subRecipe()->with('ingredient', 'unit')->get();
    @endphp

    <x-admin.data-panel title="الوصفة الفرعية" icon="bi-diagram-3" class="mt-3">
        <div class="alert alert-info small">
            <i class="bi bi-info-circle"></i>
            أضف المكوّنات الخام التي تُنتج <strong>{{ rtrim(rtrim((string) ($ingredient->composite_yield ?? 0), '0'), '.') }} {{ $ingredient->baseUnit?->code }}</strong>
            من <strong>{{ $ingredient->name }}</strong>. عند بيع أي صنف يستخدم هذا المركّب، النظام يحلّله تلقائياً ويخصم المكوّنات الفرعية بالنسبة الصحيحة.
        </div>

        <form method="POST" action="{{ route('admin.ingredients.sub_recipe.update', $ingredient) }}"
              x-data="subRecipeEditor({{ $existingLines->count() }})">
            @csrf

            <table class="table table-sm">
                <thead>
                    <tr>
                        <th style="width: 50%">المكوّن الفرعي</th>
                        <th style="width: 20%">الكمية</th>
                        <th style="width: 20%">الوحدة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($existingLines as $idx => $line)
                        <tr>
                            <td>
                                <select name="lines[{{ $idx }}][ingredient_id]" class="form-select form-select-sm" required>
                                    @foreach($allIngredients as $i)
                                        <option value="{{ $i->id }}" @selected($i->id === $line->ingredient_id)>
                                            {{ $i->name }} ({{ $i->sku }})
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.0001" min="0.0001"
                                       name="lines[{{ $idx }}][quantity]" value="{{ $line->quantity }}"
                                       class="form-control form-control-sm text-end" required>
                            </td>
                            <td>
                                <select name="lines[{{ $idx }}][unit_id]" class="form-select form-select-sm" required>
                                    @foreach($allUnits as $u)
                                        <option value="{{ $u->id }}" @selected($u->id === $line->unit_id)>
                                            {{ $u->name }} ({{ $u->code }})
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
                        </tr>
                    @endforeach
                    <template x-for="i in extraRows" :key="i">
                        <tr>
                            <td>
                                <select :name="`lines[${ {{ $existingLines->count() }} + i - 1 }][ingredient_id]`" class="form-select form-select-sm" required>
                                    @foreach($allIngredients as $i)
                                        <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->sku }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.0001" min="0.0001"
                                       :name="`lines[${ {{ $existingLines->count() }} + i - 1 }][quantity]`"
                                       class="form-control form-control-sm text-end" required>
                            </td>
                            <td>
                                <select :name="`lines[${ {{ $existingLines->count() }} + i - 1 }][unit_id]`" class="form-select form-select-sm" required>
                                    @foreach($allUnits as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->code }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" @click="removeRow(i)"><i class="bi bi-x"></i></button></td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <div class="d-flex gap-2">
                <button type="button" @click="addRow()" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus"></i> إضافة مكوّن فرعي
                </button>
                <button class="btn btn-sm btn-success">
                    <i class="bi bi-check2"></i> حفظ الوصفة الفرعية
                </button>
            </div>
        </form>
    </x-admin.data-panel>

    @push('scripts')
    <script>
    function subRecipeEditor(initialCount) {
        return {
            extraRows: [],
            nextId: 1,
            addRow() { this.extraRows.push(this.nextId++); },
            removeRow(id) { this.extraRows = this.extraRows.filter(i => i !== id); },
        };
    }
    </script>
    @endpush
@endif
@endsection
