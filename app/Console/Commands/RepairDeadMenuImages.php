<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Production repair for dead remote menu images.
 *
 * Unsplash occasionally removes photos, which turns hotlinked menu images
 * into 404s. This command HEAD-checks every category + menu-item whose
 * `image` is still an http(s) URL and, for each dead one:
 *
 *   1. If the dish/category is in the verified map below (keyed by SKU /
 *      name / slug) → swap in the replacement URL. Every mapped URL was
 *      HEAD-verified (HTTP 200) on 2026-07-14 and matches the URLs used by
 *      RealRestaurantMenuSeeder + menu:fix-missing-images.
 *   2. Otherwise → clear `image` to NULL so imageUrl() serves the local
 *      placeholder instead of a broken <img>.
 *
 * Network failures (timeouts, DNS, no internet) are NOT treated as dead —
 * those rows are skipped so a flaky connection can never wipe the menu.
 *
 * Idempotent: replacements are live URLs (pass the next check) and NULLs
 * are no longer remote (not selected). Safe to re-run any time.
 *
 * Usage:
 *   php artisan menu:repair-dead-images --dry-run   → report only
 *   php artisan menu:repair-dead-images             → apply repairs
 */
class RepairDeadMenuImages extends Command
{
    protected $signature = 'menu:repair-dead-images {--dry-run : Report only — no DB writes}';

    protected $description = 'HEAD-check remote category/menu-item images; replace dead ones from the verified map, else clear to NULL';

