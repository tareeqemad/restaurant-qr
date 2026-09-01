<?php

namespace Tests\Unit;

use Tests\TestCase;

class LocalizationFilesTest extends TestCase
{
    public function test_application_has_only_arabic_translation_files(): void
    {
        $arabicFiles = collect(glob(base_path('lang/ar/*.php')) ?: [])
            ->mapWithKeys(fn (string $path) => [basename($path) => $path]);

        $this->assertNotEmpty($arabicFiles);
        $this->assertDirectoryDoesNotExist(base_path('lang/en'));
        $this->assertSame('ar', config('app.locale'));
        $this->assertSame('ar', config('app.fallback_locale'));
    }
}
