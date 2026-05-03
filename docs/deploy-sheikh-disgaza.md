# Staging Deploy: sheikh.disgaza.com

هذا الملف هو المرجع السريع لنقل المشروع على سيرفر التطوير.

## 1. إعداد الـ subdomain

لازم يكون document root للـ subdomain على مجلد `public` فقط:

```text
/path/to/restaurant-qr/public
```

لا توجه الدومين على جذر المشروع، لأن ملفات مثل `.env` و `storage` و `vendor` يجب ألا تكون متاحة من المتصفح.

## 2. ملف البيئة

على السيرفر:

```bash
cp .env.staging.example .env
php artisan key:generate --force
```

عدّل هذه القيم قبل التشغيل:

```dotenv
APP_URL=https://sheikh.disgaza.com
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
MAIL_*=...
```

اترك هذه القيم مفعّلة على السيرفر:

```dotenv
APP_DEBUG=false
APP_FORCE_HTTPS=true
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
VITE_REVERB_ENABLED=false
```

`VITE_REVERB_ENABLED=false` مقصودة حالياً، لأن شاشات المطبخ والبار والكاشير تعتمد على Livewire polling بدون WebSocket.

## 3. أوامر النشر

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build

php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class=SystemSeeder --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

لأول تثبيت فعلي، يمكن استخدام المثبت التفاعلي بدل أمر الـ seed اليدوي:

```bash
php artisan app:install --no-demo-wipe
```

## 4. Queue و Scheduler

أضف cron:

```cron
* * * * * cd /path/to/restaurant-qr && php artisan schedule:run >> /dev/null 2>&1
```

وشغّل queue worker عبر Supervisor أو service manager:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

## 5. Nginx example

```nginx
server {
    listen 80;
    server_name sheikh.disgaza.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name sheikh.disgaza.com;

    root /path/to/restaurant-qr/public;
    index index.php index.html;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

## 6. بعد النقل

افتح هذه الروابط:

```text
https://sheikh.disgaza.com/up
https://sheikh.disgaza.com/login
https://sheikh.disgaza.com/admin
```

ثم افحص:

- QR الطاولة يولد رابط `https://sheikh.disgaza.com/menu/...`.
- الصور المرفوعة تظهر من `/storage/...`.
- تسجيل الدخول يعمل والمستخدم لا يخرج من الجلسة.
- شاشة المطبخ والبار والكاشير تتحدث كل عدة ثوان.
- الطباعة و PDF للفاتورة يعملان.

أي QR قديم مطبوع من `127.0.0.1` يجب إعادة طباعته بعد ضبط `APP_URL`.