    /**
     * SKU → verified replacement URL (the canonical seeded photo per dish).
     * Full seeded menu so ANY future Unsplash removal is repairable, not
     * just the ones dead today.
     */
    protected array $itemUrlsBySku = [
        // ── المقبلات ────────────────────────────────────────────────
        'APP-01'  => 'https://images.unsplash.com/photo-1637949385162-e416fb15b2ce?w=600&q=80', // حمص
        'APP-02'  => 'https://images.unsplash.com/photo-1627308595127-d9acf19107ce?w=600&q=80', // متبل
        'APP-03'  => 'https://images.unsplash.com/photo-1621880099609-68eb25395c8f?w=600&q=80', // بابا غنوج
        'APP-04'  => 'https://images.unsplash.com/photo-1551504734-5ee1c4a1479b?w=600&q=80',   // لبنة
        'APP-05'  => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?w=600&q=80', // فتوش
        'APP-06'  => 'https://images.unsplash.com/photo-1606756790138-261d2b21cd75?w=600&q=80', // ورق عنب
        'APP-07'  => 'https://images.unsplash.com/photo-1649434150059-13fee33e1de4?w=600&q=80', // محاشي كوسا
        'APP-08'  => 'https://images.unsplash.com/photo-1574484284002-952d92456975?w=600&q=80', // كبة مقلية
        'APP-09'  => 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=600&q=80', // بطاطا مقلية
        'APP-10'  => 'https://images.unsplash.com/photo-1598866594230-a7c12756260f?w=600&q=80', // مقلوبة صغيرة
        'APP-11'  => 'https://images.unsplash.com/photo-1547058881-aa0edd92aab3?w=600&q=80',   // فلافل

        // ── الأطباق الرئيسية ────────────────────────────────────────
        'MAIN-01' => 'https://images.unsplash.com/photo-1631515243349-e0cb75fb8d3a?w=600&q=80', // منسف
        'MAIN-02' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=600&q=80',   // مقلوبة لحم
        'MAIN-03' => 'https://images.unsplash.com/photo-1589302168068-964664d93dc0?w=600&q=80', // مقلوبة دجاج
        'MAIN-04' => 'https://images.unsplash.com/photo-1565557623262-b51c2513a641?w=600&q=80', // كبسة دجاج
        'MAIN-05' => 'https://images.unsplash.com/photo-1610057099431-d73a1c9d2f2f?w=600&q=80', // مسخن
        'MAIN-06' => 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=600&q=80', // فريكة بالدجاج
        'MAIN-07' => 'https://images.unsplash.com/photo-1599021456807-25db0f974333?w=600&q=80', // مجدرة
        'MAIN-08' => 'https://images.unsplash.com/photo-1529193591184-b1d58069ecdd?w=600&q=80', // شيش طاووق
        'MAIN-09' => 'https://images.unsplash.com/photo-1518291344630-4857135fb581?w=600&q=80', // قدرة خليلية
        'MAIN-10' => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?w=600&q=80', // صيادية

        // ── المشاوي ─────────────────────────────────────────────────
        'GRL-01'  => 'https://images.unsplash.com/photo-1529193591184-b1d58069ecdd?w=600&q=80', // كباب لحم
        'GRL-02'  => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=600&q=80',   // كباب دجاج
        'GRL-03'  => 'https://images.unsplash.com/photo-1544025162-d76694265947?w=600&q=80',   // كفتة مشوية
        'GRL-04'  => 'https://images.unsplash.com/photo-1558030006-450675393462?w=600&q=80',   // ريش غنم
        'GRL-05'  => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=600&q=80',   // شقف لحم
        'GRL-06'  => 'https://images.unsplash.com/photo-1598103442097-8b74394b95c6?w=600&q=80', // فراخ مشوي
        'GRL-07'  => 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?w=600&q=80', // سمك مشوي
        'GRL-08'  => 'https://images.unsplash.com/photo-1558030006-450675393462?w=600&q=80',   // مشاوي مشكل
        'GRL-09'  => 'https://images.unsplash.com/photo-1599487488170-d11ec9c172f0?w=600&q=80', // تكا دجاج
        'GRL-10'  => 'https://images.unsplash.com/photo-1598103442097-8b74394b95c6?w=600&q=80', // صدر دجاج مشوي

        // ── السندويشات ──────────────────────────────────────────────
        'SND-01'  => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=600&q=80', // برجر لحم
        'SND-02'  => 'https://images.unsplash.com/photo-1606755962773-d324e0a13086?w=600&q=80', // برجر دجاج
        'SND-03'  => 'https://images.unsplash.com/photo-1633321702518-7feccafb94d5?w=600&q=80', // شاورما دجاج
        'SND-04'  => 'https://images.unsplash.com/photo-1529006557810-274b9b2fc783?w=600&q=80', // شاورما لحم
        'SND-05'  => 'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=600&q=80', // كلوب سندويتش
        'SND-06'  => 'https://images.unsplash.com/photo-1539252554453-80ab65ce3586?w=600&q=80', // سندويتش تونة
        'SND-07'  => 'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=600&q=80', // سندويتش جبنة
        'SND-08'  => 'https://images.unsplash.com/photo-1482049016688-2d3e1b311543?w=600&q=80', // سندويتش بيض
        'SND-09'  => 'https://images.unsplash.com/photo-1624300629298-e9de39c13be5?w=600&q=80', // فاهيتا
        'SND-10'  => 'https://images.unsplash.com/photo-1604908816649-c8bdfc3ca68b?w=600&q=80', // لفة فلافل
        'SND-11'  => 'https://images.unsplash.com/photo-1619740455993-9e612b1af08a?w=600&q=80', // هوت دوغ

        // ── السلطات ─────────────────────────────────────────────────
        'SAL-01'  => 'https://images.unsplash.com/photo-1550304943-4f24f54ddde9?w=600&q=80',   // سلطة سيزر
        'SAL-02'  => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?w=600&q=80', // تبولة
        'SAL-03'  => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=600&q=80',   // فتوش
        'SAL-04'  => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?w=600&q=80', // سلطة عربية
        'SAL-05'  => 'https://images.unsplash.com/photo-1529059997568-3d847b1154f0?w=600&q=80', // سلطة يونانية
        'SAL-06'  => 'https://images.unsplash.com/photo-1607532941433-304659e8198a?w=600&q=80', // سلطة كولسلو
        'SAL-07'  => 'https://images.unsplash.com/photo-1546793665-c74683f339c1?w=600&q=80',   // سلطة دجاج
        'SAL-08'  => 'https://images.unsplash.com/photo-1505253716362-afaea1d3d1af?w=600&q=80', // سلطة تونة
        'SAL-09'  => 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?w=600&q=80', // سلطة روكا
        'SAL-10'  => 'https://images.unsplash.com/photo-1490474418585-ba9bad8fd0ea?w=600&q=80', // سلطة فواكه

        // ── المشروبات ───────────────────────────────────────────────
        'DRK-01'  => 'https://images.unsplash.com/photo-1621506289937-a8e4df240d0b?w=600&q=80', // عصير برتقال
        'DRK-02'  => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&q=80',   // ليمون بالنعناع
        'DRK-03'  => 'https://images.unsplash.com/photo-1546171753-97d7676e4602?w=600&q=80',   // عصير مانجو
        'DRK-04'  => 'https://images.unsplash.com/photo-1557825835-70d97c4aa567?w=600&q=80',   // عصير فراولة
        'DRK-05'  => 'https://images.unsplash.com/photo-1541658016709-82535e94bc69?w=600&q=80', // ميلك شيك
        'DRK-06'  => 'https://images.unsplash.com/photo-1618597778480-8c5d3f2d3ba8?w=600&q=80', // قهوة عربية
        'DRK-07'  => 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=600&q=80', // قهوة تركية
        'DRK-08'  => 'https://images.unsplash.com/photo-1558160074-4d7d8bdf4256?w=600&q=80',   // شاي بالنعناع
        'DRK-09'  => 'https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=600&q=80', // كابتشينو
        'DRK-10'  => 'https://images.unsplash.com/photo-1561882468-9110e03e0f78?w=600&q=80',   // لاتيه
        'DRK-11'  => 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=600&q=80',   // مياه معدنية
        'DRK-12'  => 'https://images.unsplash.com/photo-1581636625402-29b2a704ef13?w=600&q=80', // مشروبات غازية

        // ── الحلويات ────────────────────────────────────────────────
        'DES-01'  => 'https://images.unsplash.com/photo-1541443131876-44b03de101c5?w=600&q=80', // كنافة
        'DES-02'  => 'https://images.unsplash.com/photo-1519915028121-7d3463d20b13?w=600&q=80', // بقلاوة
        'DES-03'  => 'https://images.unsplash.com/photo-1626256157372-17bd6f8e8d50?w=600&q=80', // أم علي
        'DES-04'  => 'https://images.unsplash.com/photo-1546039907-7fa05f864c02?w=600&q=80',   // بسبوسة
        'DES-05'  => 'https://images.unsplash.com/photo-1551024506-0bccd828d307?w=600&q=80',   // مهلبية
        'DES-06'  => 'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=600&q=80', // قطايف
        'DES-07'  => 'https://images.unsplash.com/photo-1533134242443-d4fd215305ad?w=600&q=80', // تشيز كيك
        'DES-08'  => 'https://images.unsplash.com/photo-1571877227200-a0d98ea607e9?w=600&q=80', // تيراميسو
        'DES-09'  => 'https://images.unsplash.com/photo-1501443762994-82bd5dace89a?w=600&q=80', // آيس كريم
        'DES-10'  => 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=600&q=80', // براوني
        'DES-11'  => 'https://images.unsplash.com/photo-1490474418585-ba9bad8fd0ea?w=600&q=80', // فواكه موسمية
    ];

