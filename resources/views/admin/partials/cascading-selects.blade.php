{{--
    Cascading Selects: Operator → Generation Unit → Generator
    
    Usage:
    @include('admin.partials.cascading-selects', [
        'operators' => $operators,                    // Required if canSelectOperator
        'affiliatedOperator' => $affiliatedOperator,  // Required if user is CompanyOwner/Employee/Technician
        'canSelectOperator' => $canSelectOperator,    // Boolean: can user select operator?
        'showGenerator' => true,                      // Optional: show generator select (default: true)
        'showGenerationUnit' => true,                 // Optional: show generation unit select (default: true)
        'selectedOperatorId' => old('operator_id'),   // Optional: pre-selected operator
        'selectedGenerationUnitId' => old('generation_unit_id'),
        'selectedGeneratorId' => old('generator_id'),
        'operatorRequired' => true,                   // Optional: is operator required?
        'generationUnitRequired' => true,             // Optional: is generation unit required?
        'generatorRequired' => true,                  // Optional: is generator required?
        'colClass' => 'col-md-3',                     // Optional: column class for each select (default: col-md-3)
        'operatorLabel' => __('admin.common.operator'),                  // Optional: custom labels
        'generationUnitLabel' => __('admin.common.generation_unit'),
        'generatorLabel' => __('admin.common.generator'),
        'idPrefix' => '',                             // Optional: prefix for IDs (for multiple instances)
        'useSelect2' => true,                         // Optional: use Select2 styling
        'generationUnits' => null,                    // Optional: pre-loaded generation units (for affiliated users)
        'generators' => null,                         // Optional: pre-loaded generators (for affiliated users)
    ])
--}}

@php
    $showGenerator = $showGenerator ?? true;
    $showGenerationUnit = $showGenerationUnit ?? true;
    $operatorRequired = $operatorRequired ?? true;
    $generationUnitRequired = $generationUnitRequired ?? true;
    $generatorRequired = $generatorRequired ?? true;
    $colClass = $colClass ?? 'col-md-3';
    $operatorLabel = $operatorLabel ?? __('admin.common.operator');
    $generationUnitLabel = $generationUnitLabel ?? __('admin.common.generation_unit');
    $generatorLabel = $generatorLabel ?? __('admin.common.generator');
    $idPrefix = $idPrefix ?? '';
    $useSelect2 = $useSelect2 ?? true;
    $selectClass = $useSelect2 ? 'form-select select2' : 'form-select';
    
    $selectedOperatorId = $selectedOperatorId ?? old('operator_id') ?? request('operator_id');
    $selectedGenerationUnitId = $selectedGenerationUnitId ?? old('generation_unit_id') ?? request('generation_unit_id');
    $selectedGeneratorId = $selectedGeneratorId ?? old('generator_id') ?? request('generator_id');
    
    // Determine whether the user can choose an operator.
    // Users affiliated with an operator cannot choose another operator.
    $canSelectOperator = $canSelectOperator ?? (!auth()->user()->isAffiliatedWithOperator());
    
    // Resolve the operator affiliated with the user when not passed in.
    $affiliatedOperator = $affiliatedOperator ?? auth()->user()->getAffiliatedOperator();
    
    // Generation units and generators for affiliated users.
    $generationUnits = $generationUnits ?? ($affiliatedOperator?->generationUnits ?? collect());
    $generators = $generators ?? collect();
@endphp

