<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationFeatureConfiguration;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabasePublishedReportDefinitionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;

final class DatabasePublishedReportDefinitionRegistryTest extends TestCase
{
    public function test_catalog_exposes_only_the_db_publication_bound_to_an_on_feature(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $eligible = $fixture['eligible'];
        $identity = new ReportPublicationIdentity('01J00000000000000000000000', $eligible->candidate->code, $eligible->proofHash, $eligible->release->gitSha);
        $published = new \App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition($this->publishedDefinition($eligible), $identity);
        $publications = $this->createMock(ReportPublicationRegistry::class);
        $publications->method('publishedCodes')->willReturn([$eligible->candidate->code]);
        $publications->method('current')->willReturn($published);
        $features = $this->createMock(ReportPublicationFeatureStore::class);
        $features->method('current')->willReturn(new ReportPublicationFeatureConfiguration($identity->code, $identity->publicationId, $identity->proofHash, ReportPublicationFeatureMode::ON, [], []));

        $registry = new DatabasePublishedReportDefinitionRegistry($publications, $features);

        self::assertSame([$eligible->candidate->code], $registry->publishedCodes());
        self::assertSame($identity->proofHash->value, $registry->published($eligible->candidate->code)->publicationIdentity?->proofHash->value);
    }

    private function publishedDefinition(\App\BusinessModules\Core\Reporting\Domain\DTO\EligibleReportPublication $publication): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition
    {
        $definition = $publication->candidate->definition;

        return new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition($definition->code, $definition->definitionHash, $definition->contractVersion, $definition->formulaVersion, $definition->sourceSchemaVersion, $definition->rendererVersion, $definition->filters, $definition->columns, $definition->sorts, $definition->formats, $definition->permissionPolicy, $definition->snapshotClassification, $definition->outputClassification, \App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness::PUBLISHED, $definition->supportsSubscriptions, $definition->sourceModule, $definition->coreAccessMode);
    }
}
