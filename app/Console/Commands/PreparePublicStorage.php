<?php

namespace App\Console\Commands;

use App\Services\Deployment\PublicStorageService;
use Illuminate\Console\Command;

class PreparePublicStorage extends Command
{
    protected $signature = 'storage:prepare-public
        {--no-copy : In direct mode, do not copy existing storage/app/public files.}';

    protected $description = 'Prepare public uploads for symlink-capable servers or restricted shared hosting.';

    public function handle(PublicStorageService $storage): int
    {
        $result = $storage->prepare(! $this->option('no-copy'));

        $this->line("Mode: {$result['mode']}");
        $this->line("Path: {$result['path']}");

        match ($result['status']) {
            'good' => $this->info('✓ '.$result['message']),
            'warning' => $this->warn('! '.$result['message']),
            default => $this->error('✗ '.$result['message']),
        };

        if ($result['copied']) {
            $this->line('Existing public files were copied without deleting the source.');
        }
        if ($result['command']) {
            $this->newLine();
            $this->line('Shell fallback:');
            $this->line($result['command']);
        }

        return $result['status'] === 'danger' ? self::FAILURE : self::SUCCESS;
    }
}
