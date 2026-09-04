<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LocalDateInputUiTest extends TestCase
{
    public function test_business_date_inputs_use_local_calendar_parts_instead_of_utc(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $helper = file_get_contents($projectRoot.'/resources/js/Utils/dateInput.js');

        $this->assertIsString($helper);
        $this->assertStringContainsString('getFullYear()', $helper);
        $this->assertStringContainsString('getMonth() + 1', $helper);
        $this->assertStringContainsString('getDate()', $helper);

        $javascript = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $projectRoot.'/resources/js',
            FilesystemIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $file */
        foreach ($javascript as $file) {
            if (! in_array($file->getExtension(), ['js', 'vue'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            $this->assertDoesNotMatchRegularExpression(
                '/toISOString\(\)\.slice\(0,\s*10\)/',
                $contents,
                $file->getPathname().' must not derive a local business date from UTC.',
            );
        }
    }
}
