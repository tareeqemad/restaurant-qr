<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\File;

class PublicStorageService
{
    /**
     * Prepare the public upload path without depending on disabled PHP
     * functions. Existing files are copied only in direct mode and never
     * removed from storage/app/public.
     *
     * @return array{status:string, mode:string, message:string, path:string, command:?string, copied:bool}
     */
    public function prepare(bool $copyExisting = true): array
    {
        $mode = $this->mode();
        $source = storage_path('app/public');
        $link = public_path('storage');

        File::ensureDirectoryExists($source);

        if ($mode === 'direct') {
            if (is_link($link)) {
                return $this->result(
                    'danger',
                    $mode,
                    'PUBLIC_STORAGE_MODE=direct لكن public/storage ما زال رابطاً رمزياً. أزل الرابط أثناء نافذة صيانة ثم أعد أمر التحضير.',
                    $link,
                );
            }

            File::ensureDirectoryExists($link);
            $copied = false;
            if ($copyExisting && $this->directoryHasFiles($source)) {
                File::copyDirectory($source, $link);
                $copied = true;
            }

            return $this->result(
                is_writable($link) ? 'good' : 'danger',
                $mode,
                is_writable($link)
                    ? 'التخزين العام المباشر جاهز ولا يحتاج storage:link.'
                    : 'public/storage موجود لكنه غير قابل للكتابة.',
                $link,
                copied: $copied,
            );
        }

        if (is_link($link)) {
            $actual = realpath($link);
            $expected = realpath($source);

            return $this->result(
                $actual !== false && $actual === $expected ? 'good' : 'danger',
                $mode,
                $actual !== false && $actual === $expected
                    ? 'رابط public/storage صحيح.'
                    : 'رابط public/storage لا يشير إلى storage/app/public.',
                $link,
            );
        }

        if (file_exists($link)) {
            return $this->result(
                is_dir($link) && is_writable($link) ? 'warning' : 'danger',
                $mode,
                'public/storage مجلد عادي بينما الوضع linked. استخدم PUBLIC_STORAGE_MODE=direct أو استبدله برابط أثناء نافذة صيانة.',
                $link,
            );
        }

        if (function_exists('symlink') && @symlink($source, $link)) {
            return $this->result('good', $mode, 'تم إنشاء رابط public/storage.', $link);
        }

        $command = sprintf('ln -s %s %s', escapeshellarg($source), escapeshellarg($link));

        return $this->result(
            'warning',
            $mode,
            'الاستضافة تمنع إنشاء الرابط من PHP. نفّذ أمر ln الظاهر أو استخدم PUBLIC_STORAGE_MODE=direct.',
            $link,
            $command,
        );
    }

    /** @return array{status:string, mode:string, message:string, path:string, command:?string, copied:bool} */
    public function inspect(): array
    {
        $mode = $this->mode();
        $source = storage_path('app/public');
        $link = public_path('storage');

        if ($mode === 'direct') {
            if (is_link($link)) {
                return $this->result('danger', $mode, 'الوضع direct لكن public/storage رابط رمزي.', $link);
            }

            return $this->result(
                is_dir($link) && is_writable($link) ? 'good' : 'danger',
                $mode,
                is_dir($link) && is_writable($link)
                    ? 'التخزين العام المباشر جاهز.'
                    : 'public/storage المباشر غير موجود أو غير قابل للكتابة.',
                $link,
                'php artisan storage:prepare-public',
            );
        }

        if (is_link($link)) {
            $actual = realpath($link);
            $expected = realpath($source);

            return $this->result(
                $actual !== false && $actual === $expected ? 'good' : 'danger',
                $mode,
                $actual !== false && $actual === $expected
                    ? 'رابط public/storage صحيح.'
                    : 'رابط public/storage لا يشير إلى storage/app/public.',
                $link,
            );
        }

        if (file_exists($link)) {
            return $this->result(
                is_dir($link) && is_writable($link) ? 'warning' : 'danger',
                $mode,
                'public/storage مجلد عادي بينما الوضع linked.',
                $link,
                'اضبط PUBLIC_STORAGE_MODE=direct ثم نفّذ php artisan storage:prepare-public',
            );
        }

        $command = sprintf('ln -s %s %s', escapeshellarg($source), escapeshellarg($link));

        return $this->result('warning', $mode, 'رابط public/storage غير موجود.', $link, $command);
    }

    public function mode(): string
    {
        return config('filesystems.public_storage_mode') === 'direct' ? 'direct' : 'linked';
    }

    private function directoryHasFiles(string $path): bool
    {
        return is_dir($path) && File::allFiles($path) !== [];
    }

    /** @return array{status:string, mode:string, message:string, path:string, command:?string, copied:bool} */
    private function result(
        string $status,
        string $mode,
        string $message,
        string $path,
        ?string $command = null,
        bool $copied = false,
    ): array {
        return compact('status', 'mode', 'message', 'path', 'command', 'copied');
    }
}
