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
     * Every URL here has been manually verified.
     */
    protected array $replacements = [
        'APP-01' => 'https://images.unsplash.com/photo-1637048824018-0537c3424d68?w=600&q=80', // hummus
        'APP-02' => 'https://images.unsplash.com/photo-1626198226928-41c4dce23a9a?w=600&q=80', // eggplant dip
        'APP-03' => 'https://images.unsplash.com/photo-1625938144715-c8620bc3a28c?w=600&q=80', // baba ghanoush
        'APP-07' => 'https://images.unsplash.com/photo-1625944525533-473f1b3d9684?w=600&q=80', // stuffed zucchini
        'APP-11' => 'https://images.unsplash.com/photo-1605522568624-a1b6c17b36c5?w=600&q=80', // falafel

        'MAIN-01' => 'https://images.unsplash.com/photo-1633945274405-b6c8b60abf66?w=600&q=80', // mansaf (rice + meat)
        'MAIN-03' => 'https://images.unsplash.com/photo-1585937421612-70a008356fbe?w=600&q=80', // chicken maqluba
        'MAIN-05' => 'https://images.unsplash.com/photo-1560717845-968823efbee1?w=600&q=80',   // musakhan

        'SND-04' => 'https://images.unsplash.com/photo-1606755962773-d324e0a13086?w=600&q=80', // shawarma
        'SND-09' => 'https://images.unsplash.com/photo-1624300629298-e9de39c13be5?w=600&q=80', // fajita
        'SND-10' => 'https://images.unsplash.com/photo-1586816001966-79b736744398?w=600&q=80', // falafel wrap

        'DRK-06' => 'https://images.unsplash.com/photo-1497935586351-b67a49e012bf?w=600&q=80', // arabic coffee

        'DES-03' => 'https://images.unsplash.com/photo-1519915028121-7d3463d20b13?w=600&q=80', // umm ali (baklava-ish fallback)
        'DES-06' => 'https://images.unsplash.com/photo-1541443131876-44b03de101c5?w=600&q=80', // qatayef
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
