<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\MenuDuplicationService;
use Illuminate\Console\Command;

/**
 * Copy a complete menu (categories, items, modifier groups, recipes,
 * allergen + modifier links) from one branch to another.
 *
 * Usage:
 *   php artisan menu:duplicate                  # interactive — pick from/to
 *   php artisan menu:duplicate {from} {to}      # both args by code OR id
 *   php artisan menu:duplicate khanyunis gaza --force   # skip confirmation
 *
 * Branch identifiers: pass either the numeric id or the branch `code`
 * (e.g. `main-khan-yunis`, `gaza`). The command resolves both.
 */
class DuplicateMenu extends Command
{
    protected $signature = 'menu:duplicate {from? : Source branch (id or code)} {to? : Target branch (id or code)} {--force : Skip confirmation prompt}';

    protected $description = 'نسخ القائمة كاملة (تصنيفات، أصناف، إضافات، وصفات) من فرع إلى آخر فاضي.';

    public function handle(MenuDuplicationService $service): int
    {
        $from = $this->resolveBranch($this->argument('from'), 'المصدر (الفرع اللي ننسخ منه)');
        if (! $from) return self::FAILURE;

        $to = $this->resolveBranch($this->argument('to'), 'الهدف (الفرع اللي ننسخ له)');
        if (! $to) return self::FAILURE;

        if ($from->id === $to->id) {
            $this->error('المصدر والهدف نفس الفرع — اختر فرعين مختلفين.');
            return self::FAILURE;
        }

        $this->line('');
        $this->line("سيتم نسخ القائمة:");
        $this->line("  من:  <info>{$from->name}</info>");
        $this->line("  إلى: <info>{$to->name}</info>");
        $this->line('');

        if (! $this->option('force') && ! $this->confirm('متابعة؟', true)) {
            $this->warn('تم الإلغاء.');
            return self::SUCCESS;
        }

        try {
            $stats = $service->duplicate($from, $to);
        } catch (\Throwable $e) {
            $this->error('فشل النسخ: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('');
        $this->info('✓ تم النسخ بنجاح:');
        $this->table(['البند', 'العدد'], [
            ['التصنيفات',           $stats['categories']],
            ['الأصناف',             $stats['items']],
            ['مجموعات الإضافات',    $stats['modifier_groups']],
            ['الإضافات',            $stats['modifiers']],
            ['عناصر الوصفات',       $stats['recipes']],
            ['وصفات الإضافات',      $stats['modifier_recipes']],
            ['روابط صنف↔إضافة',    $stats['item_modifier_links']],
            ['روابط صنف↔مسبب حساسية', $stats['item_allergen_links']],
        ]);

        if ($stats['items_without_station'] > 0) {
            $this->warn(sprintf(
                'تنبيه: %d صنف نُسخت بدون محطة لأن «%s» لا يحتوي على محطة بنفس الكود في فرع المصدر. حدّد لها محطة من الـ admin قبل ما تظهر على الـ KDS.',
                $stats['items_without_station'], $to->name
            ));
        }

        return self::SUCCESS;
    }

    protected function resolveBranch(?string $idOrCode, string $promptLabel): ?Branch
    {
        if (! $idOrCode) {
            $branches = Branch::orderBy('display_order')->get();
            if ($branches->isEmpty()) {
                $this->error('لا توجد فروع في النظام.');
                return null;
            }
            $choices = $branches->mapWithKeys(fn ($b) => [$b->code => "{$b->name} ({$b->code})"])->all();
            $idOrCode = $this->choice("اختر $promptLabel", $choices);
        }

        $branch = is_numeric($idOrCode)
            ? Branch::find((int) $idOrCode)
            : Branch::where('code', $idOrCode)->first();

        if (! $branch) {
            $this->error("لم يتم العثور على فرع بـ id/code = «{$idOrCode}».");
            return null;
        }

        return $branch;
    }
}
