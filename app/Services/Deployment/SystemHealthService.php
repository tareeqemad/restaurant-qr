<?php

namespace App\Services\Deployment;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class SystemHealthService
{
    public function __construct(
        private readonly AccountingSchemaUpgrade $accounting,
        private readonly PublicStorageService $publicStorage,
        private readonly MigrationReconciler $migrationReconciler,
    ) {}

    /** @return array{generatedAt:string, summary:array{good:int,warning:int,danger:int}, checks:list<array<string,mixed>>} */
    public function report(bool $touchCache = true): array
    {
        $checks = [
            $this->runtimeCheck(),
            $this->environmentCheck(),
            $this->appKeyCheck(),
            $this->databaseCheck(),
            $this->migrationCheck(),
            $this->accountingCheck(),
            $this->buildCheck(),
            $this->writablePathsCheck(),
            $this->publicStorageCheck(),
            $this->cacheCheck($touchCache),
            $this->schedulerCheck(),
            $this->backupCheck(),
            $this->queueCheck(),
        ];

        $summary = ['good' => 0, 'warning' => 0, 'danger' => 0];
        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        return [
            'generatedAt' => now()->toIso8601String(),
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    /** @return list<string> */
    public function pendingMigrations(): array
    {
        $files = collect(File::files(database_path('migrations')))
            ->map(fn ($file) => pathinfo($file->getFilename(), PATHINFO_FILENAME))
            ->sort()
            ->values();

        if (! Schema::hasTable('migrations')) {
            return $files->all();
        }

        $ran = DB::table('migrations')->pluck('migration')->all();

        return $files->diff($ran)->values()->all();
    }

    public function hasBusinessData(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function runtimeCheck(): array
    {
        $required = ['openssl', 'pdo_mysql', 'mbstring', 'tokenizer', 'zip'];
        $missing = array_values(array_filter($required, fn (string $extension) => ! extension_loaded($extension)));
        $versionOk = version_compare(PHP_VERSION, '8.2.0', '>=');

        return $this->check(
            'runtime',
            'PHP ومتطلبات التشغيل',
            $versionOk && $missing === [] ? 'good' : 'danger',
            'PHP '.PHP_VERSION.($missing ? ' · إضافات ناقصة' : ' · الإضافات الأساسية جاهزة'),
            $missing ? 'الناقص: '.implode(', ', $missing) : null,
        );
    }

    /** @return array<string,mixed> */
    private function environmentCheck(): array
    {
        $environment = app()->environment();
        $debug = (bool) config('app.debug');
        $url = (string) config('app.url');
        $remoteUrl = ! str_contains($url, 'localhost') && ! str_contains($url, '127.0.0.1');
        $secure = ! $remoteUrl || (str_starts_with($url, 'https://') && $environment === 'production' && ! $debug);

        return $this->check(
            'environment',
            'بيئة الإنتاج',
            $secure ? ($remoteUrl ? 'good' : 'warning') : 'danger',
            "{$environment} · ".($debug ? 'Debug مفعّل' : 'Debug مغلق'),
            $url,
        );
    }

    /** @return array<string,mixed> */
    private function appKeyCheck(): array
    {
        $key = (string) config('app.key');

        return $this->check(
            'app_key',
            'مفتاح التطبيق',
            $key !== '' ? 'good' : 'danger',
            $key !== '' ? 'موجود وثابت' : 'APP_KEY غير موجود',
            $key !== '' ? 'لا تغيّره بعد بدء استخدام النظام.' : 'ولّد المفتاح قبل إنشاء أي بيانات مشفرة.',
        );
    }

    /** @return array<string,mixed> */
    private function databaseCheck(): array
    {
        try {
            DB::select('SELECT 1');
            $connection = DB::connection();

            return $this->check(
                'database',
                'قاعدة البيانات',
                $connection->getDriverName() === 'mysql' ? 'good' : 'danger',
                'الاتصال ناجح · '.$connection->getDriverName(),
                (string) $connection->getDatabaseName(),
            );
        } catch (\Throwable $e) {
            return $this->check('database', 'قاعدة البيانات', 'danger', 'تعذر الاتصال', $this->safeMessage($e));
        }
    }

    /** @return array<string,mixed> */
    private function migrationCheck(): array
    {
        try {
            $pending = $this->pendingMigrations();
            $reconcilable = $this->migrationReconciler->candidates();
            $needsSql = array_values(array_diff($pending, $reconcilable));

            if ($pending !== [] && $needsSql === []) {
                return $this->check(
                    'migrations',
                    'بنية قاعدة البيانات',
                    'warning',
                    count($pending).' سجل migration يحتاج مصالحة آمنة',
                    'الجداول أو الترقية موجودة فعلياً؛ لن يُنفذ SQL عند المصالحة.',
                    'php artisan migrations:reconcile --apply',
                );
            }

            return $this->check(
                'migrations',
                'بنية قاعدة البيانات',
                $pending === [] ? 'good' : 'danger',
                $pending === [] ? 'كل migrations مطبقة' : count($needsSql).' migration يحتاج تطبيقاً فعلياً',
                $needsSql ? implode(', ', array_slice($needsSql, 0, 3)) : null,
                $pending ? 'php artisan migrate --force' : null,
            );
        } catch (\Throwable $e) {
            return $this->check('migrations', 'بنية قاعدة البيانات', 'danger', 'تعذر فحص migrations', $this->safeMessage($e));
        }
    }

    /** @return array<string,mixed> */
    private function accountingCheck(): array
    {
        try {
            if (! $this->accounting->supportsCurrentConnection()) {
                return $this->check('accounting_schema', 'مخطط المحاسبة', 'danger', 'الاتصال ليس MySQL');
            }
            $pending = $this->accounting->pendingChanges();

            return $this->check(
                'accounting_schema',
                'مخطط المحاسبة',
                $pending === [] ? 'good' : 'danger',
                $pending === [] ? 'مكتمل ومتوافق' : count($pending).' عنصر ناقص',
                $pending ? implode(', ', array_slice($pending, 0, 4)) : null,
                $pending ? 'php artisan migrate --force' : null,
            );
        } catch (\Throwable $e) {
            return $this->check('accounting_schema', 'مخطط المحاسبة', 'danger', 'تعذر فحص المخطط', $this->safeMessage($e));
        }
    }

    /** @return array<string,mixed> */
    private function buildCheck(): array
    {
        try {
            $state = $this->frontendBuildState();
            if (! $state['exists']) {
                return $this->check(
                    'frontend_build',
                    'بناء الواجهة',
                    'danger',
                    'ملفات الإنتاج غير مبنية',
                    'رفع ملفات Vue وحدها لا يحدّث الشاشة المنشورة.',
                    'npm ci && npm run build',
                );
            }

            if ($state['stale']) {
                return $this->check(
                    'frontend_build',
                    'بناء الواجهة',
                    'danger',
                    'نسخة الواجهة المنشورة أقدم من الكود',
                    'آخر بناء '.$state['builtAt']->diffForHumans().' · تغيّر المصدر '.$state['sourceAt']->diffForHumans(),
                    'npm ci && npm run build',
                );
            }

            return $this->check(
                'frontend_build',
                'بناء الواجهة',
                'good',
                'ملفات Vite موجودة ومحدّثة',
                'آخر بناء '.$state['builtAt']->diffForHumans(),
            );
        } catch (\Throwable $e) {
            return $this->check(
                'frontend_build',
                'بناء الواجهة',
                'danger',
                'تعذّر التحقق من ملفات الواجهة',
                $this->safeMessage($e),
                'npm ci && npm run build',
            );
        }
    }

    /**
     * @param  list<string>|null  $sourcePaths
     * @return array{exists:bool,stale:bool,builtAt:?Carbon,sourceAt:?Carbon}
     */
    public function frontendBuildState(?string $manifestPath = null, ?array $sourcePaths = null): array
    {
        $manifestPath ??= public_path('build/manifest.json');
        $sourcePaths ??= [
            resource_path('js'),
            resource_path('css'),
            base_path('package.json'),
            base_path('package-lock.json'),
            base_path('vite.config.js'),
        ];

        if (! is_file($manifestPath)) {
            return ['exists' => false, 'stale' => false, 'builtAt' => null, 'sourceAt' => null];
        }

        $sourceFiles = collect($sourcePaths)->flatMap(function (string $path) {
            if (is_dir($path)) {
                return collect(File::allFiles($path))->map(fn ($file) => $file->getPathname());
            }

            return is_file($path) ? [$path] : [];
        });
        $latestSourceTimestamp = $sourceFiles
            ->map(fn (string $path) => filemtime($path))
            ->filter(fn ($timestamp) => $timestamp !== false)
            ->max();
        $buildTimestamp = filemtime($manifestPath);

        if ($buildTimestamp === false) {
            throw new \RuntimeException('تعذر قراءة تاريخ Vite manifest.');
        }

        $builtAt = Carbon::createFromTimestamp($buildTimestamp);
        $sourceAt = $latestSourceTimestamp
            ? Carbon::createFromTimestamp((int) $latestSourceTimestamp)
            : null;

        return [
            'exists' => true,
            // A small tolerance avoids false alarms on files extracted from a
            // package within the same filesystem timestamp tick.
            'stale' => $sourceAt !== null && $sourceAt->timestamp > $builtAt->timestamp + 2,
            'builtAt' => $builtAt,
            'sourceAt' => $sourceAt,
        ];
    }

    /** @return array<string,mixed> */
    private function writablePathsCheck(): array
    {
        $paths = [storage_path(), base_path('bootstrap/cache')];
        $blocked = array_values(array_filter($paths, fn (string $path) => ! is_dir($path) || ! is_writable($path)));

        return $this->check(
            'writable_paths',
            'صلاحيات الكتابة',
            $blocked === [] ? 'good' : 'danger',
            $blocked === [] ? 'storage وbootstrap/cache قابلان للكتابة' : 'مسارات غير قابلة للكتابة',
            $blocked ? implode(', ', $blocked) : null,
        );
    }

    /** @return array<string,mixed> */
    private function publicStorageCheck(): array
    {
        try {
            $storage = $this->publicStorage->inspect();

            return $this->check(
                'public_storage',
                'التخزين العام',
                $storage['status'],
                $storage['message'],
                'الوضع: '.$storage['mode'],
                $storage['command'],
            );
        } catch (\Throwable $e) {
            return $this->check('public_storage', 'التخزين العام', 'danger', 'تعذر فحص التخزين', $this->safeMessage($e));
        }
    }

    /** @return array<string,mixed> */
    private function cacheCheck(bool $touch): array
    {
        if (! $touch) {
            return $this->check('cache', 'الكاش', 'warning', 'لم يُنفذ اختبار الكتابة', 'Driver: '.config('cache.default'));
        }

        $key = 'system-health:'.bin2hex(random_bytes(6));
        try {
            Cache::put($key, 'ok', 30);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return $this->check('cache', 'الكاش', $ok ? 'good' : 'danger', $ok ? 'قراءة وكتابة ناجحتان' : 'فشل اختبار الكاش', 'Driver: '.config('cache.default'));
        } catch (\Throwable $e) {
            return $this->check('cache', 'الكاش', 'danger', 'فشل اختبار الكاش', $this->safeMessage($e));
        }
    }

    /** @return array<string,mixed> */
    private function schedulerCheck(): array
    {
        try {
            $value = Cache::get('system.scheduler.last_run_at');
        } catch (\Throwable $e) {
            return $this->check('scheduler', 'المهام المجدولة', 'danger', 'تعذر قراءة نبضة Scheduler', $this->safeMessage($e));
        }
        if (! $value) {
            return $this->check('scheduler', 'المهام المجدولة', 'warning', 'لا توجد نبضة مسجلة', 'تأكد من cron كل دقيقة.', '* * * * * php artisan schedule:run');
        }

        try {
            $at = Carbon::parse($value);
            $fresh = $at->greaterThan(now()->subMinutes(5));

            return $this->check('scheduler', 'المهام المجدولة', $fresh ? 'good' : 'danger', $fresh ? 'تعمل بانتظام' : 'النبضة متوقفة', $at->diffForHumans());
        } catch (\Throwable) {
            return $this->check('scheduler', 'المهام المجدولة', 'danger', 'قيمة النبضة غير صالحة');
        }
    }

    /** @return array<string,mixed> */
    private function backupCheck(): array
    {
        try {
            $dir = trim((string) config('backup.local_path'), '/');
            $disk = Storage::disk('local');
            $files = collect($disk->files($dir))->filter(fn (string $file) => str_ends_with($file, '.sql.gz'));
            if ($files->isEmpty()) {
                return $this->check('backup', 'النسخ الاحتياطي', 'warning', 'لا توجد نسخة محلية بعد', null, 'php artisan backup:run');
            }
            $latest = $files->sortByDesc(fn (string $file) => $disk->lastModified($file))->first();
            $at = Carbon::createFromTimestamp($disk->lastModified($latest));
            $fresh = $at->greaterThan(now()->subHours(36));

            return $this->check('backup', 'النسخ الاحتياطي', $fresh ? 'good' : 'warning', $fresh ? 'توجد نسخة حديثة' : 'آخر نسخة قديمة', $at->diffForHumans(), $fresh ? null : 'php artisan backup:run');
        } catch (\Throwable $e) {
            return $this->check('backup', 'النسخ الاحتياطي', 'warning', 'تعذر قراءة النسخ المحلية', $this->safeMessage($e));
        }
    }

    /** @return array<string,mixed> */
    private function queueCheck(): array
    {
        $connection = (string) config('queue.default');

        return $this->check(
            'queue',
            'طابور المهام',
            $connection === 'sync' ? 'warning' : 'good',
            'Connection: '.$connection,
            $connection === 'sync' ? 'المهام الثقيلة ستعمل داخل طلب المستخدم.' : 'يتطلب Queue worker دائم أو Cron مناسب.',
            $connection === 'sync' ? null : 'php artisan queue:work --stop-when-empty --tries=3',
        );
    }

    /** @return array<string,mixed> */
    private function check(string $key, string $label, string $status, string $summary, ?string $detail = null, ?string $command = null): array
    {
        return compact('key', 'label', 'status', 'summary', 'detail', 'command');
    }

    private function safeMessage(\Throwable $e): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?: 'خطأ غير معروف', 0, 240);
    }
}
