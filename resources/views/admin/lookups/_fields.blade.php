{{--
  Shared field block for both add + edit modals. The edit modal sets
  isEdit=true and overwrites these via JS from data-* attributes on the
  triggering button.
--}}
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">التسمية <span class="text-danger">*</span></label>
        <input type="text" name="label" class="form-control" required maxlength="120"
               placeholder="مثلاً: مشتريات صيانة">
    </div>
    <div class="col-md-4">
        <label class="form-label">الترتيب</label>
        <input type="number" name="display_order" class="form-control text-start"
               value="0" min="0" max="9999" dir="ltr">
    </div>

    <div class="col-md-6">
        <label class="form-label">
            الكود <small class="text-muted">(اختياري — حروف وأرقام إنجليزية فقط)</small>
        </label>
        <input type="text" name="code" class="form-control text-start"
               maxlength="60" pattern="[a-z0-9_]+" dir="ltr"
               placeholder="مثلاً: maintenance_supplies">
    </div>

    <div class="col-md-3">
        <label class="form-label">اللون</label>
        <input type="color" name="color" class="form-control form-control-color w-100"
               value="#94a3b8">
    </div>

    <div class="col-md-3">
        <label class="form-label">
            الأيقونة <small class="text-muted">
                (<a href="https://icons.getbootstrap.com/" target="_blank" class="text-muted">قائمة</a>)
            </small>
        </label>
        <input type="text" name="icon" class="form-control text-start"
               maxlength="60" placeholder="bi-cash-coin" dir="ltr">
    </div>

    <div class="col-12">
        <div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" class="form-check-input"
                   id="lkActiveSwitch_{{ $isEdit ? 'edit' : 'add' }}" checked>
            <label class="form-check-label" for="lkActiveSwitch_{{ $isEdit ? 'edit' : 'add' }}">
                مفعّل (يظهر في القوائم المنسدلة)
            </label>
        </div>
    </div>
</div>
