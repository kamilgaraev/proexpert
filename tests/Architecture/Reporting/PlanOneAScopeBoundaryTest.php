<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PlanOneAScopeBoundaryTest extends TestCase
{
    public function test_plan_one_a_contains_no_reporting_jobs_or_queue_dispatch(): void
    {
        self::assertFalse($this->reportingSourceContains('dispatch(') || is_dir($this->reportingRoot().'/Jobs'));
    }

    public function test_plan_one_a_contains_no_persistence_or_storage_implementation(): void
    {
        self::assertFalse(
            is_dir($this->reportingRoot().'/Persistence')
                || is_dir($this->reportingRoot().'/Storage'),
        );
    }

    public function test_plan_one_a_contains_no_catalog_loader_or_manifest_yaml(): void
    {
        self::assertFalse($this->reportingSourceContains('yaml_parse') || $this->reportingSourceContains('ManifestLoader'));
    }

    public function test_plan_one_a_contains_no_provider_formula_implementation(): void
    {
        self::assertFalse($this->reportingSourceContains('FormulaProvider'));
    }

    public function test_plan_one_a_contains_no_migration_owned_by_reporting(): void
    {
        self::assertSame([], glob($this->root().'/database/migrations/*reporting*') ?: []);
    }

    public function test_plan_one_a_contains_no_ui_code(): void
    {
        self::assertFalse(
            $this->reportingSourceContains('<script')
                || $this->reportingSourceContains('React.')
                || $this->reportingSourceContains('flutter'),
        );
    }

    private function reportingSourceContains(string $needle): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->reportingRoot(), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo
                && $file->isFile()
                && $file->getExtension() === 'php'
                && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function reportingRoot(): string
    {
        return $this->root().'/app/BusinessModules/Core/Reporting';
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
