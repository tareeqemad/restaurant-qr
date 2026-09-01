<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\DemoResetService;
use Database\Seeders\ProductionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One-shot production installer.
 *
 *   php artisan app:install
 *
 * Walks the operator through:
 *   1. Migrating the database (only if pending migrations exist).
 *   2. Wiping any existing demo data (with explicit confirmation).
 *   3. Seeding system references + the preserved production menu catalogue.
 *   4. Creating the first Super Admin account interactively.
 *
 * Designed for first-time deployment on a client server. Idempotent — running
 * a second time on an already-installed system asks before doing anything
 * destructive and leaves the existing super admin alone.
 *
 * Use `--force` to skip the destructive-action confirmations (CI / scripted
 * provisioning). Use `--no-interaction` (Symfony default) to fail loudly
 * instead of prompting.
 */
class InstallApp extends Command
{
    protected $signature = 'app:install
        {--force : Skip the destructive-action confirmations}
        {--no-demo-wipe : Don\'t offer to wipe demo data even if it exists}';

    protected $description = 'تثبيت إنتاجي تفاعلي: schema + system/catalogue seed + إنشاء أول مدير نظام.';

    public function handle(DemoResetService $reset): int
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>──────────────────────────────────────────────</>');
        $this->line('<fg=cyan;options=bold>  تثبيت نظام Relax Restaurant QR — Production  </>');
        $this->line('<fg=cyan;options=bold>──────────────────────────────────────────────</>');
        $this->newLine();

        // ── 1. Migrations ────────────────────────────────────────────
        $this->task('فحص الـ migrations', function () {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            $this->line(Artisan::output());

            return $exitCode === 0;
        });

        // ── 2. Detect + optionally wipe demo data ────────────────────
        if (! $this->option('no-demo-wipe') && $reset->hasDemoData()) {
            $this->newLine();
            $this->warn('⚠  النظام يحتوي على بيانات (فروع/مستخدمين/طلبات).');
            $shouldWipe = $this->option('force')
                || $this->confirm('مسح كل بيانات العرض قبل التثبيت؟ (لا يمكن التراجع)', false);

            if ($shouldWipe) {
                $this->task('مسح البيانات', function () use ($reset) {
                    $stats = $reset->reset(null);
                    $this->line(sprintf(
                        '   حذفت %d فرع و %d مستخدم.',
                        $stats['branches'], $stats['users_deleted']
                    ));

                    return true;
                });
            }
        }

        // ── 3. Production seed (idempotent on a clean install) ───────
        $this->task('زرع بيانات النظام ومنيو الإنتاج المحفوظ', function () {
            Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);

            return true;
        });

        // ── 4. First Super Admin ─────────────────────────────────────
        $existingSuperAdmin = User::where('role', UserRole::SuperAdmin->value)->first();
        if ($existingSuperAdmin) {
            $this->newLine();
            $this->info("✓ يوجد مدير نظام بالفعل: {$existingSuperAdmin->name} ({$existingSuperAdmin->email})");
            $this->line('  استخدم بياناته لتسجيل الدخول. لو تنسيت كلمة المرور: ');
            $this->line('  <fg=yellow>php artisan tinker</> ثم:');
            $this->line('  <fg=gray>User::find('.$existingSuperAdmin->id.')->update([\'password\' => Hash::make(\'كلمة-جديدة\')]);</>');
        } else {
            $this->newLine();
            $this->line('<fg=cyan;options=bold>إنشاء أول مدير نظام:</>');

            $name = $this->ask('الاسم الكامل', 'مدير النظام');
            $username = $this->ask('اسم المستخدم (للدخول)', 'admin');
            $email = $this->askValid('البريد الإلكتروني', fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false, 'بريد إلكتروني غير صالح.');
            $phone = $this->ask('رقم الهاتف (اختياري)', '');
            $password = $this->askValid('كلمة المرور (8 أحرف على الأقل)', fn ($v) => strlen($v) >= 8, 'كلمة المرور قصيرة جداً.', secret: true);

            DB::transaction(function () use ($name, $username, $email, $phone, $password) {
                User::create([
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'phone' => $phone ?: null,
                    'role' => UserRole::SuperAdmin->value,
                    'password' => Hash::make($password),
                    'status' => 'active',
                ]);
            });

            $this->newLine();
            $this->info("✓ تم إنشاء حساب «{$name}» بنجاح.");
        }

        // ── Done ─────────────────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=green;options=bold>──────────────────────────────────────────────</>');
        $this->line('<fg=green;options=bold>  ✓ التثبيت اكتمل                              </>');
        $this->line('<fg=green;options=bold>──────────────────────────────────────────────</>');
        $this->newLine();
        $this->line('الخطوات التالية:');
        $this->line('  1. <fg=cyan>افتح /admin</> وسجّل دخول بحساب مدير النظام.');
        $this->line('  2. راجع الفرع ومنيو الإنتاج المحفوظ من لوحة الإدارة.');
        $this->line('  3. أضف الطاولات والموظفين وأرصدة المخزون الافتتاحية.');
        $this->line('  4. انسخ مجلد <fg=cyan>storage/app/public</> القديم إن أردت الاحتفاظ بالصور المرفوعة.');
        $this->line('  5. لتسليم نسخة تجريبية لعميل، استخدم <fg=cyan>/setup</> لاحقاً بعد موافقته؛ هذا الأمر مخصص لمشغّل الخادم.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Tiny task helper — prints "task name … DONE/FAIL" with a runtime.
     * Returning false from the closure marks the task failed.
     */
    protected function task(string $title, callable $work): void
    {
        $this->line("  <fg=gray>→</> {$title} ...");
        $start = microtime(true);
        try {
            $ok = $work() !== false;
        } catch (\Throwable $e) {
            $this->error("    ✗ فشل: {$e->getMessage()}");
            throw $e;
        }
        $ms = round((microtime(true) - $start) * 1000);
        if ($ok) {
            $this->line("  <fg=green>✓</> {$title} <fg=gray>({$ms}ms)</>");
        } else {
            $this->error("  ✗ {$title}");
        }
    }

    /**
     * Ask + re-prompt loop until the answer passes the validator. `secret`
     * hides typed input (passwords).
     */
    protected function askValid(string $question, callable $validator, string $errorMsg, bool $secret = false): string
    {
        do {
            $value = $secret ? $this->secret($question) : $this->ask($question);
            if ($validator($value ?? '')) {
                return (string) $value;
            }
            $this->error("  ✗ {$errorMsg}");
        } while (true);
    }
}
