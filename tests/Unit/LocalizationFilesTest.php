<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use Tests\TestCase;

class LocalizationFilesTest extends TestCase
{
    public function test_arabic_and_english_translation_keys_match(): void
    {
        $arabicFiles = collect(glob(base_path('lang/ar/*.php')) ?: [])
            ->mapWithKeys(fn (string $path) => [basename($path) => $path]);
        $englishFiles = collect(glob(base_path('lang/en/*.php')) ?: [])
            ->mapWithKeys(fn (string $path) => [basename($path) => $path]);

        $this->assertSame(
            $arabicFiles->keys()->sort()->values()->all(),
            $englishFiles->keys()->sort()->values()->all(),
        );

        foreach ($arabicFiles as $filename => $arabicPath) {
            $englishPath = $englishFiles[$filename];

            $arabicKeys = array_keys(Arr::dot(require $arabicPath));
            $englishKeys = array_keys(Arr::dot(require $englishPath));

            sort($arabicKeys);
            sort($englishKeys);

            $this->assertSame($arabicKeys, $englishKeys, "Translation keys differ in {$filename}.");
        }
    }
}
