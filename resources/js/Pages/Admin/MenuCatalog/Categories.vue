<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import CatalogWorkspacePage from '../../../Components/MenuAdmin/CatalogWorkspacePage.vue';
import MenuSheet from '../../../Components/MenuAdmin/MenuSheet.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useToast } from '../../../Composables/useToast';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    navigation: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    editor: { type: Object, default: null },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const rows = ref([...props.categories]);
const search = ref('');
const status = ref('all');
const station = ref('all');
const sort = ref('manual');
const editing = ref(null);
const saving = ref(false);
const actionId = ref(null);
const imagePreview = ref('');
let localImageUrl = null;

const { ask } = useConfirm();
const toast = useToast();
const apiOptions = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };

const form = useForm({
    name: '',
    description: '',
    default_station_id: '',
    active: true,
    color: '#1f6b50',
    icon: '',
    image_url: '',
    image: null,
    remove_image: false,
});

const stats = computed(() => ({
    total: rows.value.length,
    active: rows.value.filter((row) => row.active).length,
    withItems: rows.value.filter((row) => row.itemsCount > 0).length,
    empty: rows.value.filter((row) => row.itemsCount === 0).length,
}));

const orderedRows = computed(() => [...rows.value].sort((a, b) => a.displayOrder - b.displayOrder || a.id - b.id));
const filtersActive = computed(() => Boolean(search.value.trim()) || status.value !== 'all' || station.value !== 'all');
const reorderEnabled = computed(() => sort.value === 'manual' && !filtersActive.value && props.can.update);

const visibleRows = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase('ar');
    const filtered = orderedRows.value.filter((row) => {
        const matchesSearch = !needle || [row.name, row.description, row.station]
            .filter(Boolean)
            .some((value) => String(value).toLocaleLowerCase('ar').includes(needle));
        const matchesStatus = status.value === 'all'
            || (status.value === 'active' ? row.active : !row.active);
        const matchesStation = station.value === 'all'
            || (station.value === 'none'
                ? !row.stationId
                : String(row.stationId) === String(station.value));
        return matchesSearch && matchesStatus && matchesStation;
    });

    if (sort.value === 'name') return filtered.sort((a, b) => a.name.localeCompare(b.name, 'ar'));
    if (sort.value === 'items') return filtered.sort((a, b) => b.itemsCount - a.itemsCount || a.name.localeCompare(b.name, 'ar'));
    return filtered;
});

const editorTitle = computed(() => editing.value?.mode === 'edit' ? 'تعديل القسم' : 'قسم جديد');
const editorSubtitle = computed(() => editing.value?.mode === 'edit'
    ? 'أي تعديل تحفظه ينعكس مباشرة في المنيو.'
    : 'أدخل الأساسيات الآن، ويمكنك تحسين الشكل لاحقاً.');
const previewStyle = computed(() => ({ '--cat': form.color || '#1f6b50' }));

function releaseLocalImage() {
    if (!localImageUrl) return;
    URL.revokeObjectURL(localImageUrl);
    localImageUrl = null;
}

function seed(category = null) {
    releaseLocalImage();
    form.clearErrors();
    form.name = category?.name ?? '';
    form.description = category?.description ?? '';
    form.default_station_id = category?.stationId ?? '';
    form.active = category ? Boolean(category.active) : true;
    form.color = category?.color ?? '#1f6b50';
    form.icon = category?.icon ?? '';
    form.image_url = category?.imageSource ?? '';
    form.image = null;
    form.remove_image = false;
    imagePreview.value = category?.imageUrl ?? '';
}

function openCreate() {
    editing.value = { mode: 'create' };
    seed();
}

function openEdit(category) {
    editing.value = { mode: 'edit', category };
    seed(category);
}

function closeEditor() {
    if (saving.value) return;
    releaseLocalImage();
    editing.value = null;
    form.clearErrors();
}

function selectImage(event) {
    const file = event.target.files?.[0] ?? null;
    releaseLocalImage();
    form.image = file;
    form.image_url = '';
    form.remove_image = false;
    localImageUrl = file ? URL.createObjectURL(file) : null;
    imagePreview.value = localImageUrl || editing.value?.category?.imageUrl || '';
}

function removeImage() {
    releaseLocalImage();
    form.image = null;
    form.image_url = '';
    form.remove_image = true;
    imagePreview.value = '';
}

