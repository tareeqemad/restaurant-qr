<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class MigrationStructureTest extends TestCase
{
    private const EXPECTED_APPLICATION_TABLES = 103;

    public function test_every_application_table_has_one_complete_migration_file(): void
    {
        $directory = dirname(__DIR__, 2).'/database/migrations';
        $allFiles = glob($directory.'/*.php') ?: [];
        $files = array_values(array_filter(
            $allFiles,
            fn (string $file) => preg_match('/^2026_08_12_\d{6}_create_.+_table\.php$/', basename($file)) === 1,
        ));

        $this->assertCount(
            self::EXPECTED_APPLICATION_TABLES,
            $files,
            'Update the audited table count only when a real application table is intentionally added or removed.'
        );
        $this->assertEmpty(glob($directory.'/*baseline*.php') ?: []);

        $seenTables = [];

        foreach ($files as $file) {
            $name = basename($file);
            $this->assertMatchesRegularExpression('/^2026_08_12_\d{6}_create_(.+)_table\.php$/', $name);
            preg_match('/_create_(.+)_table\.php$/', $name, $filenameMatch);

            $table = $filenameMatch[1];
            $this->assertArrayNotHasKey($table, $seenTables, "{$table} must have exactly one migration file.");
            $seenTables[$table] = $name;

            $contents = (string) file_get_contents($file);
            preg_match_all('/CREATE TABLE [`"]([^`"]+)[`"]/', $contents, $tableMatches);

            $this->assertCount(1, $tableMatches[1], "{$name} must contain one complete MySQL definition.");
            $this->assertSame([$table], array_values($tableMatches[1]));
            $this->assertStringNotContainsString('sqlite', strtolower($contents));
            $this->assertStringContainsString('ENGINE=InnoDB', $contents);
            $this->assertStringContainsString("Schema::dropIfExists('{$table}')", $contents);
        }

        $this->assertCount(self::EXPECTED_APPLICATION_TABLES, $seenTables);
    }

    public function test_additive_upgrade_migrations_are_explicit_and_never_drop_business_columns(): void
    {
        $directory = dirname(__DIR__, 2).'/database/migrations';
        $files = glob($directory.'/*_upgrade_*.php') ?: [];

        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertMatchesRegularExpression('/^2026_\d{2}_\d{2}_\d{6}_upgrade_.+\.php$/', basename($file));
            $this->assertStringContainsString('public function up(): void', $contents);
            $this->assertStringNotContainsString('dropColumn(', $contents);
            $this->assertStringNotContainsString('dropIfExists(', $contents);
        }
    }
}
