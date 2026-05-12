{{--
    Cascading Selects Component: Operator → Generation Unit → Generator
    Uses Select2 with RTL Arabic support

    Usage:
    <x-admin.operator-cascade
        :operators="$operators"
        :showGenerator="true"
        :showGenerationUnit="true"
        :operatorRequired="true"
        :generationUnitRequired="true"
        :generatorRequired="true"
        :selectedOperatorId="old('operator_id')"
        :selectedGenerationUnitId="old('generation_unit_id')"
        :selectedGeneratorId="old('generator_id')"
        colClass="col-md-4"
        idPrefix=""
    />
--}}

@props([
    'operators' => collect(),
    'affiliatedOperator' => null,
    'canSelectOperator' => null,
    'showGenerator' => true,
    'showGenerationUnit' => true,
    'operatorRequired' => true,
    'generationUnitRequired' => false,
    'generatorRequired' => false,
    'selectedOperatorId' => null,
    'selectedGenerationUnitId' => null,
    'selectedGeneratorId' => null,
    'colClass' => 'col-md-4',
    'operatorLabel' => 'المشغل',
    'generationUnitLabel' => 'وحدة التوليد',
    'generatorLabel' => 'المولد',
    'idPrefix' => '',
    'generationUnits' => null,
    'generators' => null,
    'routes' => null,
    'onOperatorChange' => 'null',
    'onGenerationUnitChange' => 'null',
    'onGeneratorChange' => 'null',
])

@php
    $canSelectOperator = $canSelectOperator ?? (!auth()->user()->isAffiliatedWithOperator());
    $affiliatedOperator = $affiliatedOperator ?? auth()->user()->getAffiliatedOperator();
    $generationUnits = $generationUnits ?? ($affiliatedOperator?->generationUnits ?? collect());
    $generators = $generators ?? collect();
    $selectedOperatorId = $selectedOperatorId ?? old('operator_id') ?? request('operator_id');
    $selectedGenerationUnitId = $selectedGenerationUnitId ?? old('generation_unit_id') ?? request('generation_unit_id');
    $selectedGeneratorId = $selectedGeneratorId ?? old('generator_id') ?? request('generator_id');

    // Default routes - can be overridden per page
    $defaultRoutes = [
        'generationUnits' => url('/admin/operators') . '/__OPERATOR__/generation-units',
        'generators' => url('/admin/generation-units') . '/__UNIT__/generators-list',
    ];
    $routes = array_merge($defaultRoutes, $routes ?? []);
@endphp

@if($canSelectOperator)
    {{-- SuperAdmin / Admin / EnergyAuthority → يختار المشغل --}}
    <div class="{{ $colClass }}" id="{{ $idPrefix }}operator_wrapper">
        <label class="form-label fw-semibold">
            {{ $operatorLabel }}
            @if($operatorRequired)<span class="text-danger">*</span>@endif
        </label>
        <select name="operator_id" id="{{ $idPrefix }}operator_id"
                class="form-select select2 @error('operator_id') is-invalid @enderror"
                data-placeholder="اختر {{ $operatorLabel }}"
                @if($operatorRequired) required @endif>
            <option value="">اختر {{ $operatorLabel }}</option>
            @foreach($operators as $operator)
                <option value="{{ $operator->id }}"
                        {{ (string)$selectedOperatorId === (string)$operator->id ? 'selected' : '' }}>
                    {{ $operator->name }}
                    @if($operator->unit_number) - {{ $operator->unit_number }} @endif
                </option>
            @endforeach
        </select>
        @error('operator_id')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    @if($showGenerationUnit)
        <div class="{{ $colClass }}" id="{{ $idPrefix }}generation_unit_wrapper">
            <label class="form-label fw-semibold">
                {{ $generationUnitLabel }}
                @if($generationUnitRequired)<span class="text-danger">*</span>@endif
            </label>
            <select name="generation_unit_id" id="{{ $idPrefix }}generation_unit_id"
                    class="form-select select2 @error('generation_unit_id') is-invalid @enderror"
                    data-placeholder="اختر {{ $generationUnitLabel }}"
                    @if($generationUnitRequired) required @endif
                    @if(!$generationUnits->isNotEmpty()) disabled @endif>
                <option value="">اختر {{ $generationUnitLabel }}</option>
                @foreach($generationUnits as $unit)
                    <option value="{{ $unit->id }}" {{ (string)$selectedGenerationUnitId === (string)$unit->id ? 'selected' : '' }}>
                        {{ $unit->name }} ({{ $unit->unit_code ?? $unit->unit_number ?? '' }})
                    </option>
                @endforeach
            </select>
            @error('generation_unit_id')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
            <small class="form-text text-muted" id="{{ $idPrefix }}generation_unit_help">
                @if($generationUnits->isNotEmpty())
                    {{ $generationUnits->count() }} وحدة متاحة
                @else
                    اختر {{ $operatorLabel }} أولاً
                @endif
            </small>
        </div>
    @endif

    @if($showGenerator)
        <div class="{{ $colClass }}" id="{{ $idPrefix }}generator_wrapper">
            <label class="form-label fw-semibold">
                {{ $generatorLabel }}
                @if($generatorRequired)<span class="text-danger">*</span>@endif
            </label>
            <select name="generator_id" id="{{ $idPrefix }}generator_id"
                    class="form-select select2 @error('generator_id') is-invalid @enderror"
                    data-placeholder="اختر {{ $generatorLabel }}"
                    @if($generatorRequired) required @endif
                    @if(!$generators->isNotEmpty()) disabled @endif>
                <option value="">اختر {{ $generatorLabel }}</option>
                @foreach($generators as $gen)
                    <option value="{{ $gen->id }}" {{ (string)$selectedGeneratorId === (string)$gen->id ? 'selected' : '' }}>
                        {{ $gen->name }} ({{ $gen->generator_number ?? $gen->id }})
                    </option>
                @endforeach
            </select>
            @error('generator_id')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
            <small class="form-text text-muted" id="{{ $idPrefix }}generator_help">
                @if($generators->isNotEmpty())
                    {{ $generators->count() }} مولد متاح
                @else
                    اختر {{ $generationUnitLabel }} أولاً
                @endif
            </small>
        </div>
    @endif
