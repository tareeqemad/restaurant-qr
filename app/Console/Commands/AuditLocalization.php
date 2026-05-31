<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AuditLocalization extends Command
{
    protected $signature = 'localization:audit
        {--paths=resources/views,app,routes,config : Comma-separated paths to scan}
        {--fail : Return a failing exit code when hardcoded Arabic remains}';

    protected $description = 'Report hardcoded Arabic UI text that still needs to move into lang files.';

    public function handle(): int
    {
        $paths = collect(explode(',', (string) $this->option('paths')))
            ->map(fn (string $path) => trim($path))
            ->filter()
            ->values();

        $matches = [];

        foreach ($paths as $path) {
            $root = base_path($path);

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                if (! $this->isScannable($file)) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', $file->getPathname());
                $relativePath = str_replace(str_replace('\\', '/', base_path()).'/', '', $relativePath);
                $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

                foreach ($lines as $lineNumber => $line) {
                    if (preg_match('/\p{Arabic}/u', $line) !== 1) {
                        continue;
                    }

                    $matches[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber + 1,
                        'text' => trim(preg_replace('/\s+/u', ' ', $line) ?? $line),
                    ];
                }
            }
        }

        $fileCount = collect($matches)->pluck('file')->unique()->count();

        if ($matches === []) {
            $this->info('No hardcoded Arabic text found in scanned source paths.');

            return self::SUCCESS;
        }

        $this->warn("Hardcoded Arabic remains: {$fileCount} file(s), ".count($matches).' line(s).');
        $this->line('Top files:');

        collect($matches)
            ->countBy('file')
            ->sortDesc()
            ->take(20)
            ->each(fn (int $count, string $file) => $this->line("  {$count}  {$file}"));

        $this->newLine();
        $this->line('Sample lines:');

        collect($matches)
            ->take(20)
            ->each(fn (array $match) => $this->line("  {$match['file']}:{$match['line']}  {$match['text']}"));

        return $this->option('fail') ? self::FAILURE : self::SUCCESS;
    }

    private function isScannable(SplFileInfo $file): bool
    {
        $path = str_replace('\\', '/', $file->getPathname());

        if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
            return false;
        }

        return in_array($file->getExtension(), ['php', 'js', 'vue'], true)
            || str_ends_with($file->getFilename(), '.blade.php');
    }
}
