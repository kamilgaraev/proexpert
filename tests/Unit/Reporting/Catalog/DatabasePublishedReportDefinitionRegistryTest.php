<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationFeatureConfiguration;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabasePublishedReportDefinitionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class DatabasePublishedReportDefinitionRegistryTest extends TestCase
{
    public function test_definition_map_reads_each_publication_once_and_rechecks_the_next_read(): void
    {
        $code = 'active_report';
        $proofHash = new Sha256Hash(str_repeat('b', 64));
        $identity = new ReportPublicationIdentity('01J00000000000000000000000', $code, $proofHash, str_repeat('c', 40));
        $published = new PublishedReportDefinition((new ReportDefinitionBuilder)->code($code)->published()->definition, $identity);
        $publications = $this->createMock(ReportPublicationRegistry::class);
        $publications->expects(self::exactly(2))->method('publishedCodes')->willReturn([$code]);
        $publications->expects(self::exactly(2))->method('current')->with($code)->willReturn($published);
        $features = $this->createMock(ReportPublicationFeatureStore::class);
        $features->expects(self::exactly(2))->method('current')->with($code)->willReturnOnConsecutiveCalls(
            new ReportPublicationFeatureConfiguration($code, $identity->publicationId, $proofHash, ReportPublicationFeatureMode::ON, [], []),
            new ReportPublicationFeatureConfiguration($code, $identity->publicationId, $proofHash, ReportPublicationFeatureMode::OFF, [], []),
        );
        $registry = new DatabasePublishedReportDefinitionRegistry($publications, $features);
        self::assertSame([$code => $published], $registry->publishedDefinitions());
        self::assertSame([], $registry->publishedDefinitions());
    }

    public function test_composite_map_preserves_publication_conflicts(): void
    {
        $builtins = $this->createMock(\App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry::class);
        $builtins->method('publishedCodes')->willReturn(['active_report']);
        $builtins->method('published')->willReturn((new ReportDefinitionBuilder)->code('active_report')->published());
        $database = $this->createMock(\App\BusinessModules\Core\Reporting\Domain\Contracts\PublishedReportDefinitionCatalog::class);
        $database->method('publishedDefinitions')->willReturn(['active_report' => (new ReportDefinitionBuilder)->code('active_report')->published()]);
        $registry = new \App\BusinessModules\Core\Reporting\Infrastructure\Catalog\CompositePublishedReportDefinitionRegistry($builtins, $database);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('report_published_definition_conflict');
        $registry->publishedDefinitions();
    }

    public function test_catalog_exposes_only_the_db_publication_bound_to_an_on_feature(): void
    {
        $code = 'active_report';
        $proofHash = new Sha256Hash(str_repeat('b', 64));
        $identity = new ReportPublicationIdentity('01J00000000000000000000000', $code, $proofHash, str_repeat('c', 40));
        $published = new PublishedReportDefinition((new ReportDefinitionBuilder)->code($code)->published()->definition, $identity);
        $publications = $this->createMock(ReportPublicationRegistry::class);
        $publications->method('publishedCodes')->willReturn([$code]);
        $publications->method('current')->willReturn($published);
        $features = $this->createMock(ReportPublicationFeatureStore::class);
        $features->method('current')->willReturn(new ReportPublicationFeatureConfiguration($identity->code, $identity->publicationId, $identity->proofHash, ReportPublicationFeatureMode::ON, [], []));

        $registry = new DatabasePublishedReportDefinitionRegistry($publications, $features);

        self::assertSame([$code], $registry->publishedCodes());
        self::assertSame($identity->proofHash->value, $registry->published($code)->publicationIdentity?->proofHash->value);
    }
}