@else
    {{-- مستخدم تابع لمشغل → المشغل محدد تلقائياً --}}
    <input type="hidden" name="operator_id" id="{{ $idPrefix }}operator_id" value="{{ $affiliatedOperator->id }}">

    <div class="{{ $colClass }}" id="{{ $idPrefix }}operator_wrapper">
        <label class="form-label fw-semibold">
            {{ $operatorLabel }}
        </label>
        <div class="input-group">
            <span class="input-group-text bg-light">
                <i class="bi bi-lock-fill text-muted"></i>
            </span>
            <input type="text" class="form-control bg-light"
                   value="{{ $affiliatedOperator->name }}"
                   disabled readonly>
        </div>
        <small class="form-text text-success">
            <i class="bi bi-check-circle me-1"></i>محدد تلقائياً
        </small>
    </div>

    @if($showGenerationUnit)
        <div class="{{ $colClass }}" id="{{ $idPrefix }}generation_unit_wrapper">
            <label class="form-label fw-semibold">
                {{ $generationUnitLabel }}
                @if($generationUnitRequired)<span class="text-danger">*</span>@endif
            </label>
            <select name="generation_unit_id" id="{{ $idPrefix }}generation_unit_id"
                    class="form-select @error('generation_unit_id') is-invalid @enderror"
                    @if($generationUnitRequired) required @endif>
                <option value="">اختر {{ $generationUnitLabel }}</option>
                @foreach($generationUnits as $unit)
                    <option value="{{ $unit->id }}"
                            {{ (string)$selectedGenerationUnitId === (string)$unit->id ? 'selected' : '' }}>
                        {{ $unit->name }} ({{ $unit->unit_code ?? '' }})
                    </option>
                @endforeach
            </select>
            @error('generation_unit_id')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
    @endif

    @if($showGenerator)
        <div class="{{ $colClass }}" id="{{ $idPrefix }}generator_wrapper">
            <label class="form-label fw-semibold">
                {{ $generatorLabel }}
                @if($generatorRequired)<span class="text-danger">*</span>@endif
            </label>
            <select name="generator_id" id="{{ $idPrefix }}generator_id"
                    class="form-select @error('generator_id') is-invalid @enderror"
                    data-placeholder="اختر {{ $generatorLabel }}"
                    @if($generatorRequired) required @endif
                    disabled>
                <option value="">اختر {{ $generatorLabel }}</option>
            </select>
            @error('generator_id')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
            <small class="form-text text-muted" id="{{ $idPrefix }}generator_help">
                اختر {{ $generationUnitLabel }} أولاً
            </small>
        </div>
    @endif
@endif

{{-- Scripts: Select2 + Cascading Logic --}}
@once
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/dashtic/libs/select2/select2.min.css') }}">
@endpush
@endonce

@push('scripts')
@once
<script src="{{ asset('assets/dashtic/libs/select2/select2.min.js') }}"></script>
<script src="{{ asset('assets/dashtic/js/cascading-selects.js') }}"></script>
@endonce
<script>
(function($) {
    'use strict';
    $(document).ready(function() {
        window.cascadingSelects{{ $idPrefix ? ucfirst($idPrefix) : '' }} = CascadingSelects.init({
            prefix: '{{ $idPrefix }}',
            operatorSelect: '#{{ $idPrefix }}operator_id',
            generationUnitSelect: '#{{ $idPrefix }}generation_unit_id',
            generatorSelect: '#{{ $idPrefix }}generator_id',
            routes: {
                generationUnits: "{{ $routes['generationUnits'] }}",
                generators: "{{ $routes['generators'] }}"
            },
            canSelectOperator: @json($canSelectOperator),
            affiliatedOperatorId: @json($affiliatedOperator?->id),
            initialOperatorId: @json($selectedOperatorId),
            initialGenerationUnitId: @json($selectedGenerationUnitId),
            initialGeneratorId: @json($selectedGeneratorId),
            useSelect2: typeof $.fn.select2 !== 'undefined',
            onOperatorChange: {!! $onOperatorChange !!},
            onGenerationUnitChange: {!! $onGenerationUnitChange !!},
            onGeneratorChange: {!! $onGeneratorChange !!}
        });
    });
})(jQuery);
</script>
@endpush
