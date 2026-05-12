# restaurant-qr — Session Log

سجل جلسة عمل مكثّفة على نظام **Relax** لإدارة المطاعم بنظام QR، نُفِّذت على فرع `claude/adoring-bohr-6575c1` ودُمجت كاملةً إلى `main`. التوثيق يغطي القرارات التصميمية والإصلاحات والتحاليل وسبب كل تغيير.

---

## فهرس
1. [ملخّص تنفيذي](#ملخّص-تنفيذي)
2. [الأنماط المتكرّرة وحلولها](#الأنماط-المتكرّرة-وحلولها)
3. [التغييرات حسب المجال](#التغييرات-حسب-المجال)
4. [قواعد بيانية وعمارة](#قواعد-بيانية-وعمارة)
5. [خرائط الـ workflows](#خرائط-الـ-workflows)
6. [قائمة الـ commits](#قائمة-الـ-commits)

---

## ملخّص تنفيذي

| المحور | عدد الـ commits | أبرز التغييرات |
|---|---:|---|
| تعدّد الفروع (Multi-branch) | 8 | فلترة العرض، حماية المسارات، fallback للـ branch_id |
| سير عمل المشتريات (PO) | 12 | combobox للبحث، تحرير السعر عند الاستلام، أزرار حسب الحالة، حجب CTA الفواتير المكرّر |
| واجهة الزبون | 4 | side-toast، header polish، QR print A4، إخفاء زر الإلغاء عند التحضير |
| التقارير | 3 | شرح KPIs، تفصيل استهلاك المخزون، صياغة عربية مفهومة |
| إصلاحات بنيوية | 6 | breadcrumb مكسور، Alpine race conditions، Livewire script stripping |
| تجربة مستخدم | 5 | ترتيب القائمة الجانبية، سياق الفروع، سياق الأعمال |

**41 commit** خلال الجلسة، كلها merged إلى main مع سجل تاريخي خطّي.

---

## الأنماط المتكرّرة وحلولها

### النمط ١: `branch_id NOT NULL` يُلقي SQL exception

**المشكلة:** عدّة جداول (`purchase_orders`، `storage_locations`، `ingredient_batches`، `inventory_movements`) عندها عمود `branch_id NOT NULL`. الـ trait `BelongsToBranch` يضع تلقائياً `branch_id` من `BranchContext::current()`، **لكن** الـ context يكون فاضي للسوبر أدمن في وضع "كل الفروع" أو لـ queue workers أو CLI.

**النمط الحلّي (`Strongest-signal-first chain`):**

```php
// 1. Storage location (الأقوى — البضاعة فعلياً موجودة فيه)
if ($storageLocationId) {
    $branchId = StorageLocation::whereKey($storageLocationId)->value('branch_id');
}
// 2. Source record (PO line يأخذها من PO الأب)
if (! $branchId && $source) {
    $branchId = $source->branch_id
        ?? optional($source->purchaseOrder)->branch_id;
}
// 3. User's primary / first member branch
$branchId ??= optional($user->primaryBranch())->id
    ?? optional($user->branches()->first())->id;
// 4. Owner-level final fallback: first active branch
if (! $branchId && $user->isOwnerLevel()) {
    $branchId = Branch::active()->orderBy('display_order')->value('id');
}
// 5. لا يزال فارغ → ValidationException بنص عربي واضح
```

**Commits:** `d8d4df0`, `6b8bffe`, `f93397d`, `ebd6b93`

### النمط ٢: Livewire 4 يحذف الـ `<script>` المرفقة

**المشكلة:** `<script>window.alpineFn = ...</script>` كشقيق لـ Livewire root → يُحذف بدون تنبيه. Alpine يقيّم `x-data="alpineFn(...)"` قبل ما الـ function تُعرَّف → الـ component يظهر فارغاً.

**الحل:** استخدام `@script ... @endscript` (Livewire directive) — يُسجَّل مع Alpine ويعمل قبل تقييم x-data.

```blade
{{-- بدل: --}}
<script>window.poLineBuilder = function(c){...};</script>

{{-- استخدم: --}}
@script
<script>window.poLineBuilder = function(c){...};</script>
@endscript
```

**Commits:** `f467092` (po-line-builder), `b058635` (po-receive-form)

### النمط ٣: Alpine x-model + x-for race condition

**المشكلة:** عند تحرير سجل موجود، الـ `<select>` يُربط بـ `x-model="line.ingredient_id"` قبل ما الـ `<template x-for="ing in ingredients">` يولّد الـ `<option>`، فالـ select يُعرض بالـ placeholder حتى لو الستيت معبّاة بـ id صحيح.

**الحل:** بعد الـ init، فِي `$nextTick` نعمل re-assignment صغير:

```js
this.$nextTick(() => {
    const sup = this.supplier_id;
    this.supplier_id = 0;
    this.supplier_id = sup;            // re-trigger x-model lookup
    this.lines = this.lines.map(l => ({ ...l }));
});
```

**Commit:** `78bb3ac`

### النمط ٤: Policy::before يتجاوز فحوصات الحالة

**المشكلة:** `BasePolicy::before()` ترجع `true` للـ owner-level → كل `@can()` تنجح بصرف النظر عن حالة السجل. النتيجة: السوبر أدمن يرى كل أزرار الإجراءات على PO حتى لو الحالة لا تسمح.

**الحل:** افحص حالة الـ Model **بالإضافة إلى** الـ policy، مش بدلاً منها.

```blade
{{-- ❌ القديم: --}}
@can('approve', $po) ... @endcan

{{-- ✅ الجديد: --}}
@if($po->isApprovable())
    @can('approve', $po) ... @endcan
@endif
```

**Commit:** `1f7c5dd`

### النمط ٥: BranchScope يحجب route binding للسجل المباشر

**المشكلة:** `BranchScope` بيفلتر القوائم بحسب الفرع النشط — صحيح للقوائم. لكن لو السوبر أدمن ضغط رابط `/admin/purchase-orders/2` وكان مبدّلاً لفرع غير الفرع المالك للـ PO، يحصل 404.

**الحل:** في trait `BelongsToBranch`، override الـ `resolveRouteBinding` بحيث ينفّذ في `unscoped` للـ owner-level:

```php
public function resolveRouteBinding($value, $field = null)
{
    if (auth()->user()?->isOwnerLevel()) {
        return BranchContext::unscoped(
            fn () => parent::resolveRouteBinding($value, $field)
        );
    }
    return parent::resolveRouteBinding($value, $field);
}
```

النتيجة: القوائم تظل مفلترة، لكن السوبر أدمن يقدر يفتح أي سجل بـ URL مباشر. Branch admins/managers يظلوا مقيّدين بفرعهم.

**Commit:** `d874b7d`

---

## التغييرات حسب المجال

### المشتريات (Purchase Orders)

#### السيناريو الكامل
```
[إنشاء مسودة] → [اعتماد] → [إرسال للمورد] → [استلام] → [فاتورة المورد] → [دفع]
   ↓ تعديل       ↓ إلغاء    ↓ إلغاء         ↓ استلام دفعة جديدة
                                            (للجزئي)
```

#### الإصلاحات الرئيسية

1. **القائمة المنسدلة للمكوّن صارت قابلة للبحث** (`02338dc`)
   - Combobox مخصص: نص للبحث + قائمة منسدلة + رسالة "لا نتائج"
   - حالة `_q` و `_open` مخزّنة على كل سطر مستقل
   - `@click.outside` يقفل القائمة
   
2. **سعر الوحدة قابل للتعديل عند الاستلام** (`e488f6f`)
   - السعر الفعلي قد يختلف عن سعر الـ PO (تغيّر الأسعار، فاتورة محدّثة)
   - النظام يستخدم السعر الفعلي للـ Weighted Average ولسجل أسعار المورد
   - تنبيه ⚠️ بصري + tooltip لو السعر مختلف عن سعر PO

3. **أزرار الإجراءات حسب حالة الـ PO** (`1f7c5dd`)
   - `draft+not approved` → اعتماد · تعديل · إلغاء
   - `draft+approved` → إرسال · إلغاء
   - `sent` → استلام · إلغاء
   - `partially_received` → استلام دفعة جديدة · فاتورة · إلغاء
   - `received` → فاتورة (إذا غير مفوترة) أو شارة "فُوتر بالكامل"
   - `cancelled` → لا أزرار

4. **حجب زر الفاتورة عند الاكتمال** (`e1b6d45`)
   - أُضيف `Order::isFullyInvoiced()` — يفحص أن كل كمية مستلمة مغطّاة بفواتير
   - يستبدل الزر بشارة خضراء "فُوتر بالكامل" للتأكيد البصري

5. **بحث الموردين مفلتر بالفرع** (`f4370d3`)
   - استخدام scope `Supplier::servingBranch($branchId)` (موجود مسبقاً)
   - فرع غزة لا يرى موردين حصريين لخان يونس إلا لو مرتبطين بكلا الفرعين

#### استلام البضاعة (Receipt)

- `الكمية` = ما وصل فعلاً (ممكن أقل من المطلوب → الباقي مفتوح)
- `رقم الدفعة` اختياري لكل صنف على حدة (لتتبّع FIFO والصلاحية)
- `سعر/وحدة` معبّأ مسبقاً بسعر PO، قابل للتعديل
- عند الحفظ:
  - تُسجَّل حركة مخزون "إدخال" (`InventoryMovement` type=in)
  - تُنشأ دفعة جديدة (`IngredientBatch`)
  - يتحدّث متوسط تكلفة المكوّن (Weighted Average)
  - تُعاد تكلفة أصناف المنيو المرتبطة

### المخزون (Inventory)

#### الدفعات (Batches)

**ما هي ولماذا تلزم؟**
- كل استلام من مورد يُنشئ دفعة منفصلة بـ: تاريخ استلام، تاريخ صلاحية، كمية أصلية، كمية متبقية، تكلفة وحدة في تلك الدفعة بالذات
- **لازمة لـ:**
  1. **FIFO** — الاستهلاك من الأقدم تلقائياً
  2. **تتبّع الصلاحية** — الأكواد المنتهية / المنتهية قريباً
  3. **استرجاع المنتجات** — لو مشكلة في شحنة، تعرف بالضبط أي وصفات استخدمتها

**متى تتحدّث؟**
- ✅ عند استلام PO → تُنشأ دفعة
- ✅ عند الاستهلاك (بيع/هدر/تحويل) → تنقص `remaining_qty`
- ❌ **دفع فاتورة المورد لا يؤثر** — مسارات منفصلة عن قصد:
  - الدفعات تتعقّب البضاعة الفعلية
  - الفواتير والدفعات المالية تتعقّب المستحقات

#### مواقع التخزين (Storage Locations)

ما في زر "إضافة كمية مباشرة" قصداً. كل حركة لازم من **مصدر موثَّق**:
1. **أمر شراء** — استلام من مورّد
2. **فاتورة مورّد مباشرة** — بدون أمر شراء مسبق
3. **نقل بين المواقع** — داخل نفس الفرع
4. **تحويل بين الفروع** — استلام بضاعة من فرع آخر

للتعديلات الاستثنائية: **جرد جديد** أو **تسجيل هدر** (يتطلبان سبباً موثَّقاً).

#### تحويلات بين الفروع (Branch Transfers)

- **مقصور على owner-level** — مدير الفرع لا يقدر يسحب من فرع آخر
- branch admin/manager يرى القائمة (incoming/outgoing لفرعه) قراءة فقط
- إنشاء/إرسال/استلام/إلغاء — للسوبر أدمن والشريك فقط
- كل الـ mutations محمية backend + frontend

### واجهة الزبون (Customer)

1. **Toast جانبي مدمج** بدل شريط ممتد (`75cb372`)
   - Bottom-end stack، 360px max-width، slide animation
   - عدة toasts تتكدّس بدل ما تختفي فوق بعضها

2. **هيدر مصقول** (`75cb372`)
   - radial highlight ذهبي على بداية الشريط
   - logo بإطار ذهبي ناعم
   - شارة الطاولة gradient ذهبي

3. **بطاقة QR للطباعة** (`c44e354`)
   - A4 portrait صفحة وحدة مضمونة
   - QR كبير 110mm، رقم الطاولة 44pt
   - زوايا ذهبية وشريط زخرفي مزدوج
   - شريط الـ URL مخفي في الطباعة (المعلومة في الـ QR نفسه)

4. **زر إلغاء الطلب** (`5dc23fe`)
   - يختفي عند `preparing` فما بعد (الزبون والادمن)
   - الـ controller يرفض الـ POST لو حد جرّب يدوياً
   - الإلغاء الجزئي للأصناف يبقى متاحاً للموظفين

5. **سبب إلغاء الصنف** (`8e8bb07`)
   - يُعرض تحت اسم الصنف في كارد أحمر ناعم
   - يبقى بكامل التباين حتى مع الـ strike-through على باقي العناصر

### تعدّد الفروع (Multi-Branch)

#### `User::accessibleBranchIds()` و `isManagementLevel()` (`05ac4fb`)
- Helper مركزي: المستخدم يرى فروعه فقط (owner-level يرى الكل)
- شاشات Overview و Live Monitor صارت branch-scoped
- Aggregate queries تستخدم `whereIn('branch_id', $accessibleIds)`

#### Sidebar (`d23bc1b`)
- ترتيب قائمة "المخزون والمشتريات" حسب workflow حقيقي:
  - **المراقبة** — لوحة المخزون، حركات المخزن
  - **المرجعيات** — المكونات، وحدات القياس، مواقع التخزين
  - **المشتريات** — الموردون، مقارنة الأسعار، أوامر الشراء، فواتير الموردين
  - **العمليات اليومية** — الدفعات والصلاحية، الجرد، الهدر، التحويلات
- رؤوس أقسام ذهبية بدون تفاعل (`pointer-events: none`)

### التقارير (Reports)

#### تقرير الأرباح والخسائر (`4a85bc0`)

أُضيف legend عربي تحت شريط KPIs:
- **الإيرادات** = مجموع المبيعات قبل خصم أي تكلفة
- **تكلفة المبيعات** = تكلفة المكوّنات للأصناف **المباعة** فقط
- **الربح الإجمالي** = الإيرادات − تكلفة المبيعات (لا يشمل الرواتب والإيجار)
- **هامش الربح %** = (الربح ÷ الإيرادات) × 100. **50%+ ممتاز · 30–50% مقبول · <30% مراجعة**

عمود "هامش" في جدول الأكثر ربحية صار "هامش الربح %" مع icon معلومات + tooltip للمعادلة.

#### نهاية اليوم (`6cfdf77`)

قسم "استهلاك المخزون" صار يفصل:
- 🟢 **بيع** (`out`) — استُهلكت في تحضير الأصناف المباعة
- 🔴 **هدر** (`waste`) — سُجِّلت يدوياً كتالف

التكلفة محسوبة بـ Weighted Average للمكوّن وقت الحركة.

#### التكلفة بأسعار الفرع (`36fc665`)

استبدلت النص الفنّي بـ:
> **احسب تكلفة الأصناف بأسعار الشراء الفعلية لهذا الفرع**
> افتراضياً نحسب تكلفة كل طبق بالسعر الموحَّد المسجَّل على المكوّن. عند تفعيل هذا الخيار، نستخدم بدلاً منه **متوسط ما دفعه هذا الفرع فعلياً** لشراء المكوّنات. أدق لاحتساب صافي ربح الفرع لما تكون الفروع تشتري من موردين بأسعار متفاوتة.

(كان فيه كلمة ألمانية `dieser` بالغلط من autocorrect.)

### العمليات (Operations)

#### auto-SKU للمكونات (`5e188fd`)

كان المستخدم يدخل SKU يدوياً ويحزر آخر رقم. الآن:
- `Ingredient::booted()` model event يُنشئ تلقائياً `ING-#####`
- الخانة مخفية من فورم الإنشاء، تظهر للقراءة فقط في التعديل
- `generateSku()` يقرأ MAX من الجدول (شامل المحذوفات لمنع تصادم)

#### أرقام الكميات نظيفة (`648c154`)

`520.0000` → `520`. أُضيف helper `App\Helpers\Qty::format($v)`:
- Default 2 منازل، يحذف الأصفار التافهة
- 17 ملف blade تم تحويلها
- استثناء: سعر صرف العملات (4 منازل دقة فعلية)
- Alpine `fmtQty()` method للنماذج التفاعلية

---

## قواعد بيانية وعمارة

### Multi-Branch Architecture

الجداول التشغيلية تستخدم trait `BelongsToBranch`:
- Order, Invoice, Reservation, Table
- PurchaseOrder, IngredientBatch, InventoryMovement
- Expense, Shift, Attendance, Review
- StorageLocation, BranchTransfer

الجداول العالمية (لا تُفلتر بالفرع):
- Ingredient, MenuItem, Category, Unit, Allergen
- Supplier (مع pivot `branch_supplier` للربط)
- Role, Permission

### Cancel rules

| من | متى |
|---|---|
| `canCancelEntireOrder()` | pending أو approved فقط |
| `cancelItem()` | في أي حالة (per-item) |
| `customer cancel` | نفس قاعدة canCancelEntireOrder |
| admin bulk cancel | محمي backend بنفس القاعدة |

### Policies & Roles

```
Owner-level (SuperAdmin + Partner) → cross-branch, bypass per-method via BasePolicy::before
Management (Admin + Manager) → branch-scoped, viewAny + manage
Floor staff (Waiter + Chef + Cashier + Bartender) → role-specific endpoints
```

`isManagementLevel()` للشاشات اللي تتطلّب management view (overview, live-monitor, dashboard).

---

## خرائط الـ workflows

### دورة شراء المكوّنات

```
                    ┌─────────────────────────────────────────────┐
                    │ 1. مرجعيات (مرة واحدة)                     │
                    │    • وحدات القياس                          │
                    │    • مواقع التخزين                         │
                    │    • المكونات (auto SKU)                   │
                    │    • الموردون (مع ربط بفروع)               │
                    └─────────────────────────────────────────────┘
                                       │
                                       ▼
    ┌──────────────────────────────────────────────────────────────┐
    │ 2. أمر الشراء (PO)                                            │
    │    • اختر مورد (مفلتر بالفرع)                                │
    │    • اختر مكون (combobox قابل للبحث)                         │
    │    • النظام يجلب آخر سعر من نفس المورد لنفس المكوّن            │
    │    • supplier_id × ingredient × branch → سعر افتراضي           │
    └──────────────────────────────────────────────────────────────┘
                                       │
                              [اعتماد] ▼
                                       │
                              [إرسال] ▼
                                       │
                                       ▼
    ┌──────────────────────────────────────────────────────────────┐
    │ 3. استلام البضاعة                                             │
    │    • وجهة التخزين = موقع التخزين (مفلتر بفرع الـ PO)          │
    │    • السعر قابل للتعديل (لو الفعلي مختلف عن PO)               │
    │    • ينشأ:                                                    │
    │       - InventoryMovement (type=in)                           │
    │       - IngredientBatch (للـ FIFO والصلاحية)                  │
    │       - IngredientSupplierPrice (تاريخ الأسعار)              │
    │    • يحدّث:                                                   │
    │       - cost_per_unit (Weighted Average)                      │
    │       - تكلفة أصناف المنيو المرتبطة                           │
    └──────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
    ┌──────────────────────────────────────────────────────────────┐
    │ 4. فاتورة المورد                                              │
    │    • نسجّلها بعد استلام البضاعة الفعلية                       │
    │    • تُرتبط بـ PO وبسطور الاستلام                              │
    │    • CTA يختفي تلقائياً عند الاكتمال (isFullyInvoiced)         │
    └──────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
    ┌──────────────────────────────────────────────────────────────┐
    │ 5. الدفع للمورد                                               │
    │    • مستقل عن البضاعة (الذمم المالية فقط)                    │
    │    • لا يؤثر على الدفعات أو المخزون                           │
    └──────────────────────────────────────────────────────────────┘
```

### دورة الطلب (الزبون)

```
QR scan → فتح الجلسة (table_session) → تصفح المنيو (filtered by branch)
       → إضافة لسلة (toast جانبي)
       → إرسال الطلب (status = pending)
                │
                ▼
       موافقة الجرسون (status = approved)
                │
                ▼
       المطبخ بدأ التحضير (status = preparing)
                │  ← من هنا، زر "إلغاء الطلب" يختفي عن الزبون
                ▼
       جاهز → مُسلّم → مكتمل
```

### Cancellation rules

| المرحلة | الزبون | الموظف |
|---|---|---|
| pending | إلغاء كلّي ✓ | إلغاء كلّي ✓ |
| approved | إلغاء كلّي ✓ | إلغاء كلّي ✓ |
| preparing | × (مخفي) | إلغاء صنف-بصنف فقط |
| ready+ | × | إلغاء صنف-بصنف فقط |

---

## قائمة الـ commits

| Commit | الوصف |
|---|---|
| `e1b6d45` | hide invoice CTA once every received unit is invoiced |
| `f0d2961` | promote invoice CTA + explain batches page |
| `02338dc` | searchable ingredient combobox per line |
| `78bb3ac` | seeded supplier/ingredient/unit selects pick their option |
| `81432b5` | pay-modal amount input shows 2 decimals not 4 |
| `648c154` | trim quantity displays from 4 decimals to clean 2 |
| `ebd6b93` | stamp branch_id on movements via location/source chain |
| `f93397d` | stamp branch_id on receipt-generated batches |
| `5e188fd` | auto-generate SKU on create, hide field from form |
| `eb048f9` | tidy table layout — clearer header + tooltip warning |
| `e488f6f` | editable unit price + clear scenario explanation |
| `b058635` | populate items + branch-scope destinations |
| `d874b7d` | owner-level can resolve any record via URL |
| `1f7c5dd` | gate action buttons on PO state, not policy alone |
| `f4370d3` | branch-scope supplier dropdown in PO line builder |
| `d8d4df0` | stamp branch_id on PO create with owner-level fallback |
| `f467092` | wrap script in @script so Alpine sees the function |
| `0d6160e` | repair truncated breadcrumb tag (suppliers) |
| `d23bc1b` | reorder المخزون والمشتريات menu by workflow stages |
| `459b44a` | repair truncated breadcrumb tag (storage-locations) |
| `669eb87` | explain stock-inflow paths on location detail |
| `6b8bffe` | stamp branch_id on storage-location create |
| `766f3d7` | add create/update/delete methods to InventoryPolicy |
| `8fbfa5c` | admin-only mutations + clearer location copy |
| `4f96266` | load @livewireScripts so Alpine bootstraps |
| `6cfdf77` | explain inventory consumption + split sale vs waste |
| `4a85bc0` | explain margin + add plain-Arabic KPI legend on P&L |
| `36fc665` | plain-Arabic copy for the per-branch costing toggle |
| `5dc23fe` | block bulk-cancel after kitchen fires (admin + customer) |
| `8e8bb07` | show cancellation reason on cancelled order items |
| `c44e354` | single-page guarantee + luxury restaurant design |
| `75cb372` | side-toasts, header polish, customer cancel rule, A4 QR print |
| `2376b9c` | redesign hero stat cards + load bootstrap-icons for diners |
| `a06249a` | flow nested chevron + open child to RTL-natural side |
| `46da325` | correct nested submenu chevron direction in RTL |
| `1e0b0ff` | stack branch cards below comparison table to fill gap |
| `05ac4fb` | scope overview + live-monitor to user's branches |

---

## فلسفة عامة استُخلصت من الجلسة

1. **النظام مقصود يكون مُحاسبياً سليم** — لا توجد أزرار "إضافة كمية مباشرة" قصداً. كل حركة مخزون من **مصدر موثَّق** (PO، فاتورة، نقل، جرد، هدر).

2. **مسارات البضاعة الفعلية ≠ مسارات المالية**:
   - الدفعات (batches) تتعقّب البضاعة
   - فواتير الموردين والمدفوعات تتعقّب الذمم
   - تربطهما `purchase_order_id` لكنهما مستقلان عن قصد

3. **Owner-level ≠ Cross-branch by default**: السوبر أدمن "يدخل" فرعاً عبر الـ switcher → الـ UI يتعامل معه كمستخدم فرع. الاستثناء الوحيد: route binding مباشر لسجل (`/admin/x/{id}`).

4. **الواجهة بالعربي الواضح**: لا COGS ولا P&L ولا dieser. مصطلحات المستخدم العادي مع شرح صريح متى يحتاج.

5. **حسب الحالة، لا حسب الصلاحية فقط**: الأزرار تختفي/تظهر بناءً على state machine للسجل، حتى للسوبر أدمن. الصلاحية تجيب "من يقدر"، الحالة تجيب "ماذا منطقي الآن".

---

## الجلسة الثانية — إصلاحات بنيوية + تحديث UX شامل

سجل الجلسة الموسَّعة بعد الـ commits الأولى. التركيز هنا على **إصلاح أسس قاعدة البيانات** المتعلقة بالمخزون متعدد الفروع، **إعادة تصميم شاملة** لشاشات أساسية، و**حماية متعددة الطبقات** للعمليات الحساسة.

### ١. مشكلة الـ phantom stock (الأخطر)

**العَرَض**: الإجمالي العام للمكون 35,000 لكن عرض الفروع يجمع 20,000 — فرق 15,000 وحدة "خيالية".

**السبب**: `MenuSeeder` كان يكتب `current_stock` مباشرة بدون إنشاء صفوف `ingredient_stock`. أي حركة لاحقة (PO/تحويل) تكتب في الجدولين، لكن المخزون الأولي موجود فقط في العامود العام.

**الإصلاح على 3 طبقات**:
1. **بيانات**: نقلت 236,300 وحدة "خيالية" من 15 مكوّن إلى `ingredient_stock` في موقع التخزين الافتراضي
2. **الكود ([app/Services/InventoryService.php](app/Services/InventoryService.php))**: `recordMovement` صار يستنتج `current_stock` من `SUM(ingredient_stock.quantity)` بعد كل حركة. لا انحراف ممكن
3. **`resolveFallbackLocationId()`** — لو الـ caller ما مرّر موقع، يبحث في chain: branch من المرجع → default → first active. لا حركة تكتب بـ `null`
4. **حماية في الـ view** ([resources/views/admin/ingredients/index.blade.php](resources/views/admin/ingredients/index.blade.php)): "كل الفروع" يستخدم `Ingredient::trackedStock()` (مباشرة من `ingredient_stock`) — لا يعتمد على `current_stock` المخزَّن
5. **Seeder fix**: المخزون الأولي يُكتب الآن في `ingredient_stock` مع موقع تخزين افتراضي

**Helpers جديدة على Ingredient**:
- `trackedStock()` — مجموع كل المخزون من `ingredient_stock`
- `valueAtBranch($branchId)` — `stockAtBranch × costAtBranch` 
- `trackedValue()` — Σ `valueAtBranch` لكل فرع نشط (وليس `totalStock × globalCost` الذي كان يُسبب فجوة 852 ₪ من دواعي صدر دجاج)

### ٢. ميزات قسم المخزون

**`/admin/ingredients`**:
- شارة الحالة: 🟢 صحي / 🟡 منخفض / 🔴 نفد (3 حالات منفصلة)
- KPI cards قابلة للنقر للفلترة + تأشير "نشط"
- زر تصدير Excel (.xlsx حقيقي عبر PhpSpreadsheet — البـ CSV+BOM فشل على Excel العربي)
- Banner لقيمة المخزون الإجمالية بالـ branch context النشط

**`/admin/ingredients/create`**:
- **branch+location إجباريان دائماً** (مع كمية أولية اختيارية = 0)
- قسم "كمية البدء" بعد الاسم مباشرة (يحدد مصدر الموردين والموقع)
- supplier dropdown يُفلتر تلقائياً حسب الفرع المختار (عبر `branch_supplier` pivot) — يُمسح المورد لو لا يخدم الفرع الجديد
- لو qty=0 → يُسجّل صف `IngredientStock` بـ qty=0 (بيت افتراضي للمكون لـ POs لاحقة)
- لو qty>0 → يُسجَّل عبر `InventoryService::recordMovement` → يُنشئ صف + audit trail

**`/admin/storage-locations`**:
- redesign للكاردات (gradient header، watermark، badges، hover lift)
- removed dark transition strip — gradient يبقى ضمن لون الموقع نفسه
- **`/admin/storage-locations/transfer`** كان معطّل (livewire stub غير موجود) → أُعيدت كتابتها بالكامل بـ Alpine + الـ controller الموجود
- صار يحترم BranchScope (أُزيل `BranchContext::unscoped()` غير الضروري)

### ٣. تحويلات بين الفروع — حماية كاملة

**`/admin/branch-transfers/create`**:
- المكونات مفلترة حسب فرع المصدر (`stock > 0` فقط)
- الكمية المتاحة معروضة ضمن كل اختيار في الـ dropdown
- 3 حالات للبنود:
  - 🟢 **OK** — قابل للنقل
  - 🟡 **WARN** — سيُفرغ المخزون أو سيهبط تحت reorder threshold
  - 🔴 **ERROR** — يتجاوز المتاح (يُعطّل الزر)
- تكلفة تقديرية لكل بند بسعر الفرع المصدر
- ملخص الإجمالي + قيمة + عدد البنود الصالحة
- زر "انقل الكل" بضغطة لأقصى متاح
- إذا الفرع الوجهة بلا مواقع تخزين → block + رابط لإنشاء موقع
- backend safety net: لو `to_location_id` فاضي عند `receive()` → fallback لموقع الفرع الافتراضي

### ٤. الموردون متعددو الفروع — حماية متينة

**ثغرة authorization**: مدير غزة كان يقدر يـ POST `branch_ids[]=1` ويربط مورداً بخانيونس. الـ validation كان `exists:branches,id` فقط.

**الإصلاح ([SupplierController.php](app/Http/Controllers/Admin/SupplierController.php))**:
- `filterToAccessible()` — يُحذف كل branch_id خارج صلاحية المستخدم بصمت
- `update()` — لـ non-owner: diff داخل النطاق فقط. attach/detach يحدث فقط للفروع المسموحة. الفروع الأخرى تبقى تماماً كما هي
- `visibleBranches()` — في الـ form يُعرض كل الفروع، لكن غير القابلة للتعديل تظهر:
  - معطّلة بصرياً (disabled checkbox)
  - 🔒 lock icon
  - tooltip "خارج نطاق صلاحيتك"
- مدير غزة الآن لا يقدر بأي طريقة (UI أو POST مباشر) يربط/يفك مورد عن فرع خارج صلاحيته

**حقول shipping محسّنة**:
- مهلة التوريد، شروط الدفع، أقل قيمة طلب — كل واحدة بـ tooltip + شرح تفصيلي تحت الحقل (مع icons و amounts)
- أيام التوصيل bekommen ما زالت checkbox-grid لكن مع `m-0`, `flex-shrink-0`, padding متّسق

### ٥. Multi-branch consistency

**Lookup zones** صارت global (كانت per-branch بـ `PER_BRANCH_GROUPS`):
- المستخدم: "المناطق ثوابت يعني المفروض تظهر للفرعين"
- نقلت 2 zones من branch_id=1 لـ NULL، حذفت `'zones'` من PER_BRANCH_GROUPS

**Livewire branch context fix**:
- `Livewire::addPersistentMiddleware([SetActiveBranch::class])` في AppServiceProvider
- بدون هذا: عند wire:click في صفحة التطبيقات، Livewire يصل بدون BranchContext → `Lookup::for('zones')` يبحث عن `branch_id=null` → 0 نتائج → الـ zones chips تختفي

**SetActiveBranch + BelongsToBranch::resolveRouteBinding** صارا يستخدمان `Auth::guard('web')->user()` صراحة (بدل `auth()->user()` الذي يرجع Customer على portal routes — ينتج `BadMethodCallException::isOwnerLevel()`)

**Tables board على "كل الفروع"**: شارة فرع على كل كارد (gradient أخضر، blur، icon `bi-building`) يميّز طاولة #1 خانيونس عن #1 غزة بنظرة. مخفية في وضع فرع محدد (لتجنب noise).

### ٦. Modern UI — SweetAlert2 توحيد كامل

**استبدال جذري** للـ confirm/alert/toast:
- `confirm('حذف؟')` → SweetAlert2 modal مع "نعم، تأكيد" / "إلغاء"
- Bootstrap toast → SweetAlert2 toast at `top-end` (يمين على RTL)
- 40 view بـ `onsubmit="return confirm(...)"` تشتغل تلقائياً بدون تعديل ملف واحد

**Form-confirm interceptor** ([resources/views/admin/partials/toast.blade.php](resources/views/admin/partials/toast.blade.php)):
1. يستخرج النص من `onsubmit="return confirm('...')"`
2. يحذف الـ attribute لمنع تكرار النافذة الأصلية
3. يُظهر Swal modal
4. عند التأكيد: `data-confirm-handled="1"` ثم `requestSubmit()`

**APIs موحَّدة**:
- `window.showToast(message, variant)` — توست
- `window.showNotification(title, body)` — backward compat لـ Livewire
- `window.confirmAction({ title, text, icon })` — Promise-based modal

### ٧. شاشات المحطات (kitchen/bar/...)

- **soften الأحمر**: المحطات بألوان مختلفة (kitchen=#ef4444). كان الـ gradient يخلط بالأخضر العام → "وحل" أسود في المنتصف. الآن لون المحطة solid + slate wash 18% للهدوء البصري + white highlight بالزاوية
- **الأيقونات الناقصة**: bootstrap-icons المحلية بلا `bi-fire` و `bi-cup-hot` → استبدلت بـ `bi-lightning-charge-fill` و `bi-cup-straw`
- **الأزرار في header**: frosted-glass بدل أسود شفاف. السلطون الصوت بـ pill كبير بنص "تفعيل الصوت" / "الصوت يعمل"

### ٨. الزبون portal

**`/portal/`**:
- إضافة قسم "طلبات نشطة" + "طلباتك السابقة" (كان يعرض الحجوزات فقط)
- دمج 4 إحصاءات للطلبات (إجمالي/نشط/مكتمل/إلخ)

**`/portal/order/history`**:
- Hero strip بـ CTA "اطلب الآن" بارز (المستخدم: "خلي في الهيدر طلباتي ومن خلالها يكون زر اطلب الآن")
- 3 stats: إجمالي/قيد التنفيذ/إجمالي ما أنفقته
- شارة المصدر بألوان: 🟢 في المطعم / 🟡 استلام · من التطبيق / 🔵 توصيل · من التطبيق
- الـ pill يُظهر رقم الطاولة لـ dine-in ("في المطعم · طاولة 5")

**`/portal/order/{id}/checkout`** — تصميم احترافي كامل:
- step indicator (السلة ✓ / إتمام الطلب 2 / التأكيد 3)
- Hero gradient أخضر مع متا (عدد أصناف + إجمالي + أيقونة)
- Type cards كبيرة (استلام/توصيل) مع علامة ✓ متحركة
- Customer card بـ avatar (أول حرف من الاسم)
- Order summary بـ thumbnails للأصناف + modifiers chips
- Submit split: نص + سعر في chip
- Trust signals: "الدفع نقدي" + "طلبك محفوظ ومتتبَّع"
- رسائل تحقق عربية (`required_if`, `after`, `max`...)
- العملة من config (`₪` بدل `ر.ع`)

**`/portal/order/1`** — منيو احترافي مع AJAX:
- Hero gradient + meta strip
- Quick-jump nav للأقسام (chips قابلة للسكرول مع counter)
- **زر toggle لكل قسم** (إخفاء/إظهار) مع localStorage لحفظ الحالة
- Item cards: media 160px + badges (`featured`/`prep_time`) + modifiers + allergens chips
- زر `+` يدور 90° عند hover (microinteraction)
- **AJAX add/remove** بدون reload — Alpine state يتحدّث فوراً، Toast confirmation
- Cart bar طافٍ:
  - desktop: 380px يمين الشاشة
  - mobile: full-width مع safe-area-inset-bottom (iPhone X+)
  - `<details>` element للطي/الفتح
  - زر checkout كبير (56px ارتفاع على الموبايل)

**Order auto-complete**:
- `BillingService::recordPayment()` كان يكمل الطلبات للـ dine-in فقط (`closeOrdersAndSession`). الـ portal orders (takeaway/delivery) تبقى عالقة على "تم التسليم" بعد الدفع
- إصلاح: لو `invoice->order_id` وما عنده `table_session_id` → يُكمَل الطلب مباشرة (`OrderStatus::Completed`)
- backfill للطلبات العالقة موجوداً

### ٩. Live Monitor `/admin/live-monitor`

إعادة تصميم كاملة (TV-mode for owner):
- خلفية داكنة بـ radial gradients ذهبي + أخضر + slate
- Header sticky مع broadcast logo + clock + "مباشر" pill بـ pulsing dot
- 4 hero KPI cards بـ glassmorphism + decorative blob (لون مختلف لكل واحدة)
- Branch columns:
  - Top accent bar (hue per branch)
  - Avatar dot (أول حرف، gradient)
  - Hero sales card ذهبي
  - 3-col KPI strip (orders/tables/avg)
  - Capacity bar مع color-coding (normal/high/full)
  - Tables mini-grid (`auto-fill 38px`، 4 ألوان للحالات)
  - Tables legend (متاحة/مشغولة/محجوزة/خارج الخدمة)
  - Recent orders feed مع status pills + slide-in animation
  - Empty state ودود
- Currency من config — تطبيق أوسع للـ ₪

### ١٠. ميزات أخرى ضمن هذه الجلسة

- **Storage location auto-fallback**: عند تعديل طاولة من admin إلى `available` بينما عليها orphan session → تُغلق الجلسة تلقائياً (إذا 0 طلبات)
- **زر "إغلاق الجلسة الراكدة"**: يظهر على كارد الطاولة عند تحذير "جلسة طويلة" (75+ دقيقة) وبدون طلبات
- **Modifier groups preview**: كان مجرد chips فارغة → عرضت رسالة "أضف خيارات" + رابط لشاشة الإدارة (تم تجاوزه لاحقاً للعودة لتصميم الـ chips)
- **CSS class polish**: `.form-section`, `.toggle-card`, `.chips-panel`, `.chip-check`, `.recipe-row`, `.image-upload`, `.btn-add-recipe` كلها كانت معرَّفة في markup بدون CSS — أُضيفت inline في كل view محتاج
- **Auto branch + location على إنشاء المكون**: قائمة منسدلة محسَّنة، dropdown موقع التخزين يفعّل/يعطّل حسب الـ qty
- **Excel `sep=,`**: كان مكسور على Windows العربي. تحول لـ `.xlsx` حقيقي عبر PhpSpreadsheet — نتيجة 100% توافق

### ١١. أنماط مكررة ظهرت في الجلسة

| النمط | الحل |
|---|---|
| `auth()->user()` على routes متعددة الـ guards | استخدم `Auth::guard('web')->user()` صراحة |
| Livewire requests تخسر BranchContext | `Livewire::addPersistentMiddleware()` |
| `BranchContext::unscoped()` ينجو من فلترة BranchScope حيث لا يجب | احذفه — اترك BranchScope يحترم الـ context النشط |
| CSS classes مكتوبة بدون تعريف | أضف `<style>` block scoped في الـ view |
| RTL `top-end` في SwAl يقلب لـ يسار | استخدم `top-end` (في RTL = يمين) — مع `dir="rtl"` على html |
| Bootstrap-icons مفقودة | استبدل بأيقونات موجودة في الـ font |
| Floating bar `position: fixed` يُزاح بسبب RTL scroll | `html, body { overflow-x: hidden }` + `min-width: 0` على الـ flex parent |

### ١٢. توصية للجلسات القادمة

عند فتح أي صفحة جديدة بمشاكل بصرية:
1. **تحقق من المسبب**: أحياناً CSS classes غير معرَّفة (افتح DevTools)
2. **اختبر RTL flow**: scrollX السلبي ينتج عن أي طفل يتجاوز container
3. **احترم BranchContext**: لا تستخدم `unscoped` إلا للتقارير عبر-فروع
4. **استخدم helpers الموحدة**: `trackedValue`, `valueAtBranch`, `Ingredient::trackedStock()` لتجنب drift
5. **العملة من config**: `config('restaurant.currency_symbol', '₪')` — لا تكتب نصاً قاسياً
6. **رسائل العربية**: لا تعتمد على Laravel default messages — مرّر `messages` array لـ `validate()`
7. **مكونات Livewire**: تحتاج `@livewireScripts` push على الصفحات التي تستخدم Alpine المعتمد على Livewire

---

## الجلسة الثالثة — خصومات الكاشير + تقرير P&L كامل + إعلانات للزبائن

تركيز هاي الجلسة على **3 ميزات business-level** متكاملة: خصم يدوي من الكاشير مع audit
وحدود لكل دور، تقرير أرباح وخسائر شامل (شاشة + ملف طباعة فاخر بالعربي)، ونظام
حملات تسويقية موجَّه لزبائن البوابة.

### ١. خصم الكاشير (Cashier-applied Discount)

**المشكلة:** الـ schema للخصومات (`order_discounts`) موجود لكن بدون UI ولا
controller — الكاشير لا يقدر يعطي زبون خصم على فاتورة مفتوحة.

**الحل (يطبَّق على portal orders + dine-in sessions):**

- **Migration**: إضافة عمودَي `category` و `reason` للـ audit، ثم لاحقاً
  promotion إلى `category_lookup_id` (FK لـ `lookups` group=`discount_categories`)
  ليصير قابلاً للتعديل من شاشة الثوابت بدون redeploy.
- **Service**: [`OrderDiscountService`](app/Services/OrderDiscountService.php)
  - `applyToOrder` — خصم على طلب standalone (portal/takeaway)
  - `applyToSession` — خصم على جلسة dine-in (يفان-آوت لكل طلب، prorated للـ fixed)
  - `remove` — مسموح فقط طالما الفاتورة مش مغلقة
  - `userCap()` — يقرأ السقف لكل دور من config + Settings (override)
  - `syncInvoiceFromOrder/Session` — يحدّث snapshot الفاتورة بعد إعادة الحساب
- **Validation chain**: type/value/reason إجباريين، النسبة ≤ 100%، السقف per-role
- **Caps** (في `config/restaurant.php`):
  - cashier: 10% أو 5 ₪
  - waiter: 5% أو 3 ₪
  - manager: 25% أو 50 ₪
  - admin: عملياً بلا حد
  - super_admin/partner: uncapped (`userCap()` returns null)
- **Permissions**: `discounts.apply` و `discounts.remove` (cashier + manager + admin)
- **Policy**: [`OrderDiscountPolicy`](app/Policies/OrderDiscountPolicy.php) — branch-scoped
- **UI** (في Livewire `⚡dashboard.blade.php` + partial `_discount-panel.blade.php`):
  - panel ينفتح inline تحت totals card، مش modal منفصل
  - dropdown التصنيف يقرأ من `Lookup::for('discount_categories')` (FK → label + color)
  - حقل سبب إجباري + اسم اختياري
  - عرض السقف فوق الـ form: "حد دورك: حتى 10% أو 5 ₪"
  - rejection برسالة عربية واضحة عند تجاوز السقف
  - locked banner لو الفاتورة مغلقة (paid/cancelled)
- **Activity Log**: `order.discount_applied`, `order.discount_removed`, `session.discount_applied`

**Tests verified:**
- ✅ تطبيق 10% خصم على طلب 7.50 ₪ → totals 7.83 ₪ (تاكس بعد الخصم)
- ✅ محاولة 25% للكاشير → "الحد الأقصى لدورك 10%"
- ✅ إزالة → الإجمالي يرجع 8.70 ₪
- ✅ بعد دفع الفاتورة: زر الإضافة يختفي، رسالة قفل تظهر

### ٢. تقرير الأرباح والخسائر (P&L) الشامل

**المشكلة:** ما في شاشة وحدة عند المدير تجمع الإيراد + COGS + الخصومات + المصروفات
+ العمولات → صافي الربح. الموجود سابقاً كان يعرض جزءاً ويترك الباقي على شاشات منفصلة.

**الحل:**

- **Service**: [`ProfitLossReport`](app/Services/Reports/ProfitLossReport.php)
  - مصدر واحد للحقيقة، تستهلكه الشاشة + Excel + ملف الطباعة
  - تصفية الفترة دقيقة وموثَّقة:
    - الفواتير: `issued_at`
    - المصروفات: `expense_date`
    - الاستردادات: `refunded_at`
    - الهدر: `occurred_at`
  - معادلات يستحضرها sectional output: sales / costs / profit / discounts / expenses_breakdown / trend / top_items
- **سلّم الربح**:
  ```
  gross_sales − discounts = net_sales
  net_sales − cogs − waste = gross_profit
  gross_profit − expenses − platform_commission = operating_profit
  operating_profit − refunds = net_profit
  ```
- **شاشة** [`profit-loss.blade.php`](resources/views/admin/reports/profit-loss.blade.php):
  - 4 hero KPI cards (صافي المبيعات، الربح الإجمالي، إجمالي التكاليف، صافي الربح)
  - waterfall للـ income statement مع tooltip ⓘ على كل بند
  - مخطط trend يومي (Chart.js)
  - تفصيل الخصومات حسب التصنيف + أعلى موظف منح خصومات
  - تفصيل المصروفات حسب التصنيف + طريقة الدفع
  - أعلى ١٠ أصناف ربحاً
  - glossary بـ `<details>` يشرح كل مصطلح
- **ملف طباعة فاخر** [`profit-loss-print.blade.php`](resources/views/admin/reports/profit-loss-print.blade.php):
  - **HTML** (مش DomPDF) — المتصفح هو الـ PDF generator، يدعم العربي بشكل أصيل
  - الـ hero يطابق `pm-hero` في `/portal/order` تماماً (نفس gradient + layout)
  - action bar فوق الورقة كـ card أبيض، مخفي عند الطباعة
  - استبدال "−" بـ "يُطرح:" لقراءة عربية أوضح
  - Glossary عربي كامل + سطر تواقيع (أعدّ / راجع / اعتمد)
  - `print-color-adjust: exact` تجبر المتصفح على طباعة الخلفيات (خلاف الافتراضي)
- **Routes جديدة**:
  - `admin.reports.profit-loss` (الشاشة)
  - `admin.reports.profit-loss.export.xlsx` (Excel)
  - `admin.reports.profit-loss.export.pdf` (HTML قابل للطباعة)

**Print pagination fix**: كان `.stmt page-break-inside: avoid` يدفع الـ income
statement كله للصفحة التالية ويترك فراغاً ضخماً تحت الـ KPIs. الحل: نقّلت `avoid`
على `.stmt__row` نفسه فقط (الصف لا ينقسم) بدل البلوك الكامل، فالمحتوى يتدفّق طبيعياً.

### ٣. نظام إعلانات للزبائن (Layer 1 — In-App)

**المشكلة:** الكاشير ينعم على الزبون بخصم بس الزبون ما يشوفه، وما في طريقة لإعلام
الزبائن الدائمين بعروض أو حملات تسويقية.

**الحل (طبقة أولى من 3):**

- **Migration**: [جدول `announcements`](database/migrations/2026_05_09_180000_create_announcements_table.php)
  - branch_id (nullable = global), title, body, image, icon, color
  - cta_text, cta_url, discount_id (nullable FK)
  - audience_type: `all|tier|inactive_days|specific` + audience_filter (JSON)
  - schedule: starts_at, ends_at
  - status: `draft|scheduled|published|expired|archived`
- **Model**: [`Announcement`](app/Models/Announcement.php)
  - scopes: `activeNow()`, `forCustomer($customer)`
  - `isCurrentlyLive()` يشيك schedule + status معاً
- **Service**: [`AnnouncementService`](app/Services/AnnouncementService.php)
  - `buildAudience()` — يحوّل الفلتر لـ Customer Collection
  - `publish()` — في transaction: يفان-آوت notification لكل زبون مطابق + يحدّث `recipients_count`
  - `unpublish()` و `expireOverdue()` للـ housekeeping
- **Notification**: [`AnnouncementForCustomer`](app/Notifications/AnnouncementForCustomer.php)
  - يرث من `BaseNotification` ليستخدم نفس `notifications` table + DatabaseChannel
- **Permissions**: `announcements.viewAny|view|create|update|publish|delete`
- **Policy**: [`AnnouncementPolicy`](app/Policies/AnnouncementPolicy.php) — branch-scoped

**Admin UI**:
- `/admin/announcements` — قائمة بـ stats + فلاتر + جدول
- `/admin/announcements/create|edit` — form تفاعلي مع:
  - معاينة حية (sticky يمين) كما يراها الزبون
  - عدّاد الجمهور المتوقع
  - 4 أنواع جمهور (الكل / tier / inactive_days / specific)
  - جدولة + اختيار صورة + لون + أيقونة
  - **Branch scope بحسب الدور** (راجع النمط ٦ تحت)
- زر نشر بـ confirm → يفان-آوت الإشعارات
- زر أرشفة → يخفي البانر بدون حذف الإشعارات السابقة

**Portal UI (الزبون)**:
- 🔔 جرس في الـ navbar مع gold-pulse counter للغير-مقروءة
- `/portal/notifications` — inbox فيه:
  - بانر علوي بإحصائيات + زر "اعتبارها كلها مقروءة"
  - بطاقة لكل إشعار: gold dot للغير-مقروء، أيقونة ملوّنة، CTA بلون الإعلان، زر mark-read
- Banner ديناميكي على `/portal/dashboard` يظهر تلقائياً لو في إعلان نشط
- mobile drawer يحتوي رابط "الإشعارات والعروض" مع badge ذهبي

**النمط ٦ (جديد): Branch-scope guard لـ broadcast actions**

أي action ينشر شيئاً عبر فروع متعددة لازم يكون له **server-side guard** يعيد كتابة
`branch_id` المُرسَل لقيمة مسموحة، **لا يعتمد فقط على الـ UI**:

```php
// في validateAnnouncement()
if (! $this->canPostGlobal($user)) {
    $accessible = $user->branches()->pluck('branches.id')->all();
    if (! $submitted || ! in_array((int) $submitted, $accessible, true)) {
        $data['branch_id'] = $user->primaryBranch()?->id ?? ($accessible[0] ?? null);
    }
}
```

السبب: الـ `BasePolicy::before()` يعطي owner-level pass تلقائي، والـ HTML form
ممكن يُتجاوز بـ POST يدوي. صلاحية النشر العالمي مقصورة على `isOwnerLevel()` و
`isAdmin()` فقط — المدراء (manager) مقفولون على فرعهم بـ hidden input + guard.

### ٤. تقرير الطباعة المتطابق مع `/portal/order` hero

**سلسلة تعديلات** على `profit-loss-print.blade.php` بناءً على feedback مرئي:

1. **حذف الدائرة الزخرفية** (`::before`) من الـ brand header
2. **حذف الشريط الذهبي المتقطع** (`::after`) من الأسفل
3. **action bar** صار card أبيض أنيق بدل شريط أخضر داكن sticky
4. **Print pagination fix**: نقّل `page-break-inside: avoid` من `.stmt` (block كامل)
   إلى `.stmt__row` (الصف الواحد) — حلّ الفراغ الضخم بعد الـ KPIs
5. الـ hero ضل بنفس gradient الـ portal `pm-hero` (`#0f4731 → #1c5e44`)

### ٥. أنماط معمارية ظهرت في الجلسة

| النمط | المثال |
|---|---|
| **Service-first** | كل عملية محاسبية لها Service مع DB transaction (OrderDiscountService, AnnouncementService, ProfitLossReport) |
| **Branch-scope guard في الـ controller** | لا تثق بالـ UI — أعد كتابة branch_id من الـ user role |
| **Polymorphic notifications للـ Customer** | نفس جدول `notifications` للموظفين والزبائن، كل واحد يفلتر بحسب `notifiable_type` |
| **Print = HTML** | DomPDF ضعيف بالعربي — اطبع HTML من المتصفح، اللغة بتطلع صح |
| **Lookup-driven dropdowns** | بدل enum hardcoded في الكود، خلّيها FK لـ `lookups.group` يعدّلها الأدمن |
| **page-break-inside على الصف، لا على البلوك** | يمنع كسر السطر بدون ما يدفع البلوك كله لصفحة جديدة |

### ٦. إصلاحات صغيرة بنفس الجلسة

- **Login portal redesign**: نسخة طبق الأصل عن `/login` الإداري بالـ split + animated blobs،
  فقط `username` → `identifier` (هاتف أو إيميل)، أيقونة `bi-person-fill`، نص الزر
  "تسجيل الدخول"، إضافة "ليس لديك حساب؟ إنشاء حساب الآن". الـ "نسيت كلمة المرور"
  انحذف بناءً على طلب المستخدم.
- **Portal navbar mobile drawer**: hamburger بـ overlay + drawer من الـ start side،
  body-scroll-lock، يقفل بـ ESC أو overlay click أو رابط داخلي. badge ذهبي
  للإشعارات غير المقروءة داخل الـ drawer.
- **Expense action buttons**: زر اعتماد/رفض/تعديل صار يختفي على الصفوف المعتمدة
  مسبقاً. نفس النمط ٤ — الـ `BasePolicy::before` يبايبس owner-level، فلازم نفحص
  `$exp->isPending()` في الـ view بالإضافة للـ `@can`.

---

## الجلسة الرابعة (2026-05-10) — التدقيق الأمني الشامل + إصلاحات Round 1-4

تركيز هاي الجلسة: **تدقيق أمني عميق على 4 محاور** (security / multi-branch /
data integrity / performance) ثم **تنفيذ منهجي** للإصلاحات الأقل خطورة وأعلى
تأثيراً، مع تأجيل التغييرات الكبيرة لجلسة منفصلة.

### ١. التدقيق الأمني الشامل (4 agents متوازية)

أطلقت 4 agents تخصصية فحصت كل ملفات النظام وأنتجت **69 finding** موزّعة:
- 12 Critical · 31 High · 26 Medium

**أعلى 3 مخاطر اكتشفت:**
1. **سلسلة خطف هوية الزبون عبر QR** — track/signup يربط بأي رقم هاتف بدون OTP
2. **`BasePolicy::before` يلغي state checks** — Owner يقدر يعدّل فاتورة مدفوعة
3. **5 جداول مالية بدون `branch_id`** (Payment, Refund, OrderDiscount, InvoiceSplit, CashMovement)

### ٢. الإصلاحات المنفذة (Round 1-4)

#### Round 1 — أمن (6 إصلاحات)
1. **`BasePolicy::before` documented** — كشف إن الـ services نفسها فيها state guards
   (StockCountService::finalize، BillingService::addPayment) فالخطر مش بالحجم
   اللي ظهر في التدقيق. وثّقت الفلسفة: **policies = "من؟"، services = "هل state يسمح؟"**
2. **`throttle:5,1` على /login و /portal/login** — يقفل brute-force على الـ 6-digit PIN
3. **Lookup cache: `Cache::driver('array')` → `Cache::remember()` افتراضي** —
   كان يضيع بعد كل request، الآن cross-request حقيقي. مع `forget()` المحدّث
   يفلت كل branches من DB بدل hardcoded `range(1, 50)`
4. **LookupController يحدد `branch_id` server-side** من الـ group، مش من الـ POST
5. **AnnouncementController bug fix** — كان يستدعي `wherePivot('is_active', true)`
   على جدول `branch_user` اللي ما فيه `is_active` column أصلاً → يرجع فاضي →
   manager غير-owner كان يقدر ينشر إعلان عالمي بصمت. صار يستخدم `accessibleBranchIds()`
6. **ProfileController يطلب `current_password`** قبل تغيير الباسورد + UI field

#### Round 2 — تشديد صلاحيات (4 endpoints)
الـ controllers اللي كانت تكفيها `viewAny` (أي قارئ) صارت تطلب `manage`:
- WasteController::store (تسجيل هدر — كان كاشير يقدر يخفي سرقة)
- WasteLogger Livewire submit (نفس السبب)
- IngredientBatchController::store (إنشاء دفعات يدوية)
- StorageLocationController::transferStore (نقل بين مواقع)
- BranchTransferController::show (إضافة فحص `accessibleBranchIds`)

#### Round 3 — أداء
- **Migration `2026_05_10_180000_add_performance_indexes`**: 5 indexes جديدة
  - `payments(paid_at, invoice_id)` — dashboard cash today
  - `inventory_movements(branch_id, type, occurred_at)` — branch comparison
  - `ingredient_batches(expiry_date, remaining_qty)` — expiry alerts
  - `ingredients(active, track_stock)` — low stock scan
  - `orders(branch_id, created_at)` — order archive
- **`App\Support\SidebarBadges`** — view-composer ينشئ counts للـ 4 sidebar badges
  مرة واحدة بـ cache 30 ثانية (كان 4 queries × كل request)

#### Round 4 — Shift safety
- DB transaction + `lockForUpdate` على store + close (يمنع double-click + race conditions)
- Activity log لكل من open + close
- إنشاء `ShiftPolicy` (كانت مفقودة + auto-discovery كان يفشل) + register في AppServiceProvider

#### Round 5 — UX & Wiring (نفس الجلسة)
- **Sidebar:** أضفت رابط الورديات تحت "الحسابات"
- **Shifts screen redesign**: branch context chip، help banner مفتوح افتراضياً
  لما ما في شفت مفتوح، 3 بطاقات شرح (شو الشفت / كيف أبدأ / كيف أغلق)، كرت
  active shift بـ pulse animation، حماية وضع "كل الفروع"، جدول السجل ملوّن
  حسب فرق الكاش

#### Round 6 — User branch assignment guard (متضمن في نفس الجلسة)
- **`/admin/users/create` و edit** — مدير غزة كان يقدر يربط موظف بفرع خانيونس
- **3 طبقات حماية**:
  1. **UI**: out-of-scope branches تظهر معطلة بـ 🔒 + diagonal stripes
  2. **Server filter**: `extractBranchAssignments()` يفلتر `$request->branches`
     ضد `$accessibleBranchIds`، يرمي أي branch_id خارج النطاق
  3. **Audit log**: أي bypass attempt يتسجّل في activity_logs مع actor + branches
- **Edit مذكي**: يحفظ assignments الموظف لفروع خارج نطاق المعدّل (لا يمسحها)
- **اختبار مثبت**: submit `[2, 99]` بينما allowed=[2] → النظام يحفظ 2 فقط، يرمي 99، يسجل المحاولة

### ٣. الأنماط الجديدة المستخلصة

| النمط | المبدأ |
|---|---|
| **3-layer guard للـ multi-branch input** | UI disabled + server filter + audit log. الـ UI ممكن يتعدل، السيرفر هو الحارس الحقيقي |
| **`accessibleBranchIds()` على User** | الـ helper المركزي — يستخدمه كل controller بحاجة يفلتر بالفرع |
| **Defense in depth على fillable models** | `branch_id` لو فيه fillable، الـ controller لازم يحدده server-side، مش يثق بـ POST |
| **State checks في الـ services، مش الـ policies** | policy = من، service = هل يجوز الآن. هذا يخفف الضغط على `BasePolicy::before` |
| **Cache::driver vs Cache facade** | `Cache::driver('array')` للـ per-request فقط (يموت بعد response). للـ cross-request استخدم `Cache::remember` (default driver) |
| **Sidebar badges via view composer + cached** | 4 queries × كل request = هدر. helper مع cache 30s = نظيف |
| **Audit log على bypass attempts** | لا تكتفي بـ "silently drop". سجل المحاولة عشان لاحقاً تعرف من حاول |

### ٤. الإصلاحات المؤجَّلة (priority للجلسة القادمة)

#### 🚨 الأولوية القصوى — Cashier branch isolation (لازم بكرا)

5 جداول مالية لسه **بدون `branch_id`** ولا `BelongsToBranch` trait:
- `payments`, `refunds`, `order_discounts`, `invoice_splits`, `cash_movements`

**التأثير الفعلي المثبت:**
في `CashierController.php:39`:
```php
'cash_today' => Payment::whereDate('created_at', today())
                       ->where('method', 'cash')
                       ->sum('amount'),  // ← يجمع كل الفروع!
```

مدير غزة → يشوف "كاش اليوم: 5000 ₪"
مدير خانيونس → يشوف **نفس الـ 5000 ₪** (= مجموع الفرعين)

**الـ Shift نفسه آمن** (له branch_id بعد إصلاحات Round 4)، **لكن**:
- الاستعلامات اللي ما تمر بـ `shift_id` (الـ daily KPIs على الـ dashboard,
  تقرير الكاشير، Profit & Loss) كلها بتختلط بين الفروع

**خطة الإصلاح للجلسة القادمة:**
1. Migration واحدة تضيف `branch_id NOT NULL` على الـ 5 جداول
2. Backfill كل صف موجود من الـ Invoice/Order parent (فيه قواعد ذكية:
   - payment.branch_id ← invoice.branch_id (دائماً)
   - refund.branch_id ← invoice.branch_id
   - order_discount.branch_id ← order.branch_id (اللي لـ session) أو الفاتورة المنفردة
   - invoice_split.branch_id ← invoice.branch_id
   - cash_movement.branch_id ← shift.branch_id)
3. كل model يحط `use BelongsToBranch`
4. مراجعة الـ controllers/services اللي تكتب لهذه الجداول للتأكد إنها تمرر branch_id
   (أو الـ trait يسحبه auto من BranchContext للحالات اللي مش parent-derived)
5. **مراجعة كل KPI query** في `DashboardController`, `CashierController`,
   `ReportController` — أي query لـ Payment/Refund/OrderDiscount بدون فلتر فرع
   لازم يصير عندو فلتر صحيح
6. اختبار في فروع مختلفة للتأكد من الفصل (Smoke test: كاشير غزة يقفل شفت،
   مدير خانيونس يدخل dashboard لازم لا يشوف رقم غزة)

**تقدير الوقت:** ساعتين (الـ refactor كبير، الـ backfill حذر، الـ KPI review طويل)

#### 🔴 Critical الباقية (للجلسات اللاحقة)
- **A1 سلسلة Customer impersonation OTP** — يحتاج SMS gateway integration + قرار منتج
- **B1 Number generation race fix** — لـ 6 models (Order, Invoice, PO, Refund, StockCount, BranchTransfer): helper موحد بـ lockForUpdate أو try/catch QueryException → retry
- **B3 LocationInventoryService::recordLocationMovement** بدون قفل
- **B4 BillingService::cancelInvoice** TOCTOU
- **B6 Order state machine** — حالياً transitionTo يقبل أي transition (Pending → Completed يتجاوز خصم المخزون)

#### 🟠 High الباقية
- 20 موقع بـ `whereDate(col, today())` يلغي الـ indexes — استبدال بـ `whereBetween(col, [start, end])`
- N+1 في `branchComparison`, `reorderSuggestions`, `stockValuation`, `InventoryController::dashboard`

### ٥. ملاحظات تقنية مهمة (لا تنسها بكرا)

1. **`BasePolicy::before` ما اتغيّر** — كان عندي محاولة أحجبه على mutations لكن لقيت
   إن معظم الـ policies ما عندها `isOwnerLevel()` short-circuit في الـ per-method
   checks، فالتغيير بيكسر كل عمليات الـ owner. الـ services فيها state guards
   بالفعل، فالخطر مش بالحجم اللي ظنه التدقيق. **لو فكرت تشدد policies، ابدأ بـ
   إضافة `isOwnerLevel()` checks في كل policy method أولاً.**

2. **Lookup cache** صار default driver — لازم تتذكر `Lookup::forget('group')`
   عند CRUD على lookup. الكنترولر صار يعمل هذا، لكن لو في كود ثاني يعدّل
   lookups مباشرة (seeder, migration, tinker) لازم يعمل forget يدوي.

3. **`branch_user` pivot ما فيه `is_active` column** — أي كود يستخدم
   `wherePivot('is_active', ...)` بيرجع فاضي بصمت. استخدم `accessibleBranchIds()`.

4. **ShiftPolicy** صار موجود ومسجّل — لو ضفت Shift methods جديدة (mutations)
   تذكر تضيف `@can` checks.

5. **SidebarBadges cache** بـ 30 ثانية — لو عملت "create order" وما شفت الـ badge
   تحدث، استنى 30 ثانية أو امسح cache بـ `App\Support\SidebarBadges::bust()`
   من Order observer لو حبيت real-time.

6. **Performance indexes** الجديدة لازم تعمل migrate بعد pull. الـ migration
   idempotent — آمن re-run.

### ٦. ملفات تم تعديلها (للمرجع السريع)

```
app/Policies/BasePolicy.php                    — توثيق
app/Policies/ShiftPolicy.php                   — جديد
app/Models/Lookup.php                          — cache fix + forget update
app/Support/SidebarBadges.php                  — جديد
app/Http/Controllers/Admin/ShiftController.php — transactions + locks + audit
app/Http/Controllers/Admin/UserController.php  — branch assignment guards
app/Http/Controllers/Admin/LookupController.php — branch_id derivation + cache bust
app/Http/Controllers/Admin/AnnouncementController.php — wherePivot bug fix
app/Http/Controllers/Admin/ProfileController.php — current_password
app/Http/Controllers/Admin/WasteController.php — viewAny → manage
app/Http/Controllers/Admin/IngredientBatchController.php — viewAny → manage
app/Http/Controllers/Admin/StorageLocationController.php — viewAny → manage
app/Http/Controllers/Admin/BranchTransferController.php — branch ownership check
app/Providers/AppServiceProvider.php           — register ShiftPolicy
routes/web.php, routes/portal.php              — throttle on /login
resources/views/admin/users/_form.blade.php    — locked branches UI
resources/views/admin/profile/show.blade.php   — current_password field
resources/views/admin/shifts/index.blade.php   — full redesign
resources/views/admin/partials/sidebar.blade.php — shifts link + badges helper
database/migrations/2026_05_10_180000_add_performance_indexes.php — جديد
```

---

## 🚨 لجلسة الغد — مهمة واحدة فقط (لا تنحرف)

**Cashier branch isolation** — اقرأ القسم "الإصلاحات المؤجَّلة" أعلاه للخطة الكاملة.
البداية الموصى بها:

```
1. اعمل commit للشغل الحالي (Round 1-6) أولاً
2. branch جديد: cashier-branch-isolation
3. اعمل الـ migration (الـ 5 جداول + backfill)
4. اعمل اختبار في فرعين قبل ما تلمس أي controller
5. بعدها flag كل query في:
   - DashboardController
   - CashierController
   - ReportController (تقرير الكاشير، P&L)
6. Smoke test النهائي: افتح dashboard في فرعين بمستخدمين مختلفين
   تأكد إن الـ "كاش اليوم" لكل فرع يطابق ما في درجه الفعلي
```

---

## الجلسة الرابعة — تتبّع وقت التحضير + إصلاحات سلامة الجلسة + قناة إعلانات للضيوف

تركيز هذه الجلسة على **تجربة الزبون من الباب لبعد الأكل**: المطبخ يعرف كم لازم
ياخد كل صنف، الزبون يشوف عدّاد تنازلي حقيقي، المدير يفتح أرشيف الطلبات ويعرف
مين تأخّر وكم. + إصلاحات حرجة على تسريب جلسة الزبون والمحاسبة، + قناة إعلانات
جديدة كلياً لاستهداف الزوّار غير المسجَّلين.

### ١. نظام تتبّع وقت التحضير (Prep Time Tracking)

**المعادلة الأساسية:**
```
eta_minutes = max(item.prep_time_minutes حيث > 0) × (1 + buffer_pct/100)
```
- **MAX مش SUM** لأن المطبخ يطبخ بالتوازي — الزبون ينتظر الصنف الأبطأ.
- **استثناء `prep_time = 0`** — المشروبات والـ pre-made لا تدخل الحساب.
- **Buffer قابل للضبط** من `/admin/settings` (المفتاح `prep_time_buffer_pct`،
  افتراضي 20%، محدود بين 0-200% حتى ما يقول "جاهز بعد 4 ساعات" بسبب خطأ ضبط).

**Migration**: عمود جديد `prep_started_at` على `orders` بعد `approved_at`.
يُختم لحظة دخول الطلب لحالة `preparing` (لمّا يبدأ المطبخ أول صنف فعلاً).

**Service**: [`OrderTimingService`](app/Services/OrderTimingService.php)
- `estimateMinutes()` — يحسب الـETA بدقيقة كاملة (`ceil`)
- `stampPrepStart()` — Idempotent، يختم مرة واحدة فقط
- `remainingSeconds()` — للعدّاد التنازلي عند الزبون
- `elapsedMinutes()` — للمطبخ لمعرفة "كم صار له يطبخ"

**Hook في `OrderService::syncOrderStatus()`**:
```php
} elseif ($active->contains(fn($i) => $i->status === 'preparing')) {
    $order->update(['status' => 'preparing']);
    app(OrderTimingService::class)->stampPrepStart($order);
}
```

**شاشة المطبخ** (`_kitchen-card.blade.php`):
- بطاقة الطلب فيها شارة `12د` (وقت التحضير) لكل صنف
- لمّا يبدأ التحضير، شارة "منقضي 3د" تظهر بجانبها بألوان إشارة المرور:
  - ≥80% من الوقت → أصفر (تحذير)
  - ≥100% من الوقت → أحمر + نبض (overdue)
- شارة ETA على ribbon الطاولة بلون أزرق (`H:i` مثلاً 16:46)، تصير حمراء لو
  الوقت تجاوز

**شاشة الزبون** (`track-card.blade.php`):
- عدّاد تنازلي حي بـ Alpine تحت كرت "في المطبخ"
- كل 5 ثواني يحدّث + عند `livewire:morph.updated` لإعادة الربط بعد polling
- لمّا الوقت ينقضي ولسّه مش جاهز → "**جاهز قريباً...**" مع pulse (ما نعرض أرقام
  سالبة على زبون متوتر)

### ٢. أرشيف الطلبات مع تحليل التأخير

**شاشة `/admin/orders/archive` صار فيها**:
- **KPI rail ثاني** يظهر فقط لو في طلبات مقيسة:
  - عدد الطلبات المقيسة / وصلت في الوقت / متوسط الوقت الفعلي / متوسط الفرق
  - ألوان إشارة المرور: ≥80% أخضر، ≥50% أصفر، أقل أحمر
- **عمود "وقت التحضير"** في كل صف:
  - `12د → 18د` (متوقّع → فعلي) + شارة `+6د` بلون
  - أخضر `arx-var--good` لـ ≤0 delta
  - أصفر `arx-var--warn` لـ 1-5د تأخير
  - أحمر `arx-var--bad` لـ >5د تأخير
  - 3 حالات: مكتمل / لسّه قيد التحضير / ما دخل preparing (—)
- **فلتر "المتأخّرة فقط"** — toggle checkbox، auto-submit

**SQL ملحوظة حرجة (BIGINT UNSIGNED gotcha):**
```php
// خطأ — يرمي "Numeric value out of range" لو الطلب خلص قبل وقته
DB::raw('AVG(TIMESTAMPDIFF(MINUTE, prep_started_at, ready_at) - estimated_prep_minutes)')

// صح — CAST AS SIGNED حتى يقبل القيم السالبة
DB::raw('AVG(CAST(TIMESTAMPDIFF(MINUTE, prep_started_at, ready_at) AS SIGNED) - CAST(estimated_prep_minutes AS SIGNED))')
```

### ٣. إصلاحات حرجة على سلامة بيانات التوقيت

#### `stampPrepStart` كان يُختم على طلبات مكتملة

**العَرَض**: أرشيف الطلبات يعرض طلب بفعلي = -149د.

**السبب**: شخص في المطبخ لمس عنصر من طلب مكتمل (status=`completed`) فحرّكه لـ
`preparing`، وكان `prep_started_at = null`، فختمته الخدمة بـ`now()` — لكن
`ready_at` كان من ساعتين ونصف. النتيجة: `ready_at` قبل `prep_started_at` بمدة.

**الحارس**:
```php
if (in_array($order->status, ['ready', 'delivered', 'completed', 'cancelled'])) {
    return;   // never stamp on orders past the preparing phase
}
```

**التنظيف**: حذفت `prep_started_at` و`estimated_ready_at` من السجلات المصابة
(`whereColumn('prep_started_at', '>', 'ready_at')`).

**في الـ View**: أُعرض شارة `⚠ —` بدل القيمة السالبة، مع tooltip "بيانات غير
متّسقة". وفي تجميعات KPI أضفت شرط `where('ready_at', '>=', DB::raw('prep_started_at'))`
حتى ما يلوّث المتوسطات.

#### تسريب جلسة الزبون عبر كوكي `qr_customer_id`

**العَرَض**: زبون فهيم سجّل، طلب، عمل logout. أدمن فات على نفس المتصفح وفتح
رابط طاولة → الشاشة قالت "أهلاً فهيم".

**السبب**: 3 طبقات تشتغل معاً:
1. `CartController` يكتب كوكي `qr_customer_id=123` بمدة سنة كـ "soft remember-me"
2. `Portal\AuthController::logout()` كان يمسح الجلسة بس **يترك الكوكي حي**
3. `MenuController::open()` يقرأ الكوكي fallback ويربط الجلسة الجديدة بنفس
   الزبون

**الإصلاح من طبقتين**:
```php
// 1. logout يمسح الكوكي
return redirect()->route('portal.login')
    ->withCookie(Cookie::forget('qr_customer_id'))
    ->withCookie(Cookie::forget('table_session'));

// 2. حارس ثاني في MenuController::open() — لو موظّف مسجّل دخول، ما نطبّق
//    soft-remember-me أصلاً (الموظّف يختبر، مش بياكل)
$staffSignedIn = Auth::guard('web')->check();
if (! $portalCustomerId && ! $staffSignedIn && $cookieId = ...) { ... }
```

#### عداد الاستردادات مخفي في تقرير P&L

**العَرَض**: صفحة `/admin/reports/profit-loss` وملف PDF ما يظهروا سطر
"الاستردادات" أبداً، حتى صار شك إن النظام لا يحسبها.

**السبب**: السطر مخفي بـ`@if($costs['refunds'] > 0)` — في DB صفر استرداد، فما
يطلع شي.

**الإصلاح**: السطر يظهر **دائماً** حتى لو صفر (ممارسة محاسبية صحيحة — تقرير
P&L لازم يظهر كل بند صراحة)، مع class جديد `pl-row--muted` بلون رمادي خفيف
لمّا القيمة = 0 حتى لا يبدو كخسارة حقيقية.

### ٤. تحفيز تسجيل الزبائن — مسارات متعددة

**أ. كرت السلة (الموقع الأقوى للتحويل)** — الزبون فاتح السلة على وشك يطلب:
```
تطلب كضيف — بدون تسجيل
ارسل طلبك مباشرة. ما في خانات إجبارية ولا تأكيد رقم.

افتح حساب مرة وحدة، استفد بكل زيارة:
   • عروض وخصومات حصرية للزبائن المسجَّلين
   • سجل طلباتك السابقة وإعادتها بضغطة
   • نقاط ولاء تتحوّل لخصومات لاحقاً

[إنشاء حساب]  [عندي حساب]
```

**ب. شريط signup على شاشة تتبّع الطلب** — اللحظة الثانية المريحة (الزبون
ينتظر الأكل، الطلب راح):
- Hook عاطفي: "🎁 افتح حسابك بضغطة — وفّر بزياراتك الجاية"
- نفس قائمة الفوائد الـ3
- "أنشئ لي حساب" / "شكراً، مش الآن"

**ج. كرت sidebar تحت قائمة الأقسام** — تذكير خفيف للزبون الـguest.

### ٥. قناة إعلانات جديدة: `audience_type = 'guests'`

**المشكلة**: نظام الإعلانات كان يستهدف الزبائن المسجَّلين فقط (يكتب رسالة على
`notifications` table). الزوّار الجدد (طلب من QR بدون حساب) ما يقدر الأدمن
يحفّزهم برسالة.

**الحل (قناة عرض مختلفة كلياً)**: قناة جديدة لا تكتب notifications — تعرض
**بانر على شاشة المنيو** لأي جلسة anonymous.

**التغييرات**:
- `AnnouncementController::validateAnnouncement()` — أضفت `'guests'` لـ
  validation rule `in:...`
- `form.blade.php` — `<optgroup>` يميّز بين قناتي التوزيع:
  - "الزبائن المسجَّلون (إشعار في حسابهم)"
  - "الزوّار غير المسجَّلين (بانر على المنيو)"
  - + alert تنبيه أصفر يظهر عند اختيار `guests` يشرح: "قناة عرض مختلفة... لن
    يُرسَل كإشعار. عداد المستلمين يبقى صفراً"
- `AnnouncementService::buildAudience()` — short-circuit لـ`guests`:
  ```php
  if ($announcement->audience_type === 'guests') {
      return collect();   // no customer notifications
  }
  ```
- `Announcement` model — scope جديد `scopeForGuests(?int $branchId)`:
  ```php
  $q->where('audience_type', 'guests');
  if ($branchId !== null) {
      $q->where(fn ($w) => $w->whereNull('branch_id')
                              ->orWhere('branch_id', $branchId));
  }
  ```
  + helper `isGuestsAudience()` للتمييز في الـviews.

**Route + Controller جديد**:
```php
Route::post('/menu/dismiss-promo/{id}', [MenuController::class, 'dismissGuestPromo'])
    ->name('customer.menu.dismissGuestPromo');
```
- `dismissGuestPromo()` — يتأكد إن الإعلان موجود ونوع `guests` قبل ما يحط
  session flag (حماية من مخاطر الـ injection).

**عرض البانر** على `customer/menu.blade.php`:
- يُحضَّر في الـ`@php` block فقط لما `! $linkedCustomer`
- لون البانر يتبع `announcement.color` كـ `--promo-color` CSS variable
- زرّ CTA يستعمل `announcement.cta_url` لو موجود، وإلا يفولباك لـ
  `portal.register`
- زرّ × للإغلاق يحط session flag حتى لا يرجع نفس البانر بنفس الزيارة

**شاشة فهرس الإعلانات** — عمود "المستلمين" يعرض شارة "📢 بانر منيو" بدل `0`
لمّا `isGuestsAudience()`.

**ملاحظة UX مهمة**: الفلو يتطلب خطوة "نشر" منفصلة بعد الحفظ. الحفظ
يخلّي `status='draft'` (غير ظاهر للزبون)، النشر يفلب لـ`published`. الاختبار
الأول فشل لأن الإعلان كان draft.

### ٦. ملخّص الملفات المُعدَّلة

| الملف | الغرض |
|------|------|
| `database/migrations/2026_05_11_120000_add_prep_started_at_to_orders.php` | عمود `prep_started_at` |
| `app/Services/OrderTimingService.php` | حساب ETA + ختم البدء (جديد) |
| `app/Services/OrderService.php` | hook ختم prep_started_at |
| `app/Models/Order.php` | fillable + cast |
| `app/Http/Controllers/Admin/SettingController.php` | `prep_time_buffer_pct` |
| `resources/views/admin/settings/index.blade.php` | حقل buffer % |
| `resources/views/components/admin/_kitchen-card.blade.php` | شارات per-item + ribbon ETA |
| `resources/views/components/admin/⚡kitchen-board.blade.php` | eager-load menuItem |
| `resources/views/customer/partials/track-card.blade.php` | countdown widget |
| `app/Http/Controllers/Admin/OrderController.php` | KPI + delayed_only في archive |
| `resources/views/admin/orders/archive.blade.php` | عمود + KPI rail + toggle |
| `app/Http/Controllers/Portal/AuthController.php` | logout يمسح cookies |
| `app/Http/Controllers/Customer/MenuController.php` | حارس staff + dismissGuestPromo |
| `resources/views/admin/reports/profit-loss.blade.php` | refunds دائماً ظاهر |
| `resources/views/admin/reports/profit-loss-print.blade.php` | نفس الشي للـ PDF |
| `resources/views/customer/menu.blade.php` | كرت ضيف + بانر إعلان |
| `resources/views/customer/track.blade.php` | شريط signup محسَّن |
| `app/Models/Announcement.php` | scopeForGuests + isGuestsAudience |
| `app/Services/AnnouncementService.php` | short-circuit للـguests |
| `app/Http/Controllers/Admin/AnnouncementController.php` | validation + filter |
| `resources/views/admin/announcements/form.blade.php` | optgroup + hint |
| `resources/views/admin/announcements/index.blade.php` | شارة "بانر منيو" |
| `routes/customer.php` | route dismissGuestPromo |

### ٧. ملاحظات معمارية للجلسات القادمة

1. **القنوات المختلفة لنفس الميزة**: نظام الإعلانات الآن يدير **قناتين منفصلتين
   تماماً** عبر نفس الـmodel — push notifications لزبائن مسجَّلين، vs banner-render
   لزوّار. أي ميزة لاحقة تستهدف زبائن مجهولين يجب أن تتبع نفس النمط: قناة عرض
   لا تكتب notifications، وتقرأ live في view.

2. **التواريخ السالبة في MySQL**: لمّا تطرح عمودين كلاهما UNSIGNED (مثل
   `TIMESTAMPDIFF(MINUTE,a,b) - estimated_minutes`)، MySQL يفجّر مع
   "Numeric value out of range" لو النتيجة سالبة. الحل: `CAST(... AS SIGNED)`
   على الجانبين. **مهم خاصة في تقارير الـ KPI**.

3. **Idempotency في الـ services**: `stampPrepStart` كان idempotent (لا يدوس
   قيمة موجودة) لكن **مش كافي** — كان يدوس على null في الحالات الخطأ. الـ
   idempotency لازم تفحص state machine، مش بس عمود واحد.

4. **Soft remember-me cookies**: أي كوكي بيعرّف زبون لازم يكون له lifecycle
   متّسق — يُمسح عند logout صراحة. ولا تثق فيه عند وجود staff session على
   نفس المتصفح.

5. **محاسبة P&L**: لا تخفي بنود الصفر — التقرير المالي لازم يظهر كل سطر
   صراحة. الزائر يحتاج يعرف "تم فحصها = صفر"، مش "غائبة من النظام".

**لا تخلط هذا الإصلاح مع شي تاني.** هو وحده يستحق جلسة كاملة.
