# Restaurant QR

مرجع تشغيل وبيع نظام المطاعم، شامل السوق العربي/الأمريكي، setup أول مرة، التراخيص، المزامنة، وبناء نسخة عميل جاهزة.

## الفكرة التجارية

النظام يتم تسليمه للعميل كنسخة ديمو حسب السوق. العميل يدخل على `/login` ويجرب بيانات الديمو أولا بدون إجباره على setup. إذا أعجبه النظام ودفع، أنت تنشئ له ترخيص من لوحة التراخيص الخاصة بك، ثم يبقى لديه خيار `/setup` لتحويل نسخة الديمو إلى تشغيل حقيقي: يمسح بيانات الديمو ويبدأ ببيانات مطعمه الحقيقية.

العميل لا يرى ولا يفهم:

- `UUID / ULID` للفرع.
- إعدادات الترخيص.
- private license key.

أنت فقط تتحكم بالترخيص، التجديد، الإيقاف، وعدد الفروع.

## المتطلبات

- PHP 8.2+
- Composer
- Node.js و npm
- MySQL أو SQLite حسب بيئة التشغيل
- PHP extensions المعتادة للارافيل، ويضاف لها:
  - `openssl`
  - `zip`

## تشغيل محلي للتطوير

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

للتحقق:

```bash
php artisan test
php artisan view:cache
php artisan view:clear
```

## السوق واللغة

مصدر اللغة والاتجاه هو `MARKET_PROFILE` فقط.

```env
MARKET_PROFILE=palestine
```

يعني:

- `palestine`: عربي و RTL
- `us`: إنجليزي و LTR

لا يوجد زر تبديل لغة داخل النظام. نسخة العميل تظهر حسب السوق المضبوط في الكونفيج/ملف البيئة.

## Setup أول مرة

رابط الإعداد:

```text
/setup
```

الـ setup ليس إجباريا أثناء التجربة. نسخة الديمو تعمل من `/login`، والـ setup مصمم فقط لتحويل نسخة الديمو إلى نسخة إنتاج حقيقية عندما يقرر العميل البدء:

- يمسح بيانات الديمو.
- ينشئ فرع حقيقي جديد.
- ينشئ مالك/مدير أول للنظام.
- يضبط اسم المطعم والبيانات الرسمية.
- يضبط الألوان، الشعار، والأيقونة.
- يحفظ إعدادات السوق حسب الكونفيج.
- يحدد عملة البيع، عملة الدفاتر المحاسبية، وسعر الصرف بينهما.
- ينشئ السنة المالية الحالية حسب شهر/يوم بداية السنة المالية المختار.
- يجهز UUID الفرع داخليا بدون عرضه للعميل.
- يحفظ إعدادات الترخيص المجهزة مسبقا من النسخة، بدون أن يراها العميل.

مهم: الترخيص ليس جزءا من قرار العميل داخل setup. الترخيص يتم تجهيزه من طرف صاحب البرنامج قبل تسليم النسخة.

## سيرفر التراخيص الخاص بك

تحتاج نسخة مركزية عندك فقط لإدارة التراخيص. مثال:

```text
https://licenses.your-domain.com
```

هذه النسخة تكون من نفس المشروع، لكن تضبط كـ License Cloud:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://licenses.your-domain.com

