<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import MenuWorkspaceNav from '../../../Components/MenuAdmin/MenuWorkspaceNav.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useFormUx } from '../../../Composables/useFormUx';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    navigation: { type: Array, default: () => [] },
    mode: { type: String, required: true },
    item: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    allergens: { type: Array, default: () => [] },
    modifierGroups: { type: Array, default: () => [] },
    ingredients: { type: Array, default: () => [] },
    currencySymbol: { type: String, default: '' },
    submitUrl: { type: String, required: true },
    urls: { type: Object, required: true },
});

const isEdit = computed(() => props.mode === 'edit');
const imagePreview = ref(props.item?.imageUrl ?? '');
let localImageUrl = null;

const newRecipeRow = (seed = {}) => ({
    ingredient_id: seed.ingredient_id ?? '',
    quantity: seed.quantity ?? '',
    unit_id: seed.unit_id ?? '',
    is_optional: Boolean(seed.is_optional),
});

const form = useForm({
    category_id: props.item?.categoryId ?? '',
    station_id: props.item?.stationId ?? '',
    sku: props.item?.sku ?? '',
    name: props.item?.name ?? '',
    description: props.item?.description ?? '',
    price: props.item?.price ?? 0,
    price_change_reason: '',
    prep_time_minutes: props.item?.prepMinutes ?? 10,
    calories: props.item?.calories ?? '',
    display_order: props.item?.displayOrder ?? 0,
    is_available: props.item ? Boolean(props.item.isAvailable) : true,
    is_featured: props.item ? Boolean(props.item.isFeatured) : false,
    unavailable_reason: props.item?.unavailableReason ?? '',
    image: null,
    allergens: [...(props.item?.allergenIds ?? [])],
    modifier_groups: [...(props.item?.modifierGroupIds ?? [])],
    recipe: (props.item?.recipe ?? []).map(newRecipeRow),
});
const formRoot = ref(null);
const { focusFirstError } = useFormUx(form, { root: formRoot });

