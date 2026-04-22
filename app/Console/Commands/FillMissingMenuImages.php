<?php

namespace App\Console\Commands;

use App\Models\MenuItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Last-resort fallback: for every menu item whose image is still a remote URL
 * (i.e. all download attempts failed), copy a local image from a same-category
 * sibling that DID successfully localize, so every card has something to show.
 *
 * This gives us 100% coverage without hammering Unsplash further. The admin
 * can later upload a proper photo via the admin UI.
 */
class FillMissingMenuImages extends Command
{
    protected $signature = 'menu:fill-missing-images';
    protected $description = 'Copy a local sibling image for any menu items still on a remote URL';

    public function handle(): int
    {
        $stillRemote = MenuItem::with('category')
            ->where('image', 'like', 'http%')
            ->get();

        if ($stillRemote->isEmpty()) {
            $this->info('✓ All menu items already have local images.');
            return self::SUCCESS;
        }

        $this->info("Found {$stillRemote->count()} items still on remote URLs — copying sibling images...");

        $filled = 0;
        foreach ($stillRemote as $item) {
            $sibling = MenuItem::where('category_id', $item->category_id)
                ->where('id', '!=', $item->id)
                ->where('image', 'not like', 'http%')
                ->whereNotNull('image')
                ->first();

            // Fallback to ANY local image if no sibling in this category has one
            if (!$sibling) {
                $sibling = MenuItem::where('image', 'not like', 'http%')
                    ->whereNotNull('image')
                    ->first();
            }

            if (!$sibling || !Storage::disk('public')->exists($sibling->image)) {
                $this->line("  <fg=red>✗</> {$item->name} — no sibling image to copy");
                continue;
            }

            $ext = pathinfo($sibling->image, PATHINFO_EXTENSION) ?: 'jpg';
            $targetPath = "menu-items/item-{$item->id}.{$ext}";

            Storage::disk('public')->copy($sibling->image, $targetPath);
            $item->forceFill(['image' => $targetPath])->save();

            $this->line("  <fg=green>✓</> {$item->name} ← copied from sibling ({$sibling->name})");
            $filled++;
        }

        $this->line('');
        $this->info("Filled {$filled} items.");
        return self::SUCCESS;
    }
}
