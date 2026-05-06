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
