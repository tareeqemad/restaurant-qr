@extends('layouts.admin')
@section('title', 'الطاولات')

@push('styles')
    <link rel="stylesheet"
          href="{{ asset('assets/dashtic/css/tables-board.css') }}?v={{ filemtime(public_path('assets/dashtic/css/tables-board.css')) }}">
@endpush

@section('content')
@php($canQuickEdit = auth()->user()?->hasAnyRole(['admin', 'manager']))
<section class="tables-page-shell"
         x-data="{ quickEdit: null }"
         @table-quick-edit.window="quickEdit = $event.detail"
         @keydown.escape.window="quickEdit = null">
    <div class="tables-quick-head">
        <div>
            <h1><i class="bi bi-grid-3x3-gap-fill"></i> الطاولات</h1>
            <p><b>+ طلب</b> للإضافة، و<b>متابعة</b> لمشاهدة حالة الطلب.@if($canQuickEdit) زر القلم للتعديل السريع.@endif</p>
        </div>
        @can('create', \App\Models\Table::class)
            <a href="{{ route('admin.tables.create') }}" class="btn btn-primary tables-page-action">
                <i class="bi bi-plus-lg"></i> طاولة جديدة
            </a>
        @endcan
    </div>

    <livewire:admin.tables-board />

    {{-- One shared modal for the whole board. It submits through the normal
         update route, so the same permission checks, validation, historical
         number snapshots and status broadcasts remain in force. --}}
    @if($canQuickEdit)
    <div class="table-quick-modal" x-cloak x-show="quickEdit" x-transition.opacity
         role="dialog" aria-modal="true" aria-label="تعديل سريع للطاولة"
         @click.self="quickEdit = null">
        <template x-if="quickEdit">
        <div class="table-quick-modal__panel" x-transition>
            <div class="table-quick-modal__head">
                <div>
                    <small>تعديل سريع</small>
                    <h2>طاولة <span x-text="quickEdit ? quickEdit.number : ''"></span></h2>
                </div>
                <button type="button" @click="quickEdit = null" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>
            </div>

            <form :action="quickEdit ? quickEdit.updateUrl : '#'" method="POST">
                @csrf
                @method('PUT')
                <div class="table-quick-modal__body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">رقم الطاولة</label>
                            <input name="number" x-model="quickEdit.number" class="form-control" maxlength="16" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">عدد الكراسي</label>
                            <input type="number" name="capacity" x-model="quickEdit.capacity" class="form-control" min="1" max="50" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">اسم مختصر <span class="text-muted fw-normal">(اختياري)</span></label>
                            <input name="name" x-model="quickEdit.name" class="form-control" maxlength="255" placeholder="مثلاً: قرب الشباك">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">القسم</label>
                            <select name="zone_lookup_id" x-model="quickEdit.zoneId" class="form-select">
                                <option value="">بدون قسم</option>
                                @foreach($zones as $zone)
                                    <option value="{{ $zone->id }}">{{ $zone->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" x-model="quickEdit.status" class="form-select" required>
                                <option value="available">متاحة</option>
                                <option value="occupied">مشغولة</option>
                                <option value="reserved">محجوزة</option>
                                <option value="out_of_service">خارج الخدمة</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="table-active-switch">
                                <input type="hidden" name="active" value="0">
                                <input type="checkbox" name="active" value="1" x-model="quickEdit.active">
                                <span>الطاولة ظاهرة وقابلة للاستخدام</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="table-quick-modal__actions">
                    <button type="button" class="btn btn-light" @click="quickEdit = null">إلغاء</button>
                    <button class="btn btn-primary"><i class="bi bi-check2"></i> حفظ التعديل</button>
                </div>
            </form>
        </div>
        </template>
    </div>
    @endif
</section>

@push('scripts')
@livewireScripts
@endpush
@endsection