    /**
     * Arabic dish name → SKU fallback, for items whose SKU was edited but the
     * name still matches a known seeded dish. "فلافل" intentionally maps to
     * the plate photo (APP-11) since the bare name is ambiguous with SND-10.
     */
    protected array $skuByName = [
        'حمص' => 'APP-01', 'متبل' => 'APP-02', 'بابا غنوج' => 'APP-03',
        'محاشي كوسا' => 'APP-07', 'فلافل' => 'APP-11', 'منسف' => 'MAIN-01',
        'مقلوبة دجاج' => 'MAIN-03', 'مسخن' => 'MAIN-05', 'شاورما لحم' => 'SND-04',
        'فاهيتا' => 'SND-09', 'قهوة عربية' => 'DRK-06', 'أم علي' => 'DES-03',
        'قطايف' => 'DES-06', 'آيس كريم' => 'DES-09',
    ];

    /** Category slug → verified URL (seeded categories, all alive 2026-07-14). */
    protected array $categoryUrlsBySlug = [
        'appetizers'   => 'https://images.unsplash.com/photo-1541014741259-de529411b96a?w=600&q=80',
        'main-courses' => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=600&q=80',
        'grill'        => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=600&q=80',
        'sandwiches'   => 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=600&q=80',
        'salads'       => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?w=600&q=80',
        'drinks'       => 'https://images.unsplash.com/photo-1544145945-f90425340c7e?w=600&q=80',
        'desserts'     => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=600&q=80',
    ];