LICENSE_ENABLED=true
LICENSE_ROLE=cloud
LICENSE_PRIVATE_KEY_PATH=storage/app/license/license-private.pem
LICENSE_PUBLIC_KEY_PATH=storage/app/license/license-public.pem
```

ثم شغل:

```bash
php artisan migrate --force
php artisan license:generate-keys
php artisan storage:link
php artisan optimize
```

بعدها افتح `/setup` مرة واحدة على سيرفر التراخيص، واعمل مستخدم أدمن خاص بك. من هناك تستخدم:

```text
/admin/licenses
```

لإنشاء وتجديد وإيقاف تراخيص العملاء.

ممنوع نسخ `license-private.pem` لأي عميل.

## إنشاء ترخيص لعميل دفع

من سيرفر التراخيص:

```text
/admin/licenses
```

اعمل ترخيص جديد وحدد:

- اسم العميل.
- اسم المطعم.
- مدة الترخيص: 6 شهور أو سنة.
- مبلغ الدفع النقدي.
- عدد الفروع المسموح.
- ملاحظات داخلية إن وجدت.

النظام يولد `LICENSE_KEY` مثل:

```text
RQ-XXXX-XXXX-XXXX
```

هذا المفتاح يوضع داخل نسخة العميل قبل التسليم.

## بناء باكج عميل للبيع

قبل بناء الباكج شغل:

```bash
npm run build
```

ثم ابن الباكج:

```bash
php artisan release:customer-package \
  --license-key=RQ-PAID-CUSTOMER-001 \
  --cloud-url=https://licenses.your-domain.com \
  --market=us \
  --app-url=https://customer-domain.com \
  --app-name="Customer Restaurant" \
  --public-key=storage/app/license/license-public.pem \
  --include-vendor \
  --force
```

على PowerShell:

```powershell
php artisan release:customer-package `
  --license-key=RQ-PAID-CUSTOMER-001 `
  --cloud-url=https://licenses.your-domain.com `
  --market=us `
  --app-url=https://customer-domain.com `
  --app-name="Customer Restaurant" `
  --public-key=storage/app/license/license-public.pem `
  --include-vendor `
  --force
```

الناتج يكون داخل:

```text
storage/app/releases/
```

الباكج يحتوي:

- `.env` جاهز للعميل.
- `LICENSE_ROLE=branch`.
- `LICENSE_KEY` الخاص بالعميل.
- `LICENSE_CLOUD_URL` الخاص بسيرفر التراخيص عندك.
- `storage/app/license/license-public.pem`.
- دليل `CUSTOMER-DEPLOYMENT.md`.
- ملف `release-manifest.json`.

الباكج لا يحتوي:

- `.git`
- `tests`
- `license-private.pem`
- ملفات cache/logs/runtime
- مفاتيح خاصة

إذا تريد تضمين قاعدة ديمو SQLite داخل الباكج:

```bash
--include-sqlite-demo
```

إذا لم تستخدم `--include-vendor`، يجب تشغيل هذا على سيرفر العميل بعد فك الباكج:

```bash
composer install --no-dev --optimize-autoloader
```

## إعداد نسخة العميل

ملف `.env` في نسخة العميل يجب أن يكون بهذا الشكل العام:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://customer-domain.com

MARKET_PROFILE=us

