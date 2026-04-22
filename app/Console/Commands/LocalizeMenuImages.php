<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads all remote (http*) images referenced by categories and menu_items
 * into local storage, then rewrites the DB column to the local path.
 *
 * Why: Unsplash URLs can rate-limit, change, or go offline. Local storage
 * gives us deterministic load times and offline demo capability.
 *
 * Usage:
 *   php artisan menu:localize-images             → downloads everything
 *   php artisan menu:localize-images --force     → re-download even if local copy exists
 *   php artisan menu:localize-images --dry-run   → report only, no writes
 */
class LocalizeMenuImages extends Command
{
    protected $signature = 'menu:localize-images {--force : Re-download images that are already local}
                                                  {--dry-run : Simulate without writing to DB or disk}';

    protected $description = 'Download remote (Unsplash etc.) images for categories + menu_items to local storage';

    public function handle(): int
    {
        $force  = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        // Ensure target folders exist
        Storage::disk('public')->makeDirectory('categories');
        Storage::disk('public')->makeDirectory('menu-items');

        $this->info("Localizing menu images...");
        if ($dryRun) $this->warn('🧪 DRY RUN — no changes will be made.');

        $totals = ['categories' => 0, 'menu_items' => 0, 'skipped' => 0, 'failed' => 0];

        // ── Categories ───────────────────────────────────────────────
        $this->line('');
        $this->info('## Categories');
        foreach (Category::all() as $cat) {
            $result = $this->localize($cat, 'categories', 'cat-'.$cat->id, $force, $dryRun);
            $totals[$result['bucket']] = ($totals[$result['bucket']] ?? 0) + 1;
            $this->printResult($cat->name, $result);
        }

        // ── Menu items ───────────────────────────────────────────────
        $this->line('');
        $this->info('## Menu Items');
        $items = MenuItem::all();
        $bar = $this->output->createProgressBar($items->count());
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%%  %message%");
        $bar->setMessage('starting...');
        $bar->start();

        foreach ($items as $item) {
            $bar->setMessage($item->name);
            $result = $this->localize($item, 'menu-items', 'item-'.$item->id, $force, $dryRun);
            $totals[$result['bucket']] = ($totals[$result['bucket']] ?? 0) + 1;
            $bar->advance();
        }
        $bar->finish();

        $this->line('');
        $this->line('');
        $this->info('── Summary ────────────────────────────');
        foreach ($totals as $k => $v) {
            $this->line(sprintf("  %-15s %d", $k.':', $v));
        }
        return self::SUCCESS;
    }

    /**
     * Download a model's image and update it. Returns ['bucket' => 'downloaded'|'skipped'|'failed', ...].
     */
    protected function localize(Model $model, string $dir, string $baseName, bool $force, bool $dryRun): array
    {
        $url = $model->image;

        // Not a remote URL — skip (already local, or empty)
        if (!$url || !str_starts_with($url, 'http')) {
            return ['bucket' => 'skipped', 'reason' => 'not remote', 'url' => $url];
        }

        // Derive extension from URL; fallback to jpg (Unsplash serves jpeg)
        $ext = $this->extFromUrl($url) ?: 'jpg';
        $targetPath = "{$dir}/{$baseName}.{$ext}";

        // Already localized and not forced? skip
        if (!$force && Storage::disk('public')->exists($targetPath)) {
            if (!$dryRun) {
                $model->forceFill(['image' => $targetPath])->save();
            }
            return ['bucket' => 'skipped', 'reason' => 'already exists', 'path' => $targetPath];
        }

        if ($dryRun) {
            return ['bucket' => 'downloaded', 'reason' => 'dry-run', 'path' => $targetPath, 'url' => $url];
        }

        try {
            $resp = Http::timeout(30)
                ->retry(2, 500)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 RelaxRestaurant/1.0'])
                ->get($url);

            if (!$resp->successful() || strlen($resp->body()) < 200) {
                throw new \RuntimeException("HTTP {$resp->status()} — response too small");
            }

            Storage::disk('public')->put($targetPath, $resp->body());
            $model->forceFill(['image' => $targetPath])->save();

            return ['bucket' => 'downloaded', 'path' => $targetPath, 'size' => strlen($resp->body())];
        } catch (\Throwable $e) {
            Log::warning("Failed to download image for {$dir}/{$baseName}: {$e->getMessage()}");
            return ['bucket' => 'failed', 'reason' => $e->getMessage(), 'url' => $url];
        }
    }

    protected function extFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']) ? $ext : null;
    }

    protected function printResult(string $name, array $result): void
    {
        $symbol = match ($result['bucket']) {
            'downloaded' => '<fg=green>✓</>',
            'skipped'    => '<fg=gray>·</>',
            'failed'     => '<fg=red>✗</>',
            default      => '?',
        };
        $msg = $result['reason'] ?? $result['path'] ?? '';
        $this->line("  {$symbol} {$name} ({$result['bucket']}) — " . \Illuminate\Support\Str::limit($msg, 60));
    }
}
