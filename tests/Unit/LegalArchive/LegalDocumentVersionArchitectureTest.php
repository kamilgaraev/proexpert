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
        self::assertStringNotContainsString('CREATE TABLE {$table}', $source);
        self::assertStringContainsString('legal_archive_document_files', $source);
        self::assertStringContainsString('legal_archive_document_versions', $source);
        self::assertStringContainsString('legal_archive_versions_immutable_guard', $source);
        self::assertStringContainsString('legal_archive_document_file_current_unique', $source);
        self::assertStringContainsString('rollBack()', $source);
    }

    public function test_processing_transition_is_not_a_public_application_api(): void
    {
        self::assertFalse(method_exists(
            \App\Services\LegalArchive\Files\LegalDocumentFileService::class,
            'transitionProcessingStatus',
        ));

        $source = file_get_contents(
            __DIR__.'/../../../app/Services/LegalArchive/Files/LegalDocumentFileService.php',
        );
        self::assertIsString($source);
        self::assertStringNotContainsString('public function transitionProcessingStatus', $source);
    }

    public function test_technical_mutation_capability_is_confined_to_model_and_file_service(): void
    {
        $root = realpath(__DIR__.'/../../../app');
        self::assertIsString($root);
        $allowed = array_filter(array_map(
            static fn (string $path): string => str_replace('\\', '/', realpath($path) ?: ''),
            [
                __DIR__.'/../../../app/BusinessModules/Features/LegalArchive/Models/LegalArchiveDocumentVersion.php',
                __DIR__.'/../../../app/Services/LegalArchive/Files/LegalDocumentFileService.php',
            ],
        ));
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (in_array($path, $allowed, true)) {
                continue;
            }
            $source = file_get_contents($path);
            self::assertIsString($source);
            if (
                str_contains($source, 'LegalArchiveDocumentVersion::technicalMutation(')
                || str_contains($source, 'most.legal_archive_version_mutation')
            ) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_scanner_failure_has_an_explicit_accepted_api_contract(): void
    {
        $controller = file_get_contents(
            __DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchiveController.php',
        );
        self::assertIsString($controller);
        self::assertGreaterThanOrEqual(2, substr_count($controller, 'instanceof LegalDocumentScanFailed'));
        self::assertStringContainsString('legal_archive.messages.document_file_processing_failed', $controller);
        self::assertStringContainsString('legal_archive.messages.version_file_processing_failed', $controller);
        self::assertGreaterThanOrEqual(2, substr_count($controller, '202'));
        self::assertGreaterThanOrEqual(2, substr_count($controller, "'retry_action' => 'add_version'"));
        self::assertStringContainsString('new LegalArchiveDocumentResource(', $controller);
        self::assertStringContainsString('new LegalArchiveDocumentVersionResource($e->version)', $controller);
    }

    public function test_create_failure_returns_durable_recovery_contract_instead_of_generic_error(): void
    {
        $controller = file_get_contents(
            __DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchiveController.php',
        );
        $resource = file_get_contents(
            __DIR__.'/../../../app/Http/Resources/Api/V1/Admin/LegalArchive/LegalArchiveDocumentResource.php',
        );

        self::assertIsString($controller);
        self::assertIsString($resource);
        self::assertStringContainsString('instanceof LegalDocumentCreateFailed', $controller);
        self::assertStringContainsString("'operation_result' => 'document_create_failed'", $controller);
        self::assertStringContainsString("? 'repeat_create' : 'add_version'", $controller);
        self::assertStringContainsString("'retry_document_id' => (int) \$e->document->id", $controller);
        self::assertStringContainsString("'source_create_status' => \$this->source_create_status", $resource);
    }
}