function clearFilters() {
    search.value = '';
    status.value = 'all';
    station.value = 'all';
    sort.value = 'manual';
}

function upsert(category) {
    const index = rows.value.findIndex((row) => row.id === category.id);
    if (index === -1) rows.value.push(category);
    else rows.value.splice(index, 1, category);
    rows.value = [...rows.value].sort((a, b) => a.displayOrder - b.displayOrder || a.id - b.id);
}

function applyApiError(error) {
    const errors = error.response?.data?.errors;
    if (errors) {
        Object.entries(errors).forEach(([field, messages]) => {
            form.setError(field, Array.isArray(messages) ? messages[0] : messages);
        });
        return;
    }
    toast.error(error.response?.data?.message || 'تعذر إتمام العملية. حاول مرة أخرى.');
}

function buildPayload(isEdit) {
    const payload = new FormData();
    payload.append('name', form.name ?? '');
    payload.append('description', form.description ?? '');
    payload.append('default_station_id', form.default_station_id ?? '');
    payload.append('active', form.active ? '1' : '0');
    payload.append('color', form.color || '#1f6b50');
    payload.append('icon', form.icon ?? '');
    payload.append('image_url', form.image_url ?? '');
    payload.append('remove_image', form.remove_image ? '1' : '0');
    if (form.image) payload.append('image', form.image);
    if (isEdit) payload.append('_method', 'PUT');
    return payload;
}

async function submit() {
    form.clearErrors();
    saving.value = true;
    const isEdit = editing.value?.mode === 'edit';
    const url = isEdit ? editing.value.category.urls.update : props.urls.store;

    try {
        const response = await axios.post(url, buildPayload(isEdit), apiOptions);
        upsert(response.data.category);
        toast.success(response.data.message);
        closeEditor();
    } catch (error) {
        applyApiError(error);
    } finally {
        saving.value = false;
    }
}

async function toggle(category) {
    if (!category.can.update || actionId.value) return;
    actionId.value = category.id;
    try {
        const response = await axios.patch(category.urls.toggle, {}, apiOptions);
        upsert(response.data.category);
        toast.success(response.data.message);
    } catch (error) {
        applyApiError(error);
    } finally {
        actionId.value = null;
    }
}

function canMove(category, direction) {
    if (!reorderEnabled.value || actionId.value) return false;
    const index = orderedRows.value.findIndex((row) => row.id === category.id);
    return direction === 'up' ? index > 0 : index !== -1 && index < orderedRows.value.length - 1;
}

async function move(category, direction) {
    if (!canMove(category, direction)) return;
    actionId.value = category.id;
    try {
        const response = await axios.post(category.urls.move, { direction }, apiOptions);
        rows.value = response.data.categories;
        toast.success(response.data.message);
    } catch (error) {
        applyApiError(error);
    } finally {
        actionId.value = null;
    }
}

async function destroy(category) {
    if (!category.can.delete || actionId.value) return;
    const approved = await ask({
        title: `حذف قسم «${category.name}»؟`,
        message: 'هذا القسم فارغ، وسيُحذف نهائياً. الأقسام التي تحتوي أصنافاً يمكن إخفاؤها فقط.',
        confirmLabel: 'حذف القسم',
        danger: true,
    });
    if (!approved) return;

    actionId.value = category.id;
    try {
        const response = await axios.delete(category.urls.destroy, apiOptions);
        rows.value = rows.value.filter((row) => row.id !== response.data.id);
        toast.success(response.data.message);
    } catch (error) {
        applyApiError(error);
    } finally {
        actionId.value = null;
    }
}

if (props.editor?.mode === 'create') openCreate();
if (props.editor?.mode === 'edit' && props.editor.category) openEdit(props.editor.category);
onBeforeUnmount(releaseLocalImage);
</script>

