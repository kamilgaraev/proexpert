<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Generation;

use App\BusinessModules\Core\Reporting\Application\Generation\ReportCatalogArtifactGenerator;
use App\BusinessModules\Core\Reporting\Application\Generation\ReportPermissionTranslationGenerator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportCatalogArtifactGeneratorTest extends TestCase
{
    public function test_platform_generation_has_stable_group_order_and_no_manifest_ordinal_on_wire(): void
    {
        $generated = (new ReportCatalogArtifactGenerator)->generate('platform', $this->registry(), $this->inputs());

        self::assertSame('platform', $generated['catalog']['phase']);
        self::assertSame(['portfolio', 'projects', 'finance', 'procurement_warehouse', 'team', 'quality_safety', 'partners_customers'], $generated['catalog']['catalog_group_order']);
        self::assertSame('holding_performance', $generated['catalog']['definitions'][0]['code']);
        self::assertArrayNotHasKey('manifest_ordinal', $generated['resource']['definitions'][0]);
        self::assertStringContainsString("'holding_performance'", $generated['typeScript']);
    }

    public function test_release_generation_rejects_non_complete_catalog(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('release_catalog_count_invalid');

        (new ReportCatalogArtifactGenerator)->generate('release', $this->registry(), $this->inputs());
    }

    private function registry(): ReportDefinitionRegistry
    {
        $definition = new ReportDefinition(
            'holding_performance', new Sha256Hash(str_repeat('a', 64)), '1.0.0', '1.0.0', '1.0.0', '1.0.0',
            [['id' => 'project_id']], [['id' => 'amount']], [['id' => 'amount']], ['csv'],
            new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []),
            ReportSnapshotClassification::OPERATIONAL,
            new ReportOutputClassification(ReportDataClassification::STANDARD, [], [], false, false, false),
            ReportPublicationReadiness::PUBLISHED, false,
        );
        $published = new PublishedReportDefinition($definition);

        return new class($published) implements ReportDefinitionRegistry {
            public function __construct(private PublishedReportDefinition $published) {}
            public function published(string $code): PublishedReportDefinition { return $this->published; }
            public function publishedCodes(): array { return ['holding_performance']; }
            public function manifestSha256(): Sha256Hash { return new Sha256Hash(hash('sha256', 'manifest')); }
        };
    }

    private function inputs(): array
    {
        return [
            'manifest_bytes' => 'manifest',
            'metadata' => new class implements ReportCatalogMetadataRegistry {
                public function published(string $code): ReportCatalogMetadata { return new ReportCatalogMetadata($code, 'reports.catalog.'.$code, ReportCatalogGroup::PORTFOLIO, 'portfolio', 'project', 1, 1); }
            },
            'scheduling' => new class implements ReportSchedulingCapabilityRegistry {
                public function published(string $code): ReportSchedulingCapability { return new ReportSchedulingCapability($code, false, false); }
            },
            'translations' => ReportPermissionTranslationGenerator::fromProject(dirname(__DIR__, 4)),
        ];
    }
}
