@extends('layouts.admin')
@section('title', 'مجموعات الإضافات')

@section('content')
<x-admin.breadcrumb title="مجموعات الإضافات" icon="bi-sliders2"
    subtitle="خيارات الإضافات التي يختارها الزبون مع الأصناف (حجم، تحميص، …)" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي المجموعات', 'value' => $stats['total_groups'],   'icon' => 'bi-sliders2',          'color' => 'primary'],
    ['label' => 'مجموعات مطلوبة',    'value' => $stats['required'],        'icon' => 'bi-exclamation-circle-fill','color' => 'accent'],
    ['label' => 'مجموعات اختيارية',  'value' => $stats['optional'],        'icon' => 'bi-check2-square',     'color' => 'success'],
    ['label' => 'إجمالي الخيارات',   'value' => $stats['total_options'],   'icon' => 'bi-tags-fill',          'color' => 'muted'],
]" />

<x-admin.data-panel title="مجموعات الإضافات" :count="$groups->total()" icon="bi-sliders2">
    <x-slot:actions>
        <a href="{{ route('admin.modifiers.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> مجموعة جديدة
        </a>
    </x-slot:actions>

    @if($groups->count() > 0)
        <div class="mg-grid">
            @foreach($groups as $g)
                @php
                    $count = $g->allModifiers->count();
                    // Selection-range label: "اختر 1" / "اختر من 1 إلى 3" / "اختر حتى 5"
                    $min = (int) $g->min_select;
                    $max = (int) $g->max_select;
                    if ($min === $max) {
                        $selectLabel = 'اختر ' . $min;
                    } elseif ($min > 0) {
                        $selectLabel = "اختر من {$min} إلى {$max}";
                    } else {
                        $selectLabel = 'اختر حتى ' . $max;
                    }
                @endphp

                <div class="mg-card {{ $g->required ? 'is-required' : '' }}">
                    <div class="mg-card-head">
                        <div class="mg-card-head-start">
                            <span class="mg-card-icon"><i class="bi bi-sliders2"></i></span>
                            <div>
                                <h4 class="mg-card-name">{{ $g->name }}</h4>
                                <div class="mg-card-meta">
                                    <span class="mg-select-chip">
                                        <i class="bi bi-check2-square"></i>
                                        {{ $selectLabel }}
                                    </span>
                                    @if($g->required)
                                        <span class="mg-required-badge">
                                            <i class="bi bi-asterisk"></i>
                                            مطلوب
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="mg-card-actions">
                            <a href="{{ route('admin.modifiers.edit', $g) }}" class="mg-action-btn" title="تعديل">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('admin.modifiers.destroy', $g) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف هذه المجموعة وكل خياراتها؟')">
                                @csrf @method('DELETE')
                                <button type="submit" class="mg-action-btn mg-action-btn-danger" title="حذف">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="mg-card-body">
                        @if($count > 0)
                            <div class="mg-options">
                                @foreach($g->allModifiers as $m)
                                    @php
                                        $delta = (float) $m->price_delta;
                                        if ($delta > 0)       { $deltaClass = 'is-plus';  $deltaText = '+' . number_format($delta, 2); }
                                        elseif ($delta < 0)   { $deltaClass = 'is-minus'; $deltaText = number_format($delta, 2); }
                                        else                   { $deltaClass = '';        $deltaText = null; }
                                    @endphp
                                    <span class="mg-option">
                                        <span class="mg-option-name">{{ $m->name }}</span>
                                        @if($deltaText)
                                            <span class="mg-option-price {{ $deltaClass }}">{{ $deltaText }}</span>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <div class="mg-empty-options">
                                <i class="bi bi-inbox"></i>
                                لا خيارات مُضافة
                            </div>
                        @endif
                    </div>

                    <div class="mg-card-foot">
                        <span class="mg-count">
                            <i class="bi bi-tags-fill"></i>
                            {{ $count }} {{ $count === 1 ? 'خيار' : 'خيارات' }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <x-admin.empty-state
            icon="bi-sliders2"
            title="ما في مجموعات إضافات"
            message="أضف مجموعات إضافات لتعطي زبائنك خيارات على أصنافك (حجم، نوع الخبز، تحميص، …).">
            <x-slot:cta>
                <a href="{{ route('admin.modifiers.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> مجموعة جديدة
                </a>
            </x-slot:cta>
        </x-admin.empty-state>
    @endif

    <x-slot:footer>{{ $groups->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
