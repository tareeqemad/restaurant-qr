{{--
    Cascading Selects Scripts - Include after the form
    
    Usage:
    @include('admin.partials.cascading-selects-scripts', [
        'canSelectOperator' => $canSelectOperator,
        'affiliatedOperatorId' => $affiliatedOperator->id ?? null,
        'initialOperatorId' => old('operator_id') ?? request('operator_id'),
        'initialGenerationUnitId' => old('generation_unit_id') ?? request('generation_unit_id'),
        'initialGeneratorId' => old('generator_id') ?? request('generator_id'),
        'idPrefix' => '',
        'onOperatorChange' => null,        // JavaScript callback function name
        'onGenerationUnitChange' => null,  // JavaScript callback function name
        'onGeneratorChange' => null,       // JavaScript callback function name
    ])
--}}

@php
    $canSelectOperator = $canSelectOperator ?? (!auth()->user()->isCompanyOwner() && !auth()->user()->isEmployee() && !auth()->user()->isTechnician());
    $affiliatedOperatorId = $affiliatedOperatorId ?? null;
    $initialOperatorId = $initialOperatorId ?? old('operator_id') ?? request('operator_id');
    $initialGenerationUnitId = $initialGenerationUnitId ?? old('generation_unit_id') ?? request('generation_unit_id');
    $initialGeneratorId = $initialGeneratorId ?? old('generator_id') ?? request('generator_id');
    $idPrefix = $idPrefix ?? '';
    $onOperatorChange = $onOperatorChange ?? 'null';
    $onGenerationUnitChange = $onGenerationUnitChange ?? 'null';
    $onGeneratorChange = $onGeneratorChange ?? 'null';
    $cascadingRoutes = $cascadingRoutes ?? [
        'generationUnits' => url('/admin/operators') . '/__OPERATOR__/generation-units',
        'generators' => url('/admin/generation-units') . '/__UNIT__/generators-list'
    ];
@endphp

<script src="{{ asset('assets/dashtic/js/cascading-selects.js') }}"></script>
<script>
(function($) {
    'use strict';
    
    $(document).ready(function() {
        window.cascadingSelects{{ $idPrefix ? ucfirst($idPrefix) : '' }} = CascadingSelects.init({
            operatorSelect: '#{{ $idPrefix }}operator_id',
            generationUnitSelect: '#{{ $idPrefix }}generation_unit_id',
            generatorSelect: '#{{ $idPrefix }}generator_id',
            routes: {
                generationUnits: "{{ $cascadingRoutes['generationUnits'] ?? url('/admin/operators') . '/__OPERATOR__/generation-units' }}",
                generators: "{{ $cascadingRoutes['generators'] ?? url('/admin/generation-units') . '/__UNIT__/generators-list' }}"
            },
            canSelectOperator: @json($canSelectOperator),
            affiliatedOperatorId: @json($affiliatedOperatorId),
            initialOperatorId: @json($initialOperatorId),
            initialGenerationUnitId: @json($initialGenerationUnitId),
            initialGeneratorId: @json($initialGeneratorId),
            useSelect2: typeof $.fn.select2 !== 'undefined',
            onOperatorChange: {!! $onOperatorChange !!},
            onGenerationUnitChange: {!! $onGenerationUnitChange !!},
            onGeneratorChange: {!! $onGeneratorChange !!}
        });
    });
})(jQuery);
</script>
