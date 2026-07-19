<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class LegalDocumentVersionArchitectureTest extends TestCase
{
    public function test_query_builder_cannot_mutate_versions_outside_approved_service(): void
    {
        $root = realpath(__DIR__.'/../../../app');
        self::assertIsString($root);
        $approved = str_replace('\\', '/', realpath(
            __DIR__.'/../../../app/Services/LegalArchive/Files/LegalDocumentFileService.php',
        ) ?: '');
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if ($path === $approved) {
                continue;
            }
            $source = file_get_contents($path);
            self::assertIsString($source);
            if (preg_match('/LegalArchiveDocumentVersion::query\(\)[\s\S]{0,300}?->(?:update|delete)\s*\(/', $source) === 1) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_postgresql_concurrency_contract_is_explicitly_opt_in(): void
    {
        $source = file_get_contents(__DIR__.'/../../Integration/LegalArchive/LegalDocumentVersionPostgresConcurrencyTest.php');

        self::assertIsString($source);
        self::assertStringContainsString("getenv('LEGAL_ARCHIVE_PG_CONCURRENCY') !== '1'", $source);
        self::assertStringContainsString('new PDO(', $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'new PDO('));
        self::assertStringContainsString('FOR UPDATE NOWAIT', $source);
    }
}
