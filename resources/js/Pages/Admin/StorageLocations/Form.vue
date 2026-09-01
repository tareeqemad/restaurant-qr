<script setup>
/**
 * موقع تخزين — create/edit. One component for both, because the retired
 * Blade was one shared `_form` partial included by two wrappers.
 *
 * useForm (not a raw router.post) on purpose: `code` is `alpha_dash` and
 * `store()` can also bounce with a "no branch could be determined" flash.
 * Both paths come back as a redirect to this same component, and useForm
 * keeps the typed values instead of handing the user an empty form.
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    location: { type: Object, default: null },
    urls: { type: Object, required: true },
});

const isEdit = computed(() => Boolean(props.location));
const title = computed(() => (isEdit.value ? `تعديل: ${props.location.name}` : 'موقع جديد'));

const form = useForm({
    code: props.location?.code ?? '',
    name: props.location?.name ?? '',
    display_order: props.location?.displayOrder ?? 0,
    icon: props.location?.icon ?? '',
    color: props.location?.color || '#166534',
    is_default: props.location?.isDefault ?? false,
    active: props.location?.active ?? true,
    description: props.location?.description ?? '',
});

const submit = () => {
    if (form.processing) return;
    if (isEdit.value) form.put(props.urls.submit, { preserveScroll: true });
    else form.post(props.urls.submit, { preserveScroll: true });
};
</script>

<template>
    <Head :title="title" />

    <PageHeader :title="title" :icon="isEdit ? 'bi-pencil-square' : 'bi-plus-circle-fill'"
                :crumbs="[{ label: 'مواقع التخزين', url: urls.index }]" />

    <DataPanel :title="isEdit ? 'النموذج' : 'موقع جديد'" :icon="isEdit ? 'bi-pencil-square' : 'bi-plus-circle-fill'">
        <template #actions>
            <a :href="urls.index" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
        </template>

        <form class="sl-form" @submit.prevent="submit">
            <div class="form-section">
                <div class="form-section-head">
                    <i class="bi bi-geo-alt-fill"></i>
                    <span>بيانات الموقع</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="sl-code">الكود <span class="req">*</span></label>
                        <input id="sl-code" v-model="form.code" class="form-control"
                               :class="{ 'is-invalid': form.errors.code }"
                               placeholder="main_kitchen" maxlength="40" required>
                        <div v-if="form.errors.code" class="invalid-feedback d-block">{{ form.errors.code }}</div>
                        <small v-else class="text-muted">أحرف لاتينية صغيرة، بدون مسافات</small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="sl-name">الاسم <span class="req">*</span></label>
                        <input id="sl-name" v-model="form.name" class="form-control form-control-lg"
                               :class="{ 'is-invalid': form.errors.name }" maxlength="255" required>
                        <div v-if="form.errors.name" class="invalid-feedback d-block">{{ form.errors.name }}</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="sl-order">الترتيب</label>
                        <input id="sl-order" v-model="form.display_order" type="number" min="0" step="1"
                               class="form-control" :class="{ 'is-invalid': form.errors.display_order }">
                        <div v-if="form.errors.display_order" class="invalid-feedback d-block">{{ form.errors.display_order }}</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="sl-icon">أيقونة</label>
                        <div class="sl-icon-row">
                            <input id="sl-icon" v-model="form.icon" class="form-control"
                                   :class="{ 'is-invalid': form.errors.icon }"
                                   placeholder="bi-fire" maxlength="40">
                            <span class="sl-icon-preview"><i class="bi" :class="form.icon || 'bi-geo-alt'"></i></span>
                        </div>
                        <div v-if="form.errors.icon" class="invalid-feedback d-block">{{ form.errors.icon }}</div>
                        <small v-else class="text-muted">اسم bootstrap-icons</small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="sl-color">اللون</label>
                        <input id="sl-color" v-model="form.color" type="color"
                               class="form-control form-control-color sl-color">
                        <div v-if="form.errors.color" class="invalid-feedback d-block">{{ form.errors.color }}</div>
                    </div>

                    <div class="col-md-4">
                        <span class="d-block form-label">الإعدادات</span>
                        <label class="sl-check">
                            <input v-model="form.is_default" type="checkbox">
                            <span>افتراضي</span>
                        </label>
                        <label class="sl-check">
                            <input v-model="form.active" type="checkbox">
                            <span>فعّال</span>
                        </label>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="sl-desc">الوصف</label>
                        <textarea id="sl-desc" v-model="form.description" class="form-control" rows="2"
                                  :class="{ 'is-invalid': form.errors.description }" maxlength="1000"></textarea>
                        <div v-if="form.errors.description" class="invalid-feedback d-block">{{ form.errors.description }}</div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a :href="urls.index" class="btn btn-light">إلغاء</a>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                    <i class="bi" :class="form.processing ? 'bi-arrow-repeat' : 'bi-save'"></i>
                    {{ form.processing ? 'جارٍ الحفظ…' : 'حفظ' }}
                </button>
            </div>
        </form>
    </DataPanel>
</template>

<style scoped>
/* `form-section` / `form-section-head` / `req` carried no CSS anywhere in
   the project — they were bare hooks in the retired Blade. Give them the
   look they were always meant to have, scoped to this page. */
.form-section {
    border: 1px solid #eef0f3;
    border-radius: 14px;
    padding: 1rem;
}
.form-section-head {
    display: flex;
    align-items: center;
    gap: .45rem;
    font-weight: 800;
    color: #334155;
    margin-bottom: .9rem;
    padding-bottom: .6rem;
    border-bottom: 1px solid #eef0f3;
}
.form-section-head i { color: var(--primary); }
.req { color: #dc3545; }

.sl-form :deep(.form-control),
.sl-form :deep(.form-select) { min-height: 44px; }

.sl-icon-row { display: flex; gap: .5rem; align-items: stretch; }
.sl-icon-row input { flex: 1; }
.sl-icon-preview {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    min-width: 44px;
    border-radius: .5rem;
    background: rgba(15, 71, 49, .06);
    color: var(--primary);
    font-size: 1.1rem;
}
.sl-color { width: 100%; min-height: 44px; }

.sl-check {
    display: flex;
    align-items: center;
    gap: .5rem;
    min-height: 44px;
    margin: 0;
    cursor: pointer;
}
.sl-check input { width: 20px; height: 20px; }

.form-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; }
.form-actions .btn { min-height: 44px; }
</style>