<template>
    <Head title="أقسام المنيو" />

    <CatalogWorkspacePage
        :navigation="navigation"
        title="أقسام المنيو"
        icon="bi-grid-fill"
        subtitle="نظّم ما يراه الزبون، وحدّد أين يذهب كل نوع من الطلبات"
        panel-title="ترتيب الأقسام"
        panel-subtitle="كل التعديلات تتم هنا مباشرة دون مغادرة الصفحة"
        panel-icon="bi-layout-text-sidebar-reverse"
        :count="visibleRows.length"
        :stats="[
            { label: 'كل الأقسام', value: stats.total, icon: 'bi-grid-fill' },
            { label: 'ظاهرة في المنيو', value: stats.active, icon: 'bi-eye-fill' },
            { label: 'بها أصناف', value: stats.withItems, icon: 'bi-egg-fried' },
            { label: 'فارغة', value: stats.empty, icon: 'bi-inbox', tone: 'muted' },
        ]"
    >
        <template #actions>
            <button v-if="can.create" type="button" class="btn btn-primary" @click="openCreate">
                <i class="bi bi-plus-lg"></i>
                إضافة قسم
            </button>
        </template>

        <template #filters>
            <div class="cat-tools">
                <label class="cat-search">
                    <i class="bi bi-search"></i>
                    <input v-model="search" type="search" placeholder="ابحث باسم القسم أو المحطة…">
                    <button v-if="search" type="button" aria-label="مسح البحث" @click="search = ''">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </label>

                <div class="cat-status" aria-label="تصفية حالة الأقسام">
                    <button type="button" :class="{ active: status === 'all' }" @click="status = 'all'">الكل</button>
                    <button type="button" :class="{ active: status === 'active' }" @click="status = 'active'">ظاهرة</button>
                    <button type="button" :class="{ active: status === 'inactive' }" @click="status = 'inactive'">مخفية</button>
                </div>

                <label class="cat-select">
                    <span>المحطة</span>
                    <select v-model="station">
                        <option value="all">كل المحطات</option>
                        <option value="none">بلا محطة</option>
                        <option v-for="item in stations" :key="item.id" :value="String(item.id)">
                            {{ item.name }}{{ item.active ? '' : ' · متوقفة' }}
                        </option>
                    </select>
                </label>

                <label class="cat-select">
                    <span>العرض</span>
                    <select v-model="sort">
                        <option value="manual">ترتيب المنيو</option>
                        <option value="name">حسب الاسم</option>
                        <option value="items">الأكثر أصنافاً</option>
                    </select>
                </label>
            </div>
        </template>

        <div class="cat-guide" :class="{ muted: !reorderEnabled }">
            <i class="bi" :class="reorderEnabled ? 'bi-arrow-down-up' : 'bi-info-circle'"></i>
            <span v-if="reorderEnabled">استخدم الأسهم لتحديد ترتيب ظهور الأقسام للزبون.</span>
            <span v-else>امسح البحث والفلاتر واختر «ترتيب المنيو» لتغيير التسلسل.</span>
        </div>

        <div v-if="visibleRows.length" class="cat-list">
            <article
                v-for="category in visibleRows"
                :key="category.id"
                class="cat-row"
                :class="{ inactive: !category.active, busy: actionId === category.id }"
            >
                <div class="cat-order">
                    <strong>{{ orderedRows.findIndex((row) => row.id === category.id) + 1 }}</strong>
                    <div>
                        <button type="button" :disabled="!canMove(category, 'up')" :aria-label="`رفع ${category.name}`" @click="move(category, 'up')">
                            <i class="bi bi-chevron-up"></i>
                        </button>
                        <button type="button" :disabled="!canMove(category, 'down')" :aria-label="`خفض ${category.name}`" @click="move(category, 'down')">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                </div>

                <div class="cat-image" :style="{ '--cat': category.color }">
                    <img v-if="category.imageUrl" :src="category.imageUrl" :alt="category.name">
                    <i v-else class="bi" :class="category.icon || 'bi-grid-fill'"></i>
                </div>

                <div class="cat-identity">
                    <div>
                        <h3>{{ category.name }}</h3>
                    </div>
                    <p v-if="category.description">{{ category.description }}</p>
                    <div class="cat-meta">
                        <span><i class="bi bi-egg-fried"></i>{{ category.itemsCount }} صنف</span>
                        <span><i class="bi bi-fire"></i>{{ category.station || 'بلا محطة افتراضية' }}</span>
                    </div>
                </div>

                <button
                    v-if="category.can.update"
                    type="button"
                    class="cat-visibility"
                    :class="{ on: category.active }"
                    :disabled="actionId === category.id"
                    :aria-pressed="category.active"
                    @click="toggle(category)"
                >
                    <i class="bi" :class="category.active ? 'bi-eye-fill' : 'bi-eye-slash'"></i>
                    <span><strong>{{ category.active ? 'ظاهر' : 'مخفي' }}</strong><small>في منيو الزبون</small></span>
                </button>
                <span v-else class="cat-visibility readonly" :class="{ on: category.active }">
                    <i class="bi" :class="category.active ? 'bi-eye-fill' : 'bi-eye-slash'"></i>
                    {{ category.active ? 'ظاهر' : 'مخفي' }}
                </span>

                <div class="cat-actions">
                    <button v-if="category.can.update" type="button" class="edit" @click="openEdit(category)">
                        <i class="bi bi-pencil"></i><span>تعديل</span>
                    </button>
                    <button
                        v-if="category.can.delete"
                        type="button"
                        class="delete"
                        :disabled="actionId === category.id"
                        :aria-label="`حذف ${category.name}`"
                        @click="destroy(category)"
                    >
                        <i class="bi bi-trash3"></i>
                    </button>
                    <span v-else-if="category.itemsCount" class="cat-protected" title="لا يحذف القسم الذي يحتوي أصنافاً">
                        <i class="bi bi-shield-lock"></i> محفوظ
                    </span>
                </div>
            </article>
        </div>

        <EmptyState
            v-else
            icon="bi-search"
            :title="rows.length ? 'لا توجد نتيجة مطابقة' : 'ابدأ بأول قسم'"
            :message="rows.length ? 'غيّر كلمة البحث أو امسح الفلاتر.' : 'أنشئ قسماً مثل المشروبات أو الأطباق الرئيسية، ثم أضف أصنافه.'"
        >
            <button v-if="filtersActive" type="button" class="btn btn-light" @click="clearFilters">مسح الفلاتر</button>
            <button v-else-if="can.create" type="button" class="btn btn-primary" @click="openCreate">إضافة أول قسم</button>
        </EmptyState>
    </CatalogWorkspacePage>

    <MenuSheet
        :open="Boolean(editing)"
        :busy="saving"
        :wide="true"
        :title="editorTitle"
        :subtitle="editorSubtitle"
        icon="bi-grid-fill"
        @close="closeEditor"
    >
        <form id="category-form" class="cat-form" @submit.prevent="submit">
            <section class="cat-preview" :style="previewStyle">
                <div class="cat-preview-image">
                    <img v-if="imagePreview" :src="imagePreview" alt="">
                    <i v-else class="bi" :class="form.icon || 'bi-grid-fill'"></i>
                </div>
                <div>
                    <small>معاينة سريعة</small>
                    <strong>{{ form.name || 'اسم القسم' }}</strong>
                    <span>{{ form.default_station_id ? stations.find((item) => String(item.id) === String(form.default_station_id))?.name : 'بلا محطة افتراضية' }}</span>
                </div>
                <em :class="{ off: !form.active }">{{ form.active ? 'ظاهر' : 'مخفي' }}</em>
            </section>

            <section class="cat-form-section">
                <header><span>1</span><div><h3>المعلومات الأساسية</h3><p>هذه هي البيانات التي يحتاجها العمل اليومي.</p></div></header>
                <div class="cat-form-grid">
                    <label>
                        <span>اسم القسم *</span>
                        <input v-model.trim="form.name" class="form-control" maxlength="255" required autofocus placeholder="مثال: المشروبات">
                        <small class="error">{{ form.errors.name }}</small>
                    </label>
                    <label>
                        <span>محطة التحضير الافتراضية</span>
                        <select v-model="form.default_station_id" class="form-select">
                            <option value="">بلا محطة افتراضية</option>
                            <option v-for="item in stations" :key="item.id" :value="item.id">
                                {{ item.name }}{{ item.active ? '' : ' · متوقفة' }}
                            </option>
                        </select>
                        <small class="error">{{ form.errors.default_station_id }}</small>
                    </label>
                    <label class="wide">
                        <span>وصف قصير</span>
                        <textarea v-model.trim="form.description" rows="3" class="form-control" placeholder="وصف يساعد الزبون على فهم محتوى هذا القسم"></textarea>
                        <small class="error">{{ form.errors.description }}</small>
                    </label>
                </div>
            </section>

            <section class="cat-form-section">
                <header><span>2</span><div><h3>الصورة والظهور</h3><p>صورة موحدة وحالة واضحة دون خطوات إضافية.</p></div></header>
                <div class="cat-media-editor">
                    <div class="cat-media-thumb" :style="previewStyle">
                        <img v-if="imagePreview" :src="imagePreview" alt="">
                        <i v-else class="bi" :class="form.icon || 'bi-image'"></i>
                    </div>
                    <div class="cat-media-copy">
                        <strong>{{ imagePreview ? 'صورة القسم الحالية' : 'لا توجد صورة' }}</strong>
                        <small>JPG أو PNG، بحد أقصى 5MB. ستظهر دائماً بمقاس موحد.</small>
                        <div>
                            <label class="cat-file-button">
                                <input type="file" accept="image/png,image/jpeg,image/webp" @change="selectImage">
                                <i class="bi bi-upload"></i>{{ imagePreview ? 'تغيير الصورة' : 'رفع صورة' }}
                            </label>
                            <button v-if="imagePreview || editing?.category?.hasImage" type="button" class="cat-remove-image" @click="removeImage">
                                <i class="bi bi-trash3"></i> إزالة
                            </button>
                        </div>
                        <small class="error">{{ form.errors.image || form.errors.image_url }}</small>
                    </div>
                    <label class="cat-active-switch">
                        <input v-model="form.active" type="checkbox">
                        <span><i class="bi" :class="form.active ? 'bi-eye-fill' : 'bi-eye-slash'"></i></span>
                        <div><strong>{{ form.active ? 'ظاهر في المنيو' : 'مخفي من المنيو' }}</strong><small>الإخفاء لا يحذف الأصناف أو تاريخها.</small></div>
                    </label>
                </div>
            </section>

            <details class="cat-optional">
                <summary><i class="bi bi-sliders"></i><span><strong>تفاصيل اختيارية</strong><small>اللون والأيقونة والرابط الخارجي</small></span><i class="bi bi-chevron-down"></i></summary>
                <div class="cat-form-grid">
                    <label>
                        <span>رابط صورة خارجي</span>
                        <input v-model.trim="form.image_url" class="form-control" type="url" placeholder="https://…" @input="form.remove_image = false">
                        <small class="error">{{ form.errors.image_url }}</small>
                    </label>
                    <label>
                        <span>لون القسم</span>
                        <div class="cat-color-input"><input v-model="form.color" type="color"><bdi>{{ form.color }}</bdi></div>
                        <small class="error">{{ form.errors.color }}</small>
                    </label>
                    <label>
                        <span>أيقونة Bootstrap</span>
                        <input v-model.trim="form.icon" class="form-control" placeholder="bi-cup-straw">
                        <small class="error">{{ form.errors.icon }}</small>
                    </label>
                </div>
            </details>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="saving" @click="closeEditor">إلغاء</button>
            <button type="submit" form="category-form" class="btn btn-primary" :disabled="saving || !form.name">
                <i class="bi" :class="saving ? 'bi-arrow-repeat spin' : 'bi-check2-circle'"></i>
                {{ saving ? 'جارٍ الحفظ…' : editing?.mode === 'edit' ? 'حفظ التعديلات' : 'إضافة القسم' }}
            </button>
        </template>
    </MenuSheet>
