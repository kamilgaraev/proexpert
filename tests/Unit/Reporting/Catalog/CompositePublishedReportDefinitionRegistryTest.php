<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\PublishedReportDefinitionCatalog;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\CompositePublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class CompositePublishedReportDefinitionRegistryTest extends TestCase
{
    public function test_manifest_keeps_the_original_code_order_without_per_code_database_probes(): void
    {
        $definitions = $this->definitions(['first_report', 'second_report', 'first_db_report', 'second_db_report']);
        $builtins = $this->createMock(PublishedReportDefinitionCatalog::class);
        $builtins->method('publishedCodes')->willReturn(['second_report', 'first_report']);
        $builtins->method('publishedDefinitions')->willReturn(array_intersect_key($definitions, array_flip(['first_report', 'second_report'])));
        $builtins->expects(self::never())->method('published');
        $database = $this->createMock(PublishedReportDefinitionCatalog::class);
        $database->method('publishedCodes')->willReturn(['second_db_report', 'first_db_report']);
        $database->method('publishedDefinitions')->willReturn(array_intersect_key($definitions, array_flip(['first_db_report', 'second_db_report'])));
        $database->expects(self::never())->method('published');
        $registry = new CompositePublishedReportDefinitionRegistry($builtins, $database);

        self::assertSame($this->digest($definitions, ['second_report', 'first_report', 'second_db_report', 'first_db_report']), $registry->manifestSha256()->value);
    }

    public function test_manifest_fails_when_a_code_disappears_after_the_initial_list(): void
    {
        $builtins = $this->createMock(ReportDefinitionRegistry::class);
        $builtins->method('publishedCodes')->willReturn([]);
        $database = $this->createMock(PublishedReportDefinitionCatalog::class);
        $database->method('publishedCodes')->willReturn(['removed_report']);
        $database->method('publishedDefinitions')->willReturn([]);
        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage(ReportErrorCode::REPORT_NOT_FOUND->value);

        (new CompositePublishedReportDefinitionRegistry($builtins, $database))->manifestSha256();
    }

    public function test_manifest_does_not_add_new_codes_outside_the_initial_list(): void
    {
        $definitions = $this->definitions(['builtin_report', 'new_report']);
        $builtins = $this->createMock(ReportDefinitionRegistry::class);
        $builtins->method('publishedCodes')->willReturn(['builtin_report']);
        $builtins->method('published')->with('builtin_report')->willReturn($definitions['builtin_report']);
        $database = $this->createMock(PublishedReportDefinitionCatalog::class);
        $database->method('publishedCodes')->willReturn([]);
        $database->method('publishedDefinitions')->willReturn(['new_report' => $definitions['new_report']]);
        $database->expects(self::never())->method('published');

        self::assertSame($this->digest($definitions, ['builtin_report']), (new CompositePublishedReportDefinitionRegistry($builtins, $database))->manifestSha256()->value);
    }

    public function test_manifest_preserves_a_new_publication_collision(): void
    {
        $definitions = $this->definitions(['builtin_report']);
        $builtins = $this->createMock(ReportDefinitionRegistry::class);
        $builtins->method('publishedCodes')->willReturn(['builtin_report']);
        $builtins->method('published')->willReturn($definitions['builtin_report']);
        $database = $this->createMock(PublishedReportDefinitionCatalog::class);
        $database->method('publishedCodes')->willReturn([]);
        $database->method('publishedDefinitions')->willReturn($definitions);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('report_published_definition_conflict');

        (new CompositePublishedReportDefinitionRegistry($builtins, $database))->manifestSha256();
    }

    public function test_manifest_rereads_the_catalog_on_the_next_call_and_skips_an_empty_map_read(): void
    {
        $definitions = $this->definitions(['db_report']);
        $builtins = $this->createMock(ReportDefinitionRegistry::class);
        $builtins->method('publishedCodes')->willReturn([]);
        $database = $this->createMock(PublishedReportDefinitionCatalog::class);
        $database->expects(self::exactly(2))->method('publishedCodes')->willReturnOnConsecutiveCalls(['db_report'], []);
        $database->expects(self::once())->method('publishedDefinitions')->willReturn($definitions);
        $database->expects(self::never())->method('published');
        $registry = new CompositePublishedReportDefinitionRegistry($builtins, $database);

        self::assertSame($this->digest($definitions, ['db_report']), $registry->manifestSha256()->value);
        self::assertSame($this->digest([], []), $registry->manifestSha256()->value);
    }

    public function test_custom_database_without_a_catalog_keeps_the_original_collision_check(): void
    {
        $definitions = $this->definitions(['builtin_report']);
        $builtins = $this->createMock(ReportDefinitionRegistry::class);
        $builtins->expects(self::once())->method('publishedCodes')->willReturn(['builtin_report']);
        $builtins->expects(self::once())->method('published')->with('builtin_report')->willReturn($definitions['builtin_report']);
        $database = $this->createMock(ReportDefinitionRegistry::class);
        $database->expects(self::once())->method('publishedCodes')->willReturn([]);
        $database->expects(self::once())->method('published')->with('builtin_report')->willThrowException(ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND));

        self::assertSame($this->digest($definitions, ['builtin_report']), (new CompositePublishedReportDefinitionRegistry($builtins, $database))->manifestSha256()->value);
    }

    private function definitions(array $codes): array
    {
        $definitions = [];
        foreach ($codes as $code) {
            $definitions[$code] = (new ReportDefinitionBuilder)->code($code)->published();
        }

        return $definitions;
    }

    private function digest(array $definitions, array $codes): string
    {
        return hash('sha256', CanonicalJson::encode(array_map(static fn (string $code): array => [
            'code' => $code,
            'definition_sha256' => $definitions[$code]->definitionHash->value,
        ], $codes)));
    }
}
