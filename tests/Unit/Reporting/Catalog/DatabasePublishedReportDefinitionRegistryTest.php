<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\BatchReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\BatchReportPublicationRegistry;
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
    public function test_bulk_definition_map_rechecks_feature_and_publication_binding_on_each_call(): void
    {
        $codes = ['active_report', 'off_report', 'canary_report', 'wrong_id_report', 'wrong_proof_report', 'no_feature_report', 'unbound_report', 'missing_report'];
        $proofHash = new Sha256Hash(str_repeat('b', 64));
        $definitions = [];
        $enabled = [];
        foreach (array_slice($codes, 0, 6) as $code) {
            $identity = new ReportPublicationIdentity('01J00000000000000000000000', $code, $proofHash, str_repeat('c', 40));
            $definitions[$code] = new PublishedReportDefinition((new ReportDefinitionBuilder)->code($code)->published()->definition, $identity);
            $enabled[$code] = new ReportPublicationFeatureConfiguration($code, $identity->publicationId, $proofHash, ReportPublicationFeatureMode::ON, [], []);
        }
        $definitions['unbound_report'] = (new ReportDefinitionBuilder)->code('unbound_report')->published();
        $first = $enabled;
        $first['off_report'] = new ReportPublicationFeatureConfiguration('off_report', '01J00000000000000000000000', $proofHash, ReportPublicationFeatureMode::OFF, [], []);
        $first['canary_report'] = new ReportPublicationFeatureConfiguration('canary_report', '01J00000000000000000000000', $proofHash, ReportPublicationFeatureMode::CANARY, [1], []);
        $first['wrong_id_report'] = new ReportPublicationFeatureConfiguration('wrong_id_report', '01J00000000000000000000001', $proofHash, ReportPublicationFeatureMode::ON, [], []);
        $first['wrong_proof_report'] = new ReportPublicationFeatureConfiguration('wrong_proof_report', '01J00000000000000000000000', new Sha256Hash(str_repeat('d', 64)), ReportPublicationFeatureMode::ON, [], []);
        unset($first['no_feature_report']);
        $second = $enabled;
        $second['active_report'] = new ReportPublicationFeatureConfiguration('active_report', '01J00000000000000000000000', $proofHash, ReportPublicationFeatureMode::OFF, [], []);
        $republished = $definitions;
        $republished['active_report'] = new PublishedReportDefinition($definitions['active_report']->definition,
            new ReportPublicationIdentity('01J00000000000000000000001', 'active_report', $proofHash, str_repeat('c', 40)));
        $publications = $this->createMock(BatchReportPublicationRegistry::class);
        $publications->expects(self::exactly(3))->method('publishedCodes')->willReturn($codes);
        $publications->expects(self::exactly(3))->method('currentMany')->with($codes)->willReturnOnConsecutiveCalls($definitions, $definitions, $republished);
        $publications->expects(self::never())->method('current');
        $features = $this->createMock(BatchReportPublicationFeatureStore::class);
        $features->expects(self::exactly(3))->method('currentMany')->with(array_slice($codes, 0, 6))->willReturnOnConsecutiveCalls($first, $second, $enabled);
        $features->expects(self::never())->method('current');
        $registry = new DatabasePublishedReportDefinitionRegistry($publications, $features);

        self::assertSame(['active_report' => $definitions['active_report']], $registry->publishedDefinitions());
        $remaining = array_intersect_key($definitions, array_diff_key($enabled, ['active_report' => true]));
        self::assertSame($remaining, $registry->publishedDefinitions());
        self::assertSame($remaining, $registry->publishedDefinitions());
    }

    public function test_bulk_catalog_skips_features_when_publications_are_missing_or_unbound(): void
    {
        $publications = $this->createMock(BatchReportPublicationRegistry::class);
        $publications->method('publishedCodes')->willReturn(['missing_report', 'unbound_report']);
        $publications->method('currentMany')->willReturn(['unbound_report' => (new ReportDefinitionBuilder)->code('unbound_report')->published()]);
        $features = $this->createMock(BatchReportPublicationFeatureStore::class);
        $features->expects(self::never())->method('currentMany');
        $features->expects(self::never())->method('current');

        self::assertSame([], (new DatabasePublishedReportDefinitionRegistry($publications, $features))->publishedDefinitions());
    }

    public function test_bulk_catalog_does_not_hide_corrupted_publication_data(): void
    {
        $publications = $this->createMock(BatchReportPublicationRegistry::class);
        $publications->method('publishedCodes')->willReturn(['active_report']);
        $publications->method('currentMany')->willThrowException(new \LogicException('report_publication_persisted_proof_drift'));
        $features = $this->createMock(BatchReportPublicationFeatureStore::class);
        $features->expects(self::never())->method('currentMany');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('report_publication_persisted_proof_drift');

        (new DatabasePublishedReportDefinitionRegistry($publications, $features))->publishedDefinitions();
    }

    public function test_bulk_catalog_preserves_code_order_and_collapses_duplicates(): void
    {
        $codes = ['second_report', 'first_report', 'second_report'];
        $proofHash = new Sha256Hash(str_repeat('b', 64));
        $definitions = [];
        $enabled = [];
        foreach (['first_report', 'second_report'] as $code) {
            $identity = new ReportPublicationIdentity('01J00000000000000000000000', $code, $proofHash, str_repeat('c', 40));
            $definitions[$code] = new PublishedReportDefinition((new ReportDefinitionBuilder)->code($code)->published()->definition, $identity);
            $enabled[$code] = new ReportPublicationFeatureConfiguration($code, $identity->publicationId, $proofHash, ReportPublicationFeatureMode::ON, [], []);
        }
        $publications = $this->createMock(BatchReportPublicationRegistry::class);
        $publications->method('publishedCodes')->willReturn($codes);
        $publications->method('currentMany')->with($codes)->willReturn($definitions);
        $features = $this->createMock(BatchReportPublicationFeatureStore::class);
        $features->method('currentMany')->willReturn($enabled);

        self::assertSame(['second_report', 'first_report'], array_keys((new DatabasePublishedReportDefinitionRegistry($publications, $features))->publishedDefinitions()));
    }

    public function test_one_bulk_capability_keeps_the_legacy_store_path(): void
    {
        foreach ([true, false] as $bulkPublications) {
            $code = 'active_report';
            $proofHash = new Sha256Hash(str_repeat('b', 64));
            $identity = new ReportPublicationIdentity('01J00000000000000000000000', $code, $proofHash, str_repeat('c', 40));
            $published = new PublishedReportDefinition((new ReportDefinitionBuilder)->code($code)->published()->definition, $identity);
            $publications = $this->createMock($bulkPublications ? BatchReportPublicationRegistry::class : ReportPublicationRegistry::class);
            $publications->expects(self::once())->method('publishedCodes')->willReturn([$code]);
            $publications->expects(self::once())->method('current')->with($code)->willReturn($published);
            $features = $this->createMock($bulkPublications ? ReportPublicationFeatureStore::class : BatchReportPublicationFeatureStore::class);
            $features->expects(self::once())->method('current')->with($code)->willReturn(new ReportPublicationFeatureConfiguration($code, $identity->publicationId, $proofHash, ReportPublicationFeatureMode::ON, [], []));
            ($bulkPublications ? $publications : $features)->expects(self::never())->method('currentMany');

            self::assertSame([$code => $published], (new DatabasePublishedReportDefinitionRegistry($publications, $features))->publishedDefinitions());
        }
    }

    public function test_missing_publication_does_not_query_its_feature_configuration(): void
    {
        $code = 'missing_report';
        $publications = $this->createMock(ReportPublicationRegistry::class);
        $publications->expects(self::once())->method('current')->with($code)->willReturn(null);
        $features = $this->createMock(ReportPublicationFeatureStore::class);
        $features->expects(self::never())->method('current');
        $registry = new DatabasePublishedReportDefinitionRegistry($publications, $features);

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage(ReportErrorCode::REPORT_NOT_FOUND->value);

        $registry->published($code);
    }

    public function test_unbound_publication_does_not_query_its_feature_configuration(): void
    {
        $code = 'unbound_report';
        $published = (new ReportDefinitionBuilder)->code($code)->published();
        $publications = $this->createMock(ReportPublicationRegistry::class);
        $publications->expects(self::once())->method('current')->with($code)->willReturn($published);
        $features = $this->createMock(ReportPublicationFeatureStore::class);
        $features->expects(self::never())->method('current');
        $registry = new DatabasePublishedReportDefinitionRegistry($publications, $features);

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage(ReportErrorCode::REPORT_NOT_FOUND->value);

        $registry->published($code);
    }

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
