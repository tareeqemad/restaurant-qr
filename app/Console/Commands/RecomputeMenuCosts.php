<?php

namespace App\Console\Commands;

use App\Services\RecipeCostService;
use Illuminate\Console\Command;

class RecomputeMenuCosts extends Command
{
    protected $signature = 'menu:recompute-costs';

    protected $description = 'Recompute menu_items.cost for every item based on its recipe ingredients.';

    public function handle(RecipeCostService $service): int
    {
        $this->info('Recomputing menu item costs from recipes...');
        $changed = $service->recomputeAll();
        $this->info("Done. {$changed} item(s) had their cost updated.");
        return self::SUCCESS;
    }
}