    /** @var array<string,bool|null> URL → true (alive) / false (dead) / null (unreachable) */
    protected array $checked = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('🧪 DRY RUN — no changes will be made.');
        }

        $totals = ['alive' => 0, 'replaced' => 0, 'cleared' => 0, 'unreachable' => 0];
        $rows = [];

        // ── Categories ───────────────────────────────────────────────
        foreach (Category::where('image', 'like', 'http%')->get() as $cat) {
            $replacement = $this->categoryUrlsBySlug[$cat->slug] ?? null;
            $this->process($cat, 'category', $cat->name, $cat->slug, $replacement, $dryRun, $totals, $rows);
        }

        // ── Menu items ───────────────────────────────────────────────
        foreach (MenuItem::where('image', 'like', 'http%')->get() as $item) {
            $sku = $item->sku ?: ($this->skuByName[$item->name] ?? null);
            $replacement = $this->itemUrlsBySku[$sku ?? ''] ?? null;
            // SKU present but unknown to the map → try the name fallback.
            if ($replacement === null && isset($this->skuByName[$item->name])) {
                $replacement = $this->itemUrlsBySku[$this->skuByName[$item->name]] ?? null;
            }
            $this->process($item, 'item', $item->name, $item->sku ?? '—', $replacement, $dryRun, $totals, $rows);
        }

        // ── Summary ──────────────────────────────────────────────────
        $this->line('');
        if (empty($rows)) {
            $this->info('✓ All remote images are alive — nothing to repair.');
        } else {
            $this->table(['Type', 'Name', 'SKU/Slug', 'Status', 'Action'], $rows);
        }

        $this->info(sprintf(
            'alive: %d · replaced: %d · cleared→NULL: %d · unreachable(skipped): %d%s',
            $totals['alive'], $totals['replaced'], $totals['cleared'], $totals['unreachable'],
            $dryRun ? ' (dry-run — nothing saved)' : ''
        ));

        if ($totals['unreachable'] > 0) {
            $this->warn('Some URLs could not be checked (network issue?) — they were left untouched. Re-run later.');
        }

        return self::SUCCESS;
    }

    /**
     * Check one model's remote image and repair it if dead.
     * Mutates $totals / $rows by reference to keep handle() readable.
     */
    protected function process(
        $model,
        string $type,
        string $name,
        string $key,
        ?string $replacement,
        bool $dryRun,
        array &$totals,
        array &$rows,
    ): void {
        $alive = $this->isAlive($model->image);

        if ($alive === true) {
            $totals['alive']++;

            return;
        }

        if ($alive === null) {
            // Timeout/DNS/no-internet — NOT proof the image is gone. Never
            // rewrite on an unreachable check or a flaky connection could
            // wipe every image in one run.
            $totals['unreachable']++;
            $rows[] = [$type, $name, $key, 'unreachable', 'skipped'];

            return;
        }

        // Definitely dead (404/410). Guard against a map entry that has
        // itself died: replacing a dead URL with the identical dead URL
        // would report "replaced" while fixing nothing, forever.
        if ($replacement !== null && $replacement !== $model->image) {
            if (! $dryRun) {
                $model->forceFill(['image' => $replacement])->save();
            }
            $totals['replaced']++;
            $rows[] = [$type, $name, $key, 'dead', 'replaced from map'];

            return;
        }

        if (! $dryRun) {
            $model->forceFill(['image' => null])->save();
        }
        $totals['cleared']++;
        $rows[] = [$type, $name, $key, 'dead', 'cleared → placeholder'];
    }

    /**
     * HEAD-check a URL. true = alive (2xx after redirects), false = dead,
     * null = could not decide (connection error, rate-limit, origin outage).
     * Cached per URL since seeded dishes share photos.
     *
     * Only 404/410 count as dead: those mean "this image is GONE". Anything
     * else that isn't 2xx (403, 429, 5xx…) is transient or ambiguous — a
     * rate-limited or briefly-down CDN must never cause a rewrite, or one
     * bad minute could wipe the whole menu's imagery.
     */
    protected function isAlive(string $url): ?bool
    {
        if (array_key_exists($url, $this->checked)) {
            return $this->checked[$url];
        }

        try {
            $resp = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 RelaxRestaurant/1.0'])
                ->head($url);

            if ($resp->successful()) {
                return $this->checked[$url] = true;
            }

            return $this->checked[$url] = in_array($resp->status(), [404, 410], true) ? false : null;
        } catch (\Throwable $e) {
            return $this->checked[$url] = null;
        }
    }
}
