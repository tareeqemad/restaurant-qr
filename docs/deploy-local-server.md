# نشر السيرفر المحلي داخل المطعم (Phase 1 — Offline-First)

> هذا الدليل ينفّذ **المرحلة ١** من خطة العمل بدون إنترنت
> (`docs/offline-sync-architecture.md`): تشغيل البرنامج على جهاز داخل المطعم
> بحيث يعمل **١٠٠٪ بدون إنترنت** — كاشير، مطبخ، ويتر، وقائمة QR للزبون — مع
> باك-أب ليلي. المزامنة مع السحابة تأتي في المراحل ٢–٣.

---

## 0. الفكرة باختصار

```
        إنترنت (قد ينقطع)  ✗
              │
       راوتر المطعم
              │  ← شبكة محلية (LAN/WiFi) تظل شغّالة دائماً
   ┌──────────┼─────────────┬──────────────┐
 السيرفر    كاشير/مطبخ    أجهزة الموظفين   أجهزة الزبائن
 المحلي                                    (على واي-فاي المطعم)
```

طالما الشبكة المحلية (الراوتر والواي-فاي) شغّالة، النظام يعمل حتى لو الإنترنت
مقطوع أسبوعاً كاملاً.

---

## 1. الجهاز المطلوب

- **mini-PC / NUC** (مفضّل) أو لابتوب دائم التشغيل، 8GB RAM فأكثر، SSD.
- موصول بالراوتر بكابل **Ethernet** (أثبت من الواي-فاي للسيرفر).
- نظام: Ubuntu Server 22.04+ أو أي توزيعة Linux. (يعمل على Windows أيضاً لكن
  Linux أوصى به للاستقرار.)
- **IP محلي ثابت** للجهاز من إعدادات الراوتر (مثلاً `192.168.1.10`) — مهم جداً
  حتى لا يتغيّر عنوان السيرفر فتتعطّل أكواد QR.

---

## 2. المتطلبات على الجهاز

```bash
sudo apt update
sudo apt install -y php8.2 php8.2-{cli,fpm,mysql,mbstring,xml,curl,zip,gd,bcmath,intl} \
                    mariadb-server nginx git unzip
# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
# Node (للـ build)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

تأكّد أن `mysqldump` موجود (يأتي مع `mariadb-server`/`mysql-client`) — أمر
الباك-أب يعتمد عليه.

---

## 3. جلب المشروع وضبط البيئة

```bash
cd /var/www
git clone <repo-url> restaurant-qr
cd restaurant-qr
cp .env.example .env
php artisan key:generate --force
```

عدّل `.env`:

```dotenv
APP_NAME="Relax"
APP_ENV=production
APP_DEBUG=false
# عنوان السيرفر المحلي كما يصله جهاز داخل الشبكة:
APP_URL=http://192.168.1.10
# نفس العنوان (أو اسم mDNS) الذي ستوجّه إليه أكواد QR للزبائن:
MENU_BASE_URL=http://192.168.1.10

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=restaurant_qr
DB_USERNAME=relax
DB_PASSWORD=<قوية>

# باك-أب ليلي محلي (الافتراضي). لرفع نسخة خارج المطعم لاحقاً اضبط BACKUP_DISK.
BACKUP_KEEP=14
```

> **ملاحظة QR:** ما دام `MENU_BASE_URL` مضبوطاً، كل كود QR يُطبع من
> `/admin/tables` سيوجّه على السيرفر المحلي تلقائياً، فيعمل الطلب الذاتي للزبون
> بدون إنترنت. أي QR قديم مطبوع على عنوان السحابة يجب إعادة طباعته.

---

## 4. قاعدة البيانات والتنصيب

```bash
sudo mysql -e "CREATE DATABASE restaurant_qr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'relax'@'127.0.0.1' IDENTIFIED BY '<قوية>'; \
               GRANT ALL ON restaurant_qr.* TO 'relax'@'127.0.0.1'; FLUSH PRIVILEGES;"

composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan app:install --no-demo-wipe     # أو db:seed --class=SystemSeeder --force
php artisan storage:link
php artisan optimize
```

---

## 5. الخدمات الدائمة (تعمل بدون إنترنت)

### Nginx
وجّه document root على `public/` كما في `docs/deploy-sheikh-disgaza.md` لكن
بـ `server_name 192.168.1.10 relax.local;` وبدون إعادة توجيه HTTPS (الشبكة
المحلية HTTP عادي).

### Queue worker + Scheduler (عبر systemd أو Supervisor)

```bash
# queue worker
php artisan queue:work --sleep=3 --tries=3 --timeout=90

# cron للسكِجولر (يشغّل الباك-أب الليلي 03:30 + التذكيرات + السنابشوت)
* * * * * cd /var/www/restaurant-qr && php artisan schedule:run >> /dev/null 2>&1
```

---

## 6. الباك-أب (شبكة الأمان قبل طبقة المزامنة)

يعمل تلقائياً كل ليلة 03:30 عبر السكِجولر. للتشغيل اليدوي/الاختبار:

```bash
php artisan backup:run                 # dump مضغوط + تدوير النسخ القديمة
php artisan backup:run --no-upload     # محلي فقط
php artisan backup:run --keep=30       # تغيير عدد النسخ المحفوظة
```

النسخ تُحفظ في `storage/app/backups/*.sql.gz` ويُحتفظ بآخر `BACKUP_KEEP` نسخة.

**رفع خارج المطعم (موصى به بشدة):** اضبط `BACKUP_DISK` على disk معرّف في
`config/filesystems.php` (مثل `s3`) فتُرفع كل نسخة لمكان آمن خارج المطعم. فشل
الرفع يُسجَّل ولا يُفشل الباك-أب المحلي.

---

## 7. اسم محلي ودود للزبون (اختياري لكن مفضّل)

بدل ما يرى الزبون `192.168.1.10`، استخدم اسماً:

- **mDNS** (`avahi-daemon` على Linux): الجهاز يصبح `relax.local` تلقائياً على
  الشبكة. اضبط `MENU_BASE_URL=http://relax.local`.
- أو سجّل `relax.local` في DNS الراوتر المحلي.
- **Captive portal** (متقدّم): يوجّه أي جهاز يتصل بواي-فاي المطعم مباشرةً
  للقائمة.

---

## 8. قائمة فحص بعد النشر (افصل إنترنت المطعم وجرّب)

- [ ] افتح `http://<IP>/up` و `/login` و `/admin` من جهاز على الشبكة.
- [ ] امسح كود QR لطاولة من هاتف **على واي-فاي المطعم** → تظهر القائمة وتقدر تطلب.
- [ ] الويتر يفتح أوردر من `/admin` ويُطبع.
- [ ] شاشة المطبخ/البار تتحدّث.
- [ ] الكاشير يقفل فاتورة ويطبع PDF.
- [ ] `php artisan backup:run` ينتج ملف `.sql.gz` غير فارغ.
- [ ] **افصل كابل الإنترنت (أبقِ الراوتر شغّالاً) وكرّر كل ما سبق** — يجب أن
      يعمل كل شيء.

إذا نجحت كل النقاط، المطعم محمي من انقطاع الإنترنت. الخطوة التالية: المرحلة ٢
(مزامنة الإعدادات من السحابة) ثم المرحلة ٣ (رفع العمليات للتقارير المجمّعة).