const selectedCategory = computed(() => props.categories.find((category) => Number(category.id) === Number(form.category_id)));
const selectedStation = computed(() => props.stations.find((station) => Number(station.id) === Number(form.station_id)));
const priceChanged = computed(() => isEdit.value && Math.abs(Number(form.price || 0) - Number(props.item?.price || 0)) > 0.001);
const duplicateIngredients = computed(() => {
    const ids = form.recipe.map((row) => Number(row.ingredient_id)).filter(Boolean);
    return [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
});
const visibleErrors = computed(() => Object.values(form.errors));

function ingredientFor(row) {
    return props.ingredients.find((ingredient) => Number(ingredient.id) === Number(row.ingredient_id));
}

function changeIngredient(row) {
    const ingredient = ingredientFor(row);
    row.unit_id = ingredient?.baseUnitId ? `u:${ingredient.baseUnitId}` : '';
}

function addRecipe() {
    form.recipe.push(newRecipeRow());
}

function removeRecipe(index) {
    form.recipe.splice(index, 1);
}

function toggleId(list, id) {
    const numeric = Number(id);
    const index = list.findIndex((value) => Number(value) === numeric);
    if (index >= 0) list.splice(index, 1);
    else list.push(numeric);
}

function hasId(list, id) {
    return list.some((value) => Number(value) === Number(id));
}

function selectImage(event) {
    const file = event.target.files?.[0] ?? null;
    form.image = file;
    if (localImageUrl) URL.revokeObjectURL(localImageUrl);
    localImageUrl = file ? URL.createObjectURL(file) : null;
    imagePreview.value = localImageUrl || props.item?.imageUrl || '';
}

onBeforeUnmount(() => {
    if (localImageUrl) URL.revokeObjectURL(localImageUrl);
});

function submit() {
    if (form.processing || duplicateIngredients.value.length) return;

    form.transform((data) => {
        const payload = {
            ...data,
            station_id: data.station_id || null,
            sku: data.sku || null,
            description: data.description || null,
            calories: data.calories === '' ? null : data.calories,
            unavailable_reason: data.is_available ? null : (data.unavailable_reason || null),
            recipe: data.recipe
                .filter((row) => row.ingredient_id && Number(row.quantity) > 0)
                .map((row) => ({
                    ingredient_id: row.ingredient_id,
                    quantity: row.quantity,
                    unit_id: row.unit_id || `u:${ingredientFor(row)?.baseUnitId ?? ''}`,
                    is_optional: row.is_optional,
                })),
        };
        if (isEdit.value) payload._method = 'PUT';
        return payload;
    }).post(props.submitUrl, { forceFormData: true, onError: focusFirstError });
}
</script>

<template>
    <Head :title="isEdit ? `تحرير ${item.name}` : 'صنف جديد'" />

    <PageHeader :title="isEdit ? `تحرير ${item.name}` : 'إنشاء صنف جديد'" icon="bi-egg-fried"
                subtitle="الوصفة هنا هي أساس خصم المخزون؛ كل كمية مكتوبة تخص حصة واحدة">
        <template #actions>
            <a :href="urls.index" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للأصناف</a>
        </template>
    </PageHeader>

    <MenuWorkspaceNav :links="navigation" />

    <div v-if="visibleErrors.length" class="mf-errors" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>تعذّر حفظ الصنف — صحّح الحقول المعلّمة.</strong>
            <ul><li v-for="(error, index) in visibleErrors" :key="index">{{ error }}</li></ul>
        </div>
    </div>

    <form ref="formRoot" class="mf-layout" @submit.prevent="submit">
        <main class="mf-main">
            <section class="mf-section">
                <header><i class="bi bi-card-text"></i><div><h2>هوية الصنف</h2><p>ما يقرأه الزبون وما يوجّه الطلب.</p></div></header>
                <div class="mf-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">اسم الصنف *</label>
                        <input v-model="form.name" name="name" class="form-control form-control-lg" required autofocus>
                        <small v-if="form.errors.name" class="mf-error">{{ form.errors.name }}</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">القسم *</label>
                        <select v-model="form.category_id" name="category_id" class="form-select" required>
                            <option value="" disabled>— اختر القسم —</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                        <small v-if="categories.length === 0" class="mf-error">لا توجد أقسام. <a :href="urls.categories">أنشئ قسماً أولاً.</a></small>
                        <small v-if="form.errors.category_id" class="mf-error">{{ form.errors.category_id }}</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">محطة التحضير</label>
                        <select v-model="form.station_id" name="station_id" class="form-select">
                            <option value="">تلقائياً من القسم</option>
                            <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
                        </select>
                        <small class="mf-help">اتركها تلقائية إلا إذا كان الصنف يذهب لمحطة مختلفة.</small>
                        <small v-if="form.errors.station_id" class="mf-error">{{ form.errors.station_id }}</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">SKU <small>(اختياري)</small></label>
                        <input v-model="form.sku" name="sku" class="form-control" placeholder="MI-001">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">وصف يظهر للزبون</label>
                        <textarea v-model="form.description" name="description" rows="3" class="form-control" placeholder="المكونات الرئيسية وطريقة التقديم"></textarea>
                    </div>
                </div>
            </section>

            <section class="mf-section">
                <header><i class="bi bi-cash-coin"></i><div><h2>السعر والتحضير</h2><p>قيم قصيرة يستعملها المنيو والمطبخ.</p></div></header>
                <div class="mf-body row g-3">
                    <div class="col-md-3">
                        <label class="form-label">سعر البيع *</label>
                        <div class="input-group"><input v-model="form.price" name="price" type="number" min="0" step="0.01" class="form-control" required><span class="input-group-text">{{ currencySymbol }}</span></div>
                        <small v-if="form.errors.price" class="mf-error">{{ form.errors.price }}</small>
                        <small class="mf-help">هذا السعر الأساسي الدائم. للسعر المؤقت استخدم <a :href="urls.promotions">العروض</a>.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">وقت التحضير</label>
                        <div class="input-group"><input v-model="form.prep_time_minutes" name="prep_time_minutes" type="number" min="0" class="form-control"><span class="input-group-text">دقيقة</span></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">السعرات</label>
                        <div class="input-group"><input v-model="form.calories" type="number" min="0" class="form-control"><span class="input-group-text">kcal</span></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">ترتيب العرض</label>
                        <input v-model="form.display_order" type="number" class="form-control">
                        <small class="mf-help">الأقل يظهر أولاً.</small>
                    </div>
                    <div v-if="priceChanged" class="col-12">
                        <label class="form-label">سبب تغيير السعر <small>(اختياري لكنه يظهر في كرت الصنف)</small></label>
                        <input v-model="form.price_change_reason" name="price_change_reason" class="form-control" maxlength="300"
                               placeholder="مثلاً: تحديث تكلفة المكونات أو اعتماد قائمة أسعار جديدة">
                        <small class="mf-help">سيُحفظ السعر السابق والجديد والتاريخ واسم من قام بالتعديل.</small>
                        <small v-if="form.errors.price_change_reason" class="mf-error">{{ form.errors.price_change_reason }}</small>
                    </div>
                </div>
            </section>

            <section class="mf-section">
                <header><i class="bi bi-image-fill"></i><div><h2>الصورة والظهور</h2><p>صورة واحدة واضحة وحالة يمكن تغييرها فوراً.</p></div></header>
                <div class="mf-body">
                    <div class="mf-image">
                        <div class="mf-preview" :class="{ empty: !imagePreview }">
                            <img v-if="imagePreview" :src="imagePreview" alt="معاينة الصنف">
                            <span v-else><i class="bi bi-image"></i> لا توجد صورة</span>
                        </div>
                        <div><input name="image" type="file" class="form-control" accept="image/*" @change="selectImage"><small class="mf-help">JPG أو PNG حتى 5MB. الصورة تُقص تلقائياً في بطاقات المنيو.</small><small v-if="form.errors.image" class="mf-error">{{ form.errors.image }}</small></div>
                    </div>
                    <div class="mf-toggles">
                        <label :class="{ active: form.is_available }"><input v-model="form.is_available" type="checkbox"><i class="bi bi-check-circle-fill"></i><span><b>متاح للطلب</b><small>يظهر ويمكن إضافته للسلة.</small></span></label>
                        <label :class="{ active: form.is_featured }"><input v-model="form.is_featured" type="checkbox"><i class="bi bi-star-fill"></i><span><b>مميز اليوم</b><small>يظهر في شريط المميز.</small></span></label>
                    </div>
                    <div v-if="!form.is_available" class="mt-3">
                        <label class="form-label">سبب عدم التوفر</label>
                        <input v-model="form.unavailable_reason" class="form-control" placeholder="مثلاً: مكون ناقص — يعود غداً">
                    </div>
                </div>
            </section>

            <section class="mf-section">
                <header><i class="bi bi-shield-check"></i><div><h2>الحساسية والتخصيص</h2><p>تنبيه واضح للزبون، وخيارات اختيارية للصنف.</p></div></header>
                <div class="mf-body mf-split">
                    <div>
                        <div class="mf-subhead"><b>مسببات الحساسية</b><a :href="urls.allergens">إدارة</a></div>
                        <div class="mf-chips">
                            <button v-for="allergen in allergens" :key="allergen.id" type="button"
                                    :class="{ selected: hasId(form.allergens, allergen.id) }" @click="toggleId(form.allergens, allergen.id)">
                                <span>{{ allergen.icon }}</span> {{ allergen.name }}
                            </button>
                            <p v-if="!allergens.length">لم تُضف مسببات حساسية بعد.</p>
                        </div>
                    </div>
                    <div>
                        <div class="mf-subhead"><b>مجموعات الإضافات</b><a :href="urls.modifiers">إدارة</a></div>
                        <div class="mf-mods">
                            <button v-for="group in modifierGroups" :key="group.id" type="button"
                                    :class="{ selected: hasId(form.modifier_groups, group.id) }" @click="toggleId(form.modifier_groups, group.id)">
                                <span><b>{{ group.name }}</b><small>{{ group.required ? 'اختيار إلزامي' : 'اختياري' }} · {{ group.options.length }} خيار</small></span>
                                <i class="bi" :class="hasId(form.modifier_groups, group.id) ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                            </button>
                            <p v-if="!modifierGroups.length">لم تُضف مجموعات خيارات بعد.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mf-section mf-recipe-section">
                <header><i class="bi bi-basket2-fill"></i><div><h2>وصفة الحصة الواحدة</h2><p>تُخصم هذه الكميات عند بدء التحضير، من أقدم دفعة صالحة أولاً.</p></div><a :href="urls.ingredients">إدارة المكونات</a></header>
                <div class="mf-body">
                    <div v-if="duplicateIngredients.length" class="mf-duplicate"><i class="bi bi-exclamation-triangle-fill"></i> نفس المكوّن مكرر. ادمج كميته في سطر واحد قبل الحفظ.</div>
                    <div class="mf-recipe-head"><span>المكوّن</span><span>كمية الحصة</span><span>الوحدة</span><span>اختياري</span><span></span></div>
                    <div class="mf-recipe-list">
                        <div v-for="(row, index) in form.recipe" :key="index" class="mf-recipe-row"
                             :class="{ invalid: form.errors[`recipe.${index}.unit_id`] || duplicateIngredients.includes(Number(row.ingredient_id)) }">
                            <select v-model="row.ingredient_id" :name="`recipe.${index}.ingredient_id`" class="form-select" @change="changeIngredient(row)">
                                <option value="">— اختر مكوّناً —</option>
                                <option v-for="ingredient in ingredients" :key="ingredient.id" :value="ingredient.id">
                                    {{ ingredient.name }} ({{ ingredient.baseUnitCode || ingredient.baseUnitName }})
                                </option>
                            </select>
                            <input v-model="row.quantity" :name="`recipe.${index}.quantity`" type="number" min="0" step="any" class="form-control" placeholder="0">
                            <span class="mf-unit">{{ ingredientFor(row)?.baseUnitName || '—' }}</span>
                            <label class="mf-optional" title="المكوّن الاختياري لا يمنع تحضير الصنف عند نقصه"><input v-model="row.is_optional" type="checkbox"><span>نعم</span></label>
                            <button type="button" class="mf-remove" aria-label="حذف المكون" @click="removeRecipe(index)"><i class="bi bi-x-lg"></i></button>
                            <small v-if="form.errors[`recipe.${index}.unit_id`]" class="mf-row-error">{{ form.errors[`recipe.${index}.unit_id`] }}</small>
                        </div>
                    </div>
                    <button type="button" class="mf-add" @click="addRecipe"><i class="bi bi-plus-circle-fill"></i> إضافة مكوّن</button>
                    <div class="mf-equation"><i class="bi bi-calculator"></i><span><b>المعادلة:</b> كمية الوصفة × عدد الوجبات. مثال: 150 غ دجاج × 3 = خصم 450 غ عند بدء المطبخ بالطلب.</span></div>
                </div>
            </section>
        </main>

        <aside class="mf-summary">
            <div class="mf-summary-card">
                <span class="mf-summary-icon"><i class="bi bi-eye-fill"></i></span>
                <h3>{{ form.name || 'صنف جديد' }}</h3>
                <p>{{ selectedCategory?.name || 'اختر القسم' }} · {{ selectedStation?.name || 'محطة القسم' }}</p>
                <strong>{{ Number(form.price || 0).toFixed(2) }} {{ currencySymbol }}</strong>
                <div><span :class="form.is_available ? 'on' : 'off'">{{ form.is_available ? 'متاح' : 'متوقف' }}</span><span v-if="form.is_featured" class="featured">مميز</span></div>
                <ul>
                    <li><i class="bi bi-basket2"></i> {{ form.recipe.filter(row => row.ingredient_id).length }} مكوّن في الوصفة</li>
                    <li><i class="bi bi-shield-exclamation"></i> {{ form.allergens.length }} تنبيه حساسية</li>
                    <li><i class="bi bi-sliders2"></i> {{ form.modifier_groups.length }} مجموعة إضافات</li>
                </ul>
            </div>
            <div class="mf-save-note"><i class="bi bi-info-circle-fill"></i> تعديل الوصفة يؤثر على الطلبات الجديدة فقط. الطلبات السابقة تحتفظ ببياناتها المسجلة.</div>
        </aside>

        <footer class="mf-actions">
            <a :href="urls.index" class="btn btn-light">إلغاء</a>
            <button type="submit" class="btn btn-primary" :disabled="form.processing || duplicateIngredients.length || !categories.length">
                <span v-if="form.processing" class="spinner-border spinner-border-sm"></span>
                <i v-else class="bi bi-check-circle-fill"></i>
                {{ form.processing ? 'جاري الحفظ…' : 'حفظ الصنف' }}
            </button>
        </footer>
    </form>
</template>

<style scoped>
.mf-errors { display: flex; gap: .7rem; align-items: flex-start; margin-bottom: 1rem; padding: .85rem 1rem; border: 1px solid #f0bbc1; border-radius: 14px; background: #fff1f2; color: #a52231; }
.mf-errors > i { font-size: 1.15rem; }.mf-errors strong { font-size: .83rem; }.mf-errors ul { margin: .35rem 0 0; padding-inline-start: 1.1rem; font-size: .74rem; }
.mf-layout { display: grid; grid-template-columns: minmax(0, 1fr) 255px; gap: 1rem; align-items: start; padding-bottom: 78px; }
.mf-main { min-width: 0; display: flex; flex-direction: column; gap: .8rem; }
.mf-section { overflow: hidden; border: 1px solid #e5ece7; border-radius: 17px; background: #fff; box-shadow: 0 7px 22px rgba(15,71,49,.035); }
.mf-section > header { display: flex; align-items: center; gap: .6rem; padding: .8rem 1rem; border-bottom: 1px solid #e9efeb; background: linear-gradient(145deg,#f5faf7,#fff); }
.mf-section > header > i { width: 38px; height: 38px; display: grid; place-items: center; flex: 0 0 auto; border-radius: 11px; color: #0f7044; background: #e8f6ed; }
.mf-section > header div { flex: 1; min-width: 0; }.mf-section > header h2 { margin: 0; color: #14261d; font-size: .92rem; font-weight: 900; }.mf-section > header p { margin: .12rem 0 0; color: #77867e; font-size: .7rem; }.mf-section > header > a { color: #126e44; font-size: .72rem; font-weight: 800; }
.mf-body { padding: 1rem; }.mf-error { display: block; margin-top: .2rem; color: #b42334; font-size: .7rem; }.mf-help { display: block; margin-top: .25rem; color: #7c8b83; font-size: .68rem; }
.mf-image { display: grid; grid-template-columns: 150px minmax(0,1fr); gap: .85rem; align-items: center; padding: .7rem; border: 1px dashed #cfdcd3; border-radius: 13px; background: #fafcfb; }
.mf-preview { aspect-ratio: 4 / 3; overflow: hidden; border-radius: 11px; background: #eef3ef; }.mf-preview img { width:100%;height:100%;object-fit:cover; }.mf-preview.empty { display:grid;place-items:center;color:#8c9a92;font-size:.72rem; }.mf-preview.empty span { display:flex;flex-direction:column;align-items:center;gap:.25rem; }.mf-preview.empty i{font-size:1.35rem;}
.mf-toggles { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; margin-top: .8rem; }.mf-toggles label { display:flex;align-items:flex-start;gap:.55rem;padding:.65rem;border:1px solid #e0e8e2;border-radius:12px;cursor:pointer;background:#fafcfb; }.mf-toggles label.active{border-color:#91cfaa;background:#f0faf4;}.mf-toggles input{margin-top:.18rem;}.mf-toggles i{color:#b4bdb7;margin-top:.1rem;}.mf-toggles label.active i{color:#0b7a46;}.mf-toggles span{display:flex;flex-direction:column;}.mf-toggles b{font-size:.77rem;color:#34483d;}.mf-toggles small{font-size:.66rem;color:#809087;}
.mf-split { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }.mf-subhead{display:flex;justify-content:space-between;align-items:center;margin-bottom:.45rem;}.mf-subhead b{font-size:.78rem;color:#34483d;}.mf-subhead a{font-size:.68rem;color:#127146;font-weight:800;}
.mf-chips { display:flex;flex-wrap:wrap;gap:.35rem; }.mf-chips button { border:1px solid #dce6df;border-radius:999px;background:#fff;color:#5d6e64;padding:.36rem .58rem;font-size:.7rem;font-weight:750; }.mf-chips button.selected{color:#fff;border-color:#126d43;background:#126d43;}.mf-chips p,.mf-mods p{margin:0;color:#849188;font-size:.72rem;}
.mf-mods{display:flex;flex-direction:column;gap:.35rem;}.mf-mods button{display:flex;align-items:center;gap:.5rem;width:100%;padding:.52rem .6rem;border:1px solid #e0e8e2;border-radius:10px;background:#fff;color:#52645a;text-align:start;}.mf-mods button>span{display:flex;flex:1;flex-direction:column;}.mf-mods b{font-size:.73rem;}.mf-mods small{font-size:.63rem;color:#89968f;}.mf-mods button.selected{border-color:#83c5a0;background:#f0faf4;color:#0d6740;}
.mf-duplicate { display:flex;gap:.45rem;padding:.6rem .7rem;margin-bottom:.7rem;border-radius:10px;background:#fff1f2;color:#a62231;font-size:.74rem;font-weight:750; }
.mf-recipe-head,.mf-recipe-row{display:grid;grid-template-columns:minmax(190px,2fr) minmax(110px,.75fr) minmax(90px,.55fr) 74px 42px;gap:.45rem;align-items:center;}.mf-recipe-head{padding:0 .55rem .35rem;color:#7b8981;font-size:.65rem;font-weight:800;}.mf-recipe-list{display:flex;flex-direction:column;gap:.45rem;}.mf-recipe-row{padding:.55rem;border:1px solid #e4ebe6;border-radius:11px;background:#fafcfb;}.mf-recipe-row.invalid{border-color:#e6aab1;background:#fff7f8;}.mf-unit{min-height:38px;display:grid;place-items:center;padding:.3rem;border-radius:8px;background:#eaf2ed;color:#365448;font-size:.72rem;font-weight:800;}.mf-optional{display:flex;align-items:center;justify-content:center;gap:.3rem;font-size:.68rem;color:#617168;}.mf-remove{width:38px;height:38px;border:1px solid #eed0d4;border-radius:9px;background:#fff;color:#ad2b39;}.mf-row-error{grid-column:1/-1;color:#b42334;font-size:.68rem;}.mf-add{display:inline-flex;align-items:center;gap:.4rem;margin-top:.65rem;padding:.5rem .8rem;border:1px dashed #90bfa4;border-radius:10px;background:#f3faf6;color:#116c43;font-size:.74rem;font-weight:850;}.mf-equation{display:flex;gap:.55rem;margin-top:.75rem;padding:.65rem .75rem;border-radius:11px;background:#eff7f2;color:#597066;font-size:.7rem;line-height:1.6;}.mf-equation i{color:#0d7546;margin-top:.12rem;}
.mf-summary { position:sticky;top:82px;display:flex;flex-direction:column;gap:.65rem; }.mf-summary-card{padding:1rem;border:1px solid #e3ebe5;border-radius:17px;background:#fff;box-shadow:0 8px 24px rgba(15,71,49,.055);text-align:center;}.mf-summary-icon{width:48px;height:48px;display:grid;place-items:center;margin:0 auto .55rem;border-radius:14px;background:#eaf7ef;color:#0e7144;font-size:1.15rem;}.mf-summary h3{margin:0;font-size:.95rem;font-weight:900;color:#173026;}.mf-summary p{margin:.18rem 0;color:#839087;font-size:.68rem;}.mf-summary>div>strong{display:block;margin:.6rem 0;color:#0c6e42;font-size:1.1rem;}.mf-summary-card>div{display:flex;justify-content:center;gap:.35rem;}.mf-summary-card>div span{padding:.18rem .48rem;border-radius:999px;font-size:.65rem;font-weight:850;}.mf-summary .on{background:#eaf8ef;color:#087343;}.mf-summary .off{background:#fff0f1;color:#a32231;}.mf-summary .featured{background:#fff5d7;color:#805a0c;}.mf-summary ul{list-style:none;margin:.75rem 0 0;padding:.7rem 0 0;border-top:1px solid #edf1ee;text-align:start;display:flex;flex-direction:column;gap:.42rem;color:#627269;font-size:.69rem;}.mf-summary li{display:flex;gap:.4rem;}.mf-save-note{display:flex;gap:.45rem;padding:.65rem;border-radius:12px;background:#fff8e8;color:#80601c;font-size:.68rem;line-height:1.55;}
.mf-actions{position:fixed;z-index:1000;inset:auto 0 0;display:flex;justify-content:flex-end;gap:.55rem;padding:.7rem max(1rem,calc((100vw - 1480px)/2));border-top:1px solid #dfe7e2;background:rgba(255,255,255,.94);backdrop-filter:blur(10px);box-shadow:0 -8px 24px rgba(15,23,42,.07);}
@media(max-width:1100px){.mf-layout{grid-template-columns:1fr;}.mf-summary{position:static;display:none;}}
@media(max-width:767.98px){.mf-split{grid-template-columns:1fr}.mf-recipe-head{display:none}.mf-recipe-row{grid-template-columns:1fr 1fr}.mf-recipe-row select{grid-column:1/-1}.mf-unit{min-height:38px}.mf-remove{justify-self:end}.mf-image{grid-template-columns:105px 1fr}.mf-toggles{grid-template-columns:1fr}.mf-actions{padding-inline:.8rem}.mf-actions .btn{flex:1;}}
</style>
