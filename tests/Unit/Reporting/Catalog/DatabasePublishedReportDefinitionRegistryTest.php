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
