<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Nightly safety-net backup of the MySQL database.
 *
 * On a local/on-prem server the branch is the source of truth between cloud
 * syncs (see docs/offline-sync-architecture.md), so a reliable gzipped dump —
 * kept locally and optionally pushed off-site — is the first line of defence
 * against branch hardware failure.
 *
 * Schedule (routes/console.php):
 *     Schedule::command('backup:run')->dailyAt('03:30');
 *
 * Manual run:
 *     php artisan backup:run                 # dump, rotate, upload if configured
 *     php artisan backup:run --no-upload     # local dump only
 *     php artisan backup:run --keep=30       # override retention for this run
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:run
                            {--keep= : Override how many local dumps to retain.}
                            {--no-upload : Skip the off-site disk upload.}';

    protected $description = 'Write a gzipped mysqldump, rotate old dumps, and upload off-site when configured.';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            $this->error("backup:run only supports the mysql connection (current: {$connection}).");

            return self::FAILURE;
        }

        $db = config("database.connections.{$connection}");
        $disk = Storage::disk('local');
        $dir = trim((string) config('backup.local_path'), '/');
        $disk->makeDirectory($dir);

        $stamp = Carbon::now()->format('Y-m-d_His');
        $filename = "{$db['database']}_{$stamp}.sql.gz";
        $absPath = $disk->path("{$dir}/{$filename}");

        $this->info("Dumping {$db['database']} → {$dir}/{$filename}…");

        if (! $this->dump($db, $absPath)) {
            // dump() already cleaned up and reported the failure.
            return self::FAILURE;
        }

        $bytes = $disk->size("{$dir}/{$filename}");
        $this->info('Dump complete ('.$this->humanBytes($bytes).').');

        if (! $this->option('no-upload')) {
            $this->upload("{$dir}/{$filename}", $filename);
        }

        $this->rotate($disk, $dir);

        return self::SUCCESS;
    }

    /**
     * Dump, then gzip it. Compression goes through PHP's zlib extension so
     * this safety-net also works on Windows, where a standalone `gzip`
     * executable is normally unavailable.
     *
     * Credentials go through a temporary defaults-file (chmod 600) so the
     * password never appears in the process list. Returns false and removes
     * any partial files on failure.
     */
    private function dump(array $db, string $absPath): bool
    {
        $rawPath = substr($absPath, 0, -3); // strip the ".gz" → the .sql path

        $defaults = tempnam(sys_get_temp_dir(), 'mysqldump_');
        file_put_contents($defaults, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n",
            $db['host'],
            $db['port'],
            $db['username'],
            $db['password'],
        ));
        @chmod($defaults, 0600);

        $process = new Process([
            $this->dumpBinary(),
            "--defaults-extra-file={$defaults}",
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            "--result-file={$rawPath}",
            (string) $db['database'],
        ], base_path(), null, null, 1800);

        try {
            $process->run();
        } finally {
            @unlink($defaults);
        }

        if (! $process->isSuccessful() || ! is_file($rawPath) || filesize($rawPath) === 0) {
            @unlink($rawPath);
            $error = trim($process->getErrorOutput()) ?: 'mysqldump produced no output.';
            $this->error("Backup failed: {$error}");
            Log::error('Database backup failed', ['error' => $error]);

            return false;
        }

        if (! $this->gzip($rawPath, $absPath)) {
            @unlink($rawPath);
            @unlink($absPath);
            $error = 'PHP zlib could not compress the verified database dump.';
            $this->error("Backup failed: {$error}");
            Log::error('Database backup failed', ['error' => $error]);

            return false;
        }

        return true;
    }

    private function dumpBinary(): string
    {
        $configured = (string) config('backup.mysqldump_binary', 'mysqldump');

        if ($configured !== 'mysqldump' || PHP_OS_FAMILY !== 'Windows') {
            return $configured;
        }

        foreach ([
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }

    private function gzip(string $source, string $destination): bool
    {
        if (! function_exists('gzopen')) {
            return false;
        }

        $input = @fopen($source, 'rb');
        $output = @gzopen($destination, 'wb9');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }

            return false;
        }

        $ok = true;
        while (! feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false || ($chunk !== '' && gzwrite($output, $chunk) === false)) {
                $ok = false;
                break;
            }
        }

        fclose($input);
        gzclose($output);

        if ($ok) {
            @unlink($source);
        }

        return $ok && is_file($destination) && filesize($destination) > 0;
    }

    /**
     * Copy the fresh dump to the configured off-site disk. Best-effort: a
     * failed upload is logged but never fails the command, since the local
     * dump already succeeded.
     */
    private function upload(string $localRelPath, string $filename): void
    {
        $diskName = config('backup.disk');

        if (empty($diskName)) {
            $this->line('No off-site disk configured (backup.disk) — keeping local only.');

            return;
        }

        $remotePath = trim((string) config('backup.disk_path'), '/')."/{$filename}";

        try {
            $stream = Storage::disk('local')->readStream($localRelPath);
            Storage::disk($diskName)->writeStream($remotePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $this->info("Uploaded off-site → [{$diskName}] {$remotePath}");
        } catch (\Throwable $e) {
            $this->warn("Off-site upload failed (kept locally): {$e->getMessage()}");
            Log::warning('Backup off-site upload failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Keep only the newest N dumps locally; prune the rest.
     */
    private function rotate($disk, string $dir): void
    {
        $keep = $this->option('keep') !== null
            ? max(1, (int) $this->option('keep'))
            : max(1, (int) config('backup.keep'));

        $dumps = collect($disk->files($dir))
            ->filter(fn ($f) => str_ends_with($f, '.sql.gz'))
            ->sortDesc()   // timestamped names sort chronologically
            ->values();

        $stale = $dumps->slice($keep);

        foreach ($stale as $file) {
            $disk->delete($file);
        }

        if ($stale->isNotEmpty()) {
            $this->line("Pruned {$stale->count()} old dump(s), retaining {$keep}.");
        }
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1).' '.$units[$i];
    }
}