@if($canSelectOperator)
    {{-- User can choose any operator. --}}
    <div class="{{ $colClass }}" id="{{ $idPrefix }}operator_wrapper">
        <label class="form-label fw-semibold">
            <i class="bi bi-building text-primary me-1"></i>
            {{ $operatorLabel }}
            @if($operatorRequired)<span class="text-danger">*</span>@endif
        </label>
        <select name="operator_id" id="{{ $idPrefix }}operator_id" 
                class="{{ $selectClass }} @error('operator_id') is-invalid @enderror"
                data-placeholder="{{ __('admin.common.choose_value', ['label' => $operatorLabel]) }}"
                @if($operatorRequired) required @endif>
            <option value="">{{ __('admin.common.choose_value', ['label' => $operatorLabel]) }}</option>
            @foreach($operators as $operator)
                <option value="{{ $operator->id }}" 
                        {{ $selectedOperatorId == $operator->id ? 'selected' : '' }}>
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
                <i class="bi bi-diagram-3 text-success me-1"></i>
                {{ $generationUnitLabel }}
                @if($generationUnitRequired)<span class="text-danger">*</span>@endif
            </label>
            <select name="generation_unit_id" id="{{ $idPrefix }}generation_unit_id" 
                    class="{{ $selectClass }} @error('generation_unit_id') is-invalid @enderror"
                    data-placeholder="{{ __('admin.common.choose_value', ['label' => $generationUnitLabel]) }}"
                    @if($generationUnitRequired) required @endif
                    @if(!$generationUnits->isNotEmpty()) disabled @endif>
                <option value="">{{ __('admin.common.choose_value', ['label' => $generationUnitLabel]) }}</option>
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
                <i class="bi bi-info-circle me-1"></i>
                @if($generationUnits->isNotEmpty())
                    {{ trans_choice('admin.common.available_generation_units', $generationUnits->count(), ['count' => $generationUnits->count()]) }}
                @else
                    {{ __('admin.common.choose_first', ['label' => $operatorLabel]) }}
                @endif
            </small>
        </div>
    @endif

    @if($showGenerator)
        <div class="{{ $colClass }}" id="{{ $idPrefix }}generator_wrapper">
            <label class="form-label fw-semibold">
                <i class="bi bi-lightning-charge text-warning me-1"></i>
                {{ $generatorLabel }}
                @if($generatorRequired)<span class="text-danger">*</span>@endif
            </label>
            <select name="generator_id" id="{{ $idPrefix }}generator_id" 
                    class="{{ $selectClass }} @error('generator_id') is-invalid @enderror"
                    data-placeholder="{{ __('admin.common.choose_value', ['label' => $generatorLabel]) }}"
                    @if($generatorRequired) required @endif
                    @if(!$generators->isNotEmpty()) disabled @endif>
                <option value="">{{ __('admin.common.choose_value', ['label' => $generatorLabel]) }}</option>
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
                <i class="bi bi-info-circle me-1"></i>
                @if($generators->isNotEmpty())
                    {{ trans_choice('admin.common.available_generators', $generators->count(), ['count' => $generators->count()]) }}
                @else
                    {{ __('admin.common.choose_first', ['label' => $generationUnitLabel]) }}
                @endif
            </small>
        </div>
    @endif
@else
    {{-- User is affiliated with an operator; operator is selected automatically. --}}
    <input type="hidden" name="operator_id" id="{{ $idPrefix }}operator_id" value="{{ $affiliatedOperator->id }}">
    
    <div class="{{ $colClass }}" id="{{ $idPrefix }}operator_wrapper">
        <label class="form-label fw-semibold">
            <i class="bi bi-building text-primary me-1"></i>
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
            <i class="bi bi-check-circle me-1"></i>
            {{ __('admin.common.auto_selected') }}
        </small>
    </div>

    @if($showGenerationUnit)
        <div class="{{ $colClass }}" id="{{ $idPrefix }}generation_unit_wrapper">
            <label class="form-label fw-semibold">
                <i class="bi bi-diagram-3 text-success me-1"></i>
                {{ $generationUnitLabel }}
                @if($generationUnitRequired)<span class="text-danger">*</span>@endif
            </label>
            <select name="generation_unit_id" id="{{ $idPrefix }}generation_unit_id" 
                    class="{{ $selectClass }} @error('generation_unit_id') is-invalid @enderror"
                    data-placeholder="{{ __('admin.common.choose_value', ['label' => $generationUnitLabel]) }}"
                    @if($generationUnitRequired) required @endif>
                <option value="">{{ __('admin.common.choose_value', ['label' => $generationUnitLabel]) }}</option>
                @foreach($generationUnits as $unit)
                    <option value="{{ $unit->id }}" 
                            {{ $selectedGenerationUnitId == $unit->id ? 'selected' : '' }}>
                        {{ $unit->name }} ({{ $unit->unit_code }})
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
                <i class="bi bi-lightning-charge text-warning me-1"></i>
                {{ $generatorLabel }}
                @if($generatorRequired)<span class="text-danger">*</span>@endif
            </label>
            <select name="generator_id" id="{{ $idPrefix }}generator_id" 
                    class="{{ $selectClass }} @error('generator_id') is-invalid @enderror"
                    data-placeholder="{{ __('admin.common.choose_value', ['label' => $generatorLabel]) }}"
                    @if($generatorRequired) required @endif
                    disabled>
                <option value="">{{ __('admin.common.choose_value', ['label' => $generatorLabel]) }}</option>
                {{-- Generators are loaded over AJAX after choosing a generation unit. --}}
            </select>
            @error('generator_id')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
            <small class="form-text text-muted" id="{{ $idPrefix }}generator_help">
                <i class="bi bi-info-circle me-1"></i>
                {{ __('admin.common.choose_first', ['label' => $generationUnitLabel]) }}
            </small>
        </div>
    @endif
@endif