LICENSE_ENABLED=true
LICENSE_ROLE=branch
LICENSE_CLOUD_URL=https://licenses.your-domain.com
LICENSE_KEY=RQ-XXXX-XXXX-XXXX
LICENSE_PUBLIC_KEY_PATH=storage/app/license/license-public.pem
LICENSE_PRIVATE_KEY_PATH=
LICENSE_SIGNING_SECRET=
```

ثم على سيرفر العميل:

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

بعدها يدخل العميل على:

```text
/login
```

ويجرب نسخة الديمو. عند قرار التشغيل الحقيقي يفتح:

```text
/setup
```

ويحول نسخة الديمو إلى نسخة مطعمه الحقيقية.

## التجديد النقدي

عندما يدفع العميل:

1. افتح سيرفر التراخيص الخاص بك.
2. ادخل `/admin/licenses`.
3. افتح ترخيص العميل.
4. سجل تجديد 6 شهور أو سنة.
5. أدخل المبلغ والمرجع والملاحظات.

نسخة العميل تحدث حالتها عند أول فحص ترخيص مع وجود الإنترنت. إذا كان الإنترنت مقطوعا، النسخة تعمل من الكاش المحلي الموقع ضمن فترة السماح.

## إيقاف أو نقل فرع

كل ترخيص يسجل فروعه المفعلة حسب `branch_uuid`.

في صفحة تفاصيل الترخيص ستجد "تفعيلات الفروع":

- إذا العميل تجاوز عدد الفروع المسموح، يرفض سيرفر التراخيص التفعيل.
- إذا العميل نقل النظام لجهاز جديد، يمكنك إيقاف تفعيل الفرع القديم ثم السماح للجديد.
- `max_branches` يحدد عدد الفروع/النسخ المسموح لها باستخدام نفس الترخيص.

## المزامنة

المزامنة اختيارية ومنفصلة عن الترخيص.

فرع العميل:

```env
SYNC_ENABLED=true
SYNC_ROLE=branch
SYNC_CLOUD_URL=https://sync.your-domain.com
SYNC_TOKEN=secret-token
SYNC_BRANCH_ID=
SYNC_BRANCH_UUID=
```

السحابة:

```env
SYNC_ENABLED=true
SYNC_ROLE=cloud
SYNC_TOKEN=secret-token
```

عند توفر الإنترنت، الفرع يزامن تلقائيا. عند انقطاع الإنترنت، يسجل الحالة offline ويحاول مرة أخرى عند عودة الاتصال.

تفاصيل أكثر في:

```text
docs/offline-sync-architecture.md
```

## المحاسبة والقيود

المحاسبة مبنية على دفتر القيود، وليس فقط على التقارير التشغيلية. المسارات الأساسية:

```text
/admin/accounting
/admin/accounting/mappings
/admin/accounting/manual-entry
/admin/accounting/opening-balances
/admin/accounting/periods
/admin/accounting/fiscal-years
/admin/accounting/reconciliations
/admin/accounting/trial-balance
/admin/accounting/balance-sheet
/admin/accounting/tax-report
/admin/accounting/tax-jurisdictions
/admin/accounting/aging
```

الفكرة العملية للمحاسب:

- شجرة الحسابات قابلة للإدارة مع منع تعديل الحسابات النظامية أو تعطيلها إذا عليها حركة.
- ربط العمليات يتم من شاشة ربط الحسابات: طرق الدفع، فئات المصاريف، وأدوار الترحيل مثل النقد، البنك، العملاء، الموردين، المبيعات، الخصومات، المرتجعات، المخزون، تكلفة البضاعة، الهدر، والضريبة.
- النظام ينشئ القيود تلقائيا من الفواتير، التحصيلات، المصاريف، الموردين، المخزون، الوردية، ووجبات الموظفين حسب الحسابات التي وجهها المحاسب.
- القيود تحفظ عملة الدفاتر، العملة الأصلية لكل سطر، سعر الصرف، والمبلغ الأصلي، لذلك يمكن أن تكون المبيعات بالشيكل والدفاتر بالدولار أو العكس.
- قيود التشغيل تتحول تلقائيا من عملة البيع إلى عملة الدفاتر حسب سعر الصرف المحفوظ في setup.
- إذا لم يربط المحاسب حسابا مخصصا، يستخدم النظام حسابات افتراضية آمنة حتى لا تتوقف العملية.
- القيد اليدوي موجود للحالات الخاصة، ومعه شاشة عكس/تصحيح حتى لا يضطر المحاسب لبناء القيد العكسي يدويا.
- الأرصدة الافتتاحية تدخل من شاشة واحدة مع عملة وسعر صرف لكل سطر، ويمكن للنظام موازنتها تلقائيا على حساب حقوق الملكية الافتتاحية.
- الفترات المحاسبية تنشئ قيد إقفال رسمي يصفّر حسابات الإيرادات والمصاريف ويرحل الصافي إلى الأرباح المحتجزة، ثم تقفل النشر داخل التاريخ المقفل.
- إعادة فتح الفترة تعكس قيد الإقفال تلقائيا حتى تظهر الإيرادات والمصاريف مرة أخرى قبل التصحيح أو إعادة الإقفال.
- السنوات المالية مستقلة عن الفترات، لذلك يمكن إقفال أشهر منفصلة ثم إقفال السنة كقفل أعلى للتاريخ بدون تضارب.
- قبل إقفال فترة أو سنة، يعرض النظام checklist ويمنع الإقفال إذا وجدت ورديات مفتوحة أو جلسات طاولات نشطة أو طلبات غير مكتملة.
- السوق الأمريكي يدعم قواعد Sales Tax حسب الفرع/الولاية/المدينة/ZIP مع fallback للنسبة العامة عند عدم وجود قاعدة مطابقة.
- الميزان، الميزانية العمومية، تقرير الضريبة، أعمار الذمم، وتقرير الربح والخسارة الرسمي تعتمد على دفتر القيود.
- مطابقة الصندوق/البنك تحفظ رصيد الدفتر ورصيد الكشف والفرق للمراجعة.
- دفتر القيود يدعم تصدير CSV للمراجعة أو التسليم للمحاسب الخارجي.

## Production checklist

قبل التسليم أو التشغيل الحقيقي:

- `APP_ENV=production`
- `APP_DEBUG=false`
- تفعيل HTTPS
- ضبط صلاحيات `storage` و `bootstrap/cache`
- تشغيل `php artisan migrate --force`
- تشغيل `php artisan storage:link`
- تشغيل `php artisan optimize`
- ضبط cron:

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

- تشغيل queue worker إذا كانت البيئة تستخدم queues:

```bash
php artisan queue:work --tries=3
```

- تفعيل backup يومي:

```bash
php artisan backup:run
```

## الحماية وحدودها

نظام الترخيص الحالي صحيح تجاريا وتقنيا من ناحية التوقيع:

- private key يبقى عندك فقط.
- العميل يأخذ public key فقط.
- العميل لا يستطيع تزوير ترخيص موقع.
- نسخ نفس المفتاح على أكثر من فرع مقيدة بـ `max_branches`.

لكن إذا العميل عنده وصول كامل لملفات PHP ومعه مبرمج، يمكنه تعديل السورس وحذف فحص الترخيص. لا توجد حماية 100% عند تسليم السورس.

للحماية الأقوى:

- الأفضل SaaS: أنت تستضيف النظام والعميل لا يأخذ الكود.
- أو استخدم ionCube / SourceGuardian لتشفير ملفات PHP الحساسة قبل التسليم.
- لا تسلم repo كامل يحتوي `.git` أو مفاتيح خاصة.

الملفات الحساسة التي تستحق التشفير عند توزيع on-prem:

- `app/Services/Licensing`
- `app/Http/Middleware/EnsureValidLicense.php`
- `app/Http/Controllers/Api/LicenseCheckController.php`
- أجزاء setup/release المرتبطة بالترخيص

## أوامر مهمة

توليد مفاتيح الترخيص على سيرفرك:

```bash
php artisan license:generate-keys
```

بناء باكج عميل:

```bash
php artisan release:customer-package --help
```

تشغيل كل الاختبارات:

```bash
php artisan test
```

فحص ملفات Blade:

```bash
php artisan view:cache
php artisan view:clear
```

## أسعار الصرف المحاسبية

سعر الصرف المحاسبي لا يعتمد على رقم واحد ثابت فقط. يوجد سجل `currency_exchange_rates` يدعم طريقتين:

- سعر من تاريخ إلى تاريخ، مثل سعر شهر كامل أو فترة محددة.
- تحديث يومي، من شاشة العملات، ويصبح السعر صالحا لتاريخ ذلك اليوم.

عند ترحيل أي عملية بعملة مختلفة عن عملة الدفاتر، يبحث النظام عن أحدث سعر يبدأ قبل أو في تاريخ العملية وما زال ضمن فترة الصلاحية. إذا لم يجد سعرا يغطي تاريخ العملية، يوقف الترحيل ويطلب من الموظف تحديث سعر الصرف قبل إكمال العملية. القيد نفسه يحفظ عملة الدفاتر، العملة الأصلية، سعر الصرف، والمبلغ الأصلي لكل سطر.

## آخر تحقق

آخر تحقق معروف:

```text
php artisan test
291 passed, 960 assertions
```
