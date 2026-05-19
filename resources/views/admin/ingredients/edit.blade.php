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
                                <select :name="`lines[${{{ $existingLines->count() }} + i - 1}][ingredient_id]`" class="form-select form-select-sm" required>
                                    @foreach($allIngredients as $i)
                                        <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->sku }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.0001" min="0.0001"
                                       :name="`lines[${{{ $existingLines->count() }} + i - 1}][quantity]`"
                                       class="form-control form-control-sm text-end" required>
                            </td>
                            <td>
                                <select :name="`lines[${{{ $existingLines->count() }} + i - 1}][unit_id]`" class="form-select form-select-sm" required>
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