</template>

<style scoped>
.cat-tools { display: grid; grid-template-columns: minmax(230px, 1fr) auto minmax(145px, .42fr) minmax(145px, .42fr); gap: .55rem; align-items: center; }
.cat-search { display: grid; grid-template-columns: 26px minmax(0, 1fr) 34px; align-items: center; min-height: 46px; padding-inline: .65rem .3rem; border: 1px solid #d9e5de; border-radius: 12px; background: #fff; color: #708079; }
.cat-search:focus-within { border-color: #6aae88; box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 31, 107, 80), .09); }
.cat-search input { min-width: 0; height: 42px; border: 0; outline: 0; background: transparent; color: #18362a; font-size: .76rem; }
.cat-search button { width: 32px; height: 32px; border: 0; border-radius: 8px; background: #f0f4f2; color: #63756c; }
.cat-status { display: flex; gap: .22rem; min-height: 46px; padding: .25rem; border: 1px solid #dce6e0; border-radius: 12px; background: #f1f5f3; }
.cat-status button { min-width: 62px; min-height: 36px; padding: .35rem .55rem; border: 0; border-radius: 8px; background: transparent; color: #607269; font-size: .68rem; font-weight: 850; }
.cat-status button.active { background: #fff; color: rgb(var(--primary-rgb, 31, 107, 80)); box-shadow: 0 2px 8px rgba(22, 63, 47, .08); }
.cat-select { display: grid; grid-template-columns: auto 1fr; align-items: center; gap: .4rem; min-height: 46px; padding-inline: .6rem; border: 1px solid #dce6e0; border-radius: 12px; background: #fff; }
.cat-select span { color: #809087; font-size: .61rem; }
.cat-select select { min-width: 0; height: 42px; border: 0; outline: 0; background: transparent; color: #284638; font-size: .69rem; font-weight: 800; }
.cat-guide { display: flex; align-items: center; gap: .4rem; min-height: 38px; margin-bottom: .55rem; padding: .45rem .65rem; border-radius: 10px; color: #176f47; background: #f0f8f3; font-size: .65rem; }
.cat-guide.muted { color: #6f7f77; background: #f4f6f5; }
.cat-list { display: grid; gap: .48rem; }
.cat-row { display: grid; grid-template-columns: 58px 70px minmax(190px, 1fr) 126px auto; align-items: center; gap: .72rem; min-height: 98px; padding: .58rem .68rem; border: 1px solid #dce7e1; border-radius: 14px; background: #fff; transition: border-color .16s ease, box-shadow .16s ease, opacity .16s ease; }
.cat-row:hover { border-color: #bad5c6; box-shadow: 0 7px 20px rgba(18, 63, 49, .055); }
.cat-row.inactive { background: #f8faf9; }
.cat-row.busy { opacity: .58; }
.cat-order { display: flex; align-items: center; gap: .28rem; }
.cat-order > strong { min-width: 22px; color: #7b8d84; font-size: .68rem; text-align: center; }
.cat-order > div { display: grid; gap: .15rem; }
.cat-order button { width: 30px; height: 30px; display: grid; place-items: center; border: 1px solid #dbe5df; border-radius: 8px; background: #fff; color: #557067; }
.cat-order button:disabled { opacity: .28; cursor: not-allowed; }
.cat-image { width: 70px; height: 70px; display: grid; place-items: center; overflow: hidden; border-radius: 13px; color: var(--cat); background: color-mix(in srgb, var(--cat) 13%, white); font-size: 1.25rem; }
.cat-image img { width: 100%; height: 100%; object-fit: cover; }
.cat-identity { min-width: 0; }
.cat-identity > div:first-child { display: flex; align-items: baseline; gap: .5rem; }
.cat-identity h3 { overflow: hidden; margin: 0; color: #153d2d; font-size: .86rem; font-weight: 950; text-overflow: ellipsis; white-space: nowrap; }
.cat-identity > div > small { color: #8c9992; font-size: .61rem; }
.cat-identity p { overflow: hidden; margin: .16rem 0 .35rem; color: #718078; font-size: .66rem; text-overflow: ellipsis; white-space: nowrap; }
.cat-meta { display: flex; flex-wrap: wrap; gap: .3rem .75rem; color: #64766c; font-size: .63rem; }
.cat-meta span { display: inline-flex; align-items: center; gap: .25rem; }
.cat-meta i { color: #8ba095; }
.cat-visibility { min-height: 48px; display: flex; align-items: center; gap: .5rem; padding: .45rem .55rem; border: 1px solid #dde5e1; border-radius: 11px; background: #f5f7f6; color: #68776f; text-align: start; }
.cat-visibility.on { border-color: #cce5d6; background: #eef9f2; color: #147344; }
.cat-visibility > i { font-size: .95rem; }
.cat-visibility > span { display: grid; }
.cat-visibility strong { font-size: .67rem; }
.cat-visibility small { color: #839189; font-size: .56rem; }
.cat-visibility.readonly { justify-content: center; font-size: .65rem; }
.cat-actions { display: flex; align-items: center; justify-content: flex-end; gap: .3rem; min-width: 116px; }
.cat-actions button { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; gap: .35rem; border: 1px solid #dce5df; border-radius: 10px; background: #fff; font-size: .66rem; font-weight: 850; }
.cat-actions .edit { padding-inline: .75rem; color: #275b46; }
.cat-actions .delete { width: 44px; color: #b63843; }
.cat-protected { display: inline-flex; align-items: center; gap: .25rem; color: #8a7654; font-size: .6rem; }
.cat-form { display: grid; gap: .8rem; }
.cat-preview { display: grid; grid-template-columns: 62px minmax(0, 1fr) auto; align-items: center; gap: .7rem; padding: .65rem; border: 1px solid color-mix(in srgb, var(--cat) 24%, #dce6e0); border-radius: 14px; background: color-mix(in srgb, var(--cat) 7%, white); }
.cat-preview-image { width: 62px; height: 62px; display: grid; place-items: center; overflow: hidden; border-radius: 12px; color: var(--cat); background: color-mix(in srgb, var(--cat) 14%, white); font-size: 1.2rem; }
.cat-preview-image img { width: 100%; height: 100%; object-fit: cover; }
.cat-preview > div:nth-child(2) { display: grid; min-width: 0; }
.cat-preview small { color: #73837a; font-size: .58rem; }
.cat-preview strong { overflow: hidden; color: #173d2e; font-size: .82rem; text-overflow: ellipsis; white-space: nowrap; }
.cat-preview span { color: #66776e; font-size: .62rem; }
.cat-preview em { padding: .25rem .52rem; border-radius: 99px; color: #147344; background: #e5f6ec; font-size: .61rem; font-style: normal; font-weight: 900; }
.cat-preview em.off { color: #6e7c74; background: #e9eeeb; }
.cat-form-section { overflow: hidden; border: 1px solid #dfe8e3; border-radius: 14px; }
.cat-form-section > header { display: flex; align-items: center; gap: .55rem; padding: .68rem .8rem; border-bottom: 1px solid #e9efeb; background: #fafcfb; }
.cat-form-section > header > span { width: 30px; height: 30px; display: grid; place-items: center; flex: 0 0 auto; border-radius: 9px; color: #fff; background: rgb(var(--primary-rgb, 31, 107, 80)); font-size: .67rem; font-weight: 950; }
.cat-form-section h3 { margin: 0; color: #173d2e; font-size: .76rem; font-weight: 950; }
.cat-form-section p { margin: .08rem 0 0; color: #7c8b83; font-size: .59rem; }
.cat-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; padding: .78rem; }
.cat-form-grid label { display: grid; align-content: start; gap: .25rem; }
.cat-form-grid label > span { color: #425e50; font-size: .66rem; font-weight: 850; }
.cat-form-grid .wide { grid-column: 1 / -1; }
.cat-form-grid .form-control, .cat-form-grid .form-select { min-height: 45px; border-color: #d9e4de; border-radius: 10px; font-size: .72rem; }
.cat-form-grid textarea.form-control { min-height: auto; resize: vertical; }
.error { min-height: .65rem; color: #b62e3d !important; font-size: .58rem !important; }
.cat-media-editor { display: grid; grid-template-columns: 94px minmax(0, 1fr) minmax(190px, .65fr); gap: .8rem; align-items: center; padding: .78rem; }
.cat-media-thumb { width: 94px; height: 76px; display: grid; place-items: center; overflow: hidden; border: 1px dashed #cbdad2; border-radius: 12px; color: var(--cat); background: color-mix(in srgb, var(--cat) 9%, white); font-size: 1.2rem; }
.cat-media-thumb img { width: 100%; height: 100%; object-fit: cover; }
.cat-media-copy { display: grid; gap: .2rem; }
.cat-media-copy > strong { color: #274b3a; font-size: .7rem; }
.cat-media-copy > small { color: #7b8982; font-size: .59rem; }
.cat-media-copy > div { display: flex; gap: .35rem; margin-top: .28rem; }
.cat-file-button, .cat-remove-image { min-height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: .35rem; padding: .42rem .65rem; border: 1px solid #d7e3dc; border-radius: 9px; background: #fff; color: #285d47; font-size: .64rem; font-weight: 850; cursor: pointer; }
.cat-file-button input { position: absolute; width: 1px; height: 1px; overflow: hidden; opacity: 0; }
.cat-remove-image { color: #af3440; }
.cat-active-switch { min-height: 66px; display: grid; grid-template-columns: 0 38px 1fr; align-items: center; gap: .55rem; padding: .55rem; border: 1px solid #dce6e0; border-radius: 12px; background: #f7faf8; cursor: pointer; }
.cat-active-switch input { opacity: 0; }
.cat-active-switch > span { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 10px; color: #157445; background: #e6f5ec; }
.cat-active-switch > div { display: grid; }
.cat-active-switch strong { color: #315744; font-size: .67rem; }
.cat-active-switch small { color: #7c8b83; font-size: .57rem; }
.cat-optional { border: 1px solid #dfe8e3; border-radius: 14px; }
.cat-optional summary { min-height: 58px; display: grid; grid-template-columns: 34px 1fr 28px; align-items: center; gap: .5rem; padding: .6rem .75rem; list-style: none; cursor: pointer; }
.cat-optional summary::-webkit-details-marker { display: none; }
.cat-optional summary > i:first-child { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 9px; color: #597064; background: #edf2ef; }
.cat-optional summary > span { display: grid; }
.cat-optional summary strong { color: #355345; font-size: .7rem; }
.cat-optional summary small { color: #7e8c84; font-size: .57rem; }
.cat-optional summary > i:last-child { color: #809087; transition: transform .18s ease; }
.cat-optional[open] summary > i:last-child { transform: rotate(180deg); }
.cat-optional[open] .cat-form-grid { border-top: 1px solid #e9efeb; }
.cat-color-input { min-height: 45px; display: flex; align-items: center; gap: .55rem; padding: .3rem .55rem; border: 1px solid #d9e4de; border-radius: 10px; }
.cat-color-input input { width: 42px; height: 32px; padding: 0; border: 0; background: none; }
.cat-color-input bdi { color: #6d7e75; font-size: .66rem; }
.spin { display: inline-block; animation: cat-spin .8s linear infinite; }
@keyframes cat-spin { to { transform: rotate(1turn); } }

@media (max-width: 1050px) {
    .cat-tools { grid-template-columns: minmax(220px, 1fr) auto; }
    .cat-select { grid-row: 2; }
    .cat-row { grid-template-columns: 54px 64px minmax(180px, 1fr) 118px; }
    .cat-actions { grid-column: 3 / -1; justify-content: flex-end; }
    .cat-image { width: 64px; height: 64px; }
}

@media (max-width: 700px) {
    .cat-tools { grid-template-columns: 1fr; }
    .cat-status, .cat-select { grid-row: auto; }
    .cat-status button { flex: 1; }
    .cat-row { grid-template-columns: 46px 58px minmax(0, 1fr); gap: .55rem; padding: .55rem; }
    .cat-order { grid-row: 1 / 3; align-self: stretch; flex-direction: column; justify-content: center; }
    .cat-order > div { grid-template-columns: repeat(2, 30px); }
    .cat-image { width: 58px; height: 58px; }
    .cat-identity p { white-space: normal; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
    .cat-visibility { grid-column: 2 / 4; min-height: 44px; }
    .cat-actions { grid-column: 2 / 4; justify-content: stretch; min-width: 0; }
    .cat-actions .edit { flex: 1; }
    .cat-protected { margin-inline-start: auto; }
    .cat-form-grid { grid-template-columns: 1fr; }
    .cat-form-grid .wide { grid-column: auto; }
    .cat-media-editor { grid-template-columns: 78px minmax(0, 1fr); }
    .cat-media-thumb { width: 78px; height: 72px; }
    .cat-active-switch { grid-column: 1 / -1; }
}

@media (max-width: 430px) {
    .cat-row { grid-template-columns: 42px 52px minmax(0, 1fr); border-radius: 12px; }
    .cat-image { width: 52px; height: 52px; }
    .cat-meta { display: grid; gap: .15rem; }
    .cat-preview { grid-template-columns: 52px minmax(0, 1fr); }
    .cat-preview-image { width: 52px; height: 52px; }
    .cat-preview em { grid-column: 1 / -1; text-align: center; }
    .cat-media-copy > div { flex-wrap: wrap; }
}

@media (prefers-reduced-motion: reduce) {
    .cat-row, .cat-optional summary > i:last-child { transition: none; }
}
</style>
