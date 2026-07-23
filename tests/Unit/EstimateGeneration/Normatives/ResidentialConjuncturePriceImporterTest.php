<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture\ResidentialConjunctureOfferProvider;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture\ResidentialConjuncturePriceImporter;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture\ResidentialConjuncturePriceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialConjuncturePriceImporterTest extends TestCase
{
    #[Test]
    public function official_price_has_priority_and_only_missing_resource_receives_auditable_analysis(): void
    {
        $prices = new InMemoryResidentialConjuncturePriceRepository(['59.1.20.03-0798']);
        $result = $this->importer($prices)->import(
            $this->datasetVersion(),
            $this->regionalVersion(),
            'RU-TA',
        );

        self::assertSame(1, $result['official']);
        self::assertSame(1, $result['inserted']);
        self::assertSame(0, $result['missing']);
        self::assertSame(['89.1.63.01-0079'], $result['resource_codes']);
        self::assertCount(1, $prices->writes);
        self::assertSame('89.1.63.01-0079', $prices->writes[0]['resource_code']);
        self::assertSame('project_material_conjuncture:v1', $prices->writes[0]['analysis']['schema_version']);
        self::assertSame(18810.0, $prices->writes[0]['analysis']['median_price']);
        self::assertCount(3, $prices->writes[0]['analysis']['eligible_offers']);
    }

    #[Test]
    public function incomplete_offer_set_is_not_persisted(): void
    {
        $config = $this->config();
        foreach ($config['analyses'] as &$analysis) {
            $analysis['offers'] = array_slice($analysis['offers'], 0, 2);
        }
        unset($analysis);

        $prices = new InMemoryResidentialConjuncturePriceRepository;
        $importer = new ResidentialConjuncturePriceImporter(
            new ResidentialProjectMaterialCatalog,
            new ResidentialConjunctureOfferProvider(
                $config,
                new DateTimeImmutable('2026-07-20 23:59:59 UTC'),
            ),
            $prices,
        );

        $result = $importer->import($this->datasetVersion(), $this->regionalVersion(), 'RU-TA');

        self::assertSame(0, $result['inserted']);
        self::assertSame(0, $result['official']);
        self::assertSame(2, $result['missing']);
        self::assertSame([], $prices->writes);
    }

    private function importer(InMemoryResidentialConjuncturePriceRepository $prices): ResidentialConjuncturePriceImporter
    {
        return new ResidentialConjuncturePriceImporter(
            new ResidentialProjectMaterialCatalog,
            new ResidentialConjunctureOfferProvider(
                $this->config(),
                new DateTimeImmutable('2026-07-20 23:59:59 UTC'),
            ),
            $prices,
        );
    }

    private function datasetVersion(): EstimateDatasetVersion
    {
        return (new EstimateDatasetVersion)->setRawAttributes(['id' => 701]);
    }

    private function regionalVersion(): EstimateRegionalPriceVersion
    {
        return (new EstimateRegionalPriceVersion)->setRawAttributes([
            'id' => 801,
            'region_id' => 16,
            'price_zone_id' => 202,
            'period_id' => 426,
        ]);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return require dirname(__DIR__, 4).'/config/estimate_generation_project_material_conjuncture.php';
    }
}

final class InMemoryResidentialConjuncturePriceRepository implements ResidentialConjuncturePriceRepository
{
    /** @var list<array<string, mixed>> */
    public array $writes = [];

    /** @param list<string> $officialResourceCodes */
    public function __construct(private readonly array $officialResourceCodes = []) {}

    public function officialPriceExists(
        EstimateRegionalPriceVersion $regionalVersion,
        string $resourceCode,
    ): bool {
        return in_array($resourceCode, $this->officialResourceCodes, true);
    }

    public function upsert(
        EstimateDatasetVersion $datasetVersion,
        EstimateRegionalPriceVersion $regionalVersion,
        string $resourceCode,
        string $sourceUnit,
        array $analysis,
    ): void {
        $this->writes[] = [
            'dataset_version_id' => $datasetVersion->id,
            'regional_version_id' => $regionalVersion->id,
            'resource_code' => $resourceCode,
            'source_unit' => $sourceUnit,
            'analysis' => $analysis,
        ];
    }
}
