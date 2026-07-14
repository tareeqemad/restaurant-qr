<?php

namespace App\Console\Commands;

use App\Models\MenuItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fixes menu items whose Unsplash URL returned 404 in the localize-images
 * command. Maps each failed SKU to a known-working alternative Unsplash ID
 * and downloads it. Idempotent: safe to run multiple times.
 */
class FixMissingMenuImages extends Command
{
    protected $signature = 'menu:fix-missing-images';
    protected $description = 'Replace 404-ing menu image URLs with working alternatives + download locally';

    /**
     * SKU → known-working replacement URL.
     * Every URL here was HEAD-verified (HTTP 200) on 2026-07-14 and matches
     * the same URLs used by RealRestaurantMenuSeeder + menu:repair-dead-images,
     * so the three sources never disagree about a dish's canonical photo.
     */
    protected array $replacements = [
        'APP-01' => 'https://images.unsplash.com/photo-1637949385162-e416fb15b2ce?w=600&q=80', // hummus
        'APP-02' => 'https://images.unsplash.com/photo-1627308595127-d9acf19107ce?w=600&q=80', // eggplant dip
        'APP-03' => 'https://images.unsplash.com/photo-1621880099609-68eb25395c8f?w=600&q=80', // baba ghanoush
        'APP-07' => 'https://images.unsplash.com/photo-1649434150059-13fee33e1de4?w=600&q=80', // stuffed zucchini
        'APP-11' => 'https://images.unsplash.com/photo-1547058881-aa0edd92aab3?w=600&q=80', // falafel

        'MAIN-01' => 'https://images.unsplash.com/photo-1631515243349-e0cb75fb8d3a?w=600&q=80', // mansaf (rice + meat + yogurt)
        'MAIN-03' => 'https://images.unsplash.com/photo-1589302168068-964664d93dc0?w=600&q=80', // chicken maqluba
        'MAIN-05' => 'https://images.unsplash.com/photo-1610057099431-d73a1c9d2f2f?w=600&q=80',   // musakhan

        'SND-04' => 'https://images.unsplash.com/photo-1529006557810-274b9b2fc783?w=600&q=80', // shawarma
        'SND-09' => 'https://images.unsplash.com/photo-1624300629298-e9de39c13be5?w=600&q=80', // fajita
        'SND-10' => 'https://images.unsplash.com/photo-1604908816649-c8bdfc3ca68b?w=600&q=80', // falafel wrap

        'DRK-06' => 'https://images.unsplash.com/photo-1618597778480-8c5d3f2d3ba8?w=600&q=80', // arabic coffee (dallah)

        'DES-03' => 'https://images.unsplash.com/photo-1626256157372-17bd6f8e8d50?w=600&q=80', // umm ali (bread pudding + cream)
        'DES-06' => 'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=600&q=80', // qatayef (pancake stack)
        'DES-09' => 'https://images.unsplash.com/photo-1501443762994-82bd5dace89a?w=600&q=80', // ice cream
    ];

    public function handle(): int
    {
        Storage::disk('public')->makeDirectory('menu-items');

        $this->info('Re-attempting images for {SKU} with replacement URLs...');

        $fixed = 0;
        $failed = 0;

        foreach ($this->replacements as $sku => $newUrl) {
            $item = MenuItem::where('sku', $sku)->first();
            if (!$item) {
                $this->line("  <fg=gray>·</> {$sku} not found");
                continue;
            }
            if ($item->image && !str_starts_with($item->image, 'http')) {
                $this->line("  <fg=gray>·</> {$sku} already local — skip");
                continue;
            }

            $targetPath = "menu-items/item-{$item->id}.jpg";
            try {
                $resp = Http::timeout(30)->retry(2, 750)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 RelaxRestaurant/1.0'])
                    ->get($newUrl);

                if (!$resp->successful() || strlen($resp->body()) < 200) {
                    throw new \RuntimeException("HTTP {$resp->status()}");
                }

                Storage::disk('public')->put($targetPath, $resp->body());
                $item->forceFill(['image' => $targetPath])->save();

                $this->line("  <fg=green>✓</> {$sku} ({$item->name}) → {$targetPath} (".number_format(strlen($resp->body())).")");
                $fixed++;
            } catch (\Throwable $e) {
                $this->line("  <fg=red>✗</> {$sku}: ".$e->getMessage());
                $failed++;
            }
        }

        $this->line('');
        $this->info("Fixed {$fixed}, failed {$failed}.");
        return self::SUCCESS;
    }
}
