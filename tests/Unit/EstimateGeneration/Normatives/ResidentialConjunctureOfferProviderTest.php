<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture\ResidentialConjunctureOfferProvider;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialConjunctureOfferProviderTest extends TestCase
{
    #[Test]
    public function current_residential_lighting_and_boiler_analyses_are_auditable_and_use_median(): void
    {
        $provider = $this->provider();
        $lighting = $provider->resolve(
            'residential_led_ceiling_luminaire_18w',
            '59.1.20.03-0798',
            'шт',
            'RU-TA',
        );
        $boiler = $provider->resolve(
            'residential_wall_mounted_single_circuit_electric_boiler_18kw',
            '89.1.63.01-0079',
            'шт',
            'RU-TA',
        );

        self::assertIsArray($lighting);
        self::assertSame(1260.0, $lighting['median_price']);
        self::assertCount(3, $lighting['eligible_offers']);
        self::assertSame('RU-TA', $lighting['region_code']);
        self::assertSame('2026-07-20', $lighting['observed_at']);
        self::assertCount(3, array_unique(array_map(
            static fn (array $offer): string => (string) parse_url($offer['url'], PHP_URL_HOST),
            $lighting['eligible_offers'],
        )));

        self::assertIsArray($boiler);
        self::assertSame(18810.0, $boiler['median_price']);
        self::assertCount(3, $boiler['eligible_offers']);
        self::assertSame('RU-TA', $boiler['region_code']);
        self::assertSame('шт', $boiler['unit']);
    }

    #[Test]
    public function fewer_than_three_eligible_offers_fail_closed(): void
    {
        $config = $this->config();
        $config['analyses']['residential_led_ceiling_luminaire_18w']['offers'] = array_slice(
            $config['analyses']['residential_led_ceiling_luminaire_18w']['offers'],
            0,
            2,
        );
        $provider = new ResidentialConjunctureOfferProvider($config, new DateTimeImmutable('2026-07-20 23:59:59 UTC'));

        self::assertNull($provider->resolve(
            'residential_led_ceiling_luminaire_18w',
            '59.1.20.03-0798',
            'шт',
            'RU-TA',
        ));
    }

    #[Test]
    public function office_or_warehouse_product_identity_fails_closed(): void
    {
        $config = $this->config();
        foreach ($config['analyses']['residential_led_ceiling_luminaire_18w']['offers'] as &$offer) {
            $offer['product_name'] = 'Офисный складской светодиодный светильник 18 Вт';
        }
        unset($offer);
        $provider = new ResidentialConjunctureOfferProvider($config, new DateTimeImmutable('2026-07-20 23:59:59 UTC'));

        self::assertNull($provider->resolve(
            'residential_led_ceiling_luminaire_18w',
            '59.1.20.03-0798',
            'шт',
            'RU-TA',
        ));
    }

    #[Test]
    public function conjuncture_provenance_is_preserved_by_residential_material_selection(): void
    {
        $provider = $this->provider();
        $analysis = $provider->resolve(
            'residential_wall_mounted_single_circuit_electric_boiler_18kw',
            '89.1.63.01-0079',
            'шт',
            'RU-TA',
        );
        self::assertIsArray($analysis);
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog(
            $scenarios,
            new DateTimeImmutable('2026-07-20 23:59:59 UTC'),
        );
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('heating.unit', 'residential'),
        ]);
        self::assertIsArray($requirement);

        $resource = $catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 701,
            'construction_resource_id' => null,
            'resource_code' => '89.1.63.01-0079',
            'resource_name' => $analysis['resource_name'],
            'unit' => 'шт',
            'base_price' => '18810.0000',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'fgis-ru-ta-2026-q2',
            'source_price_kind' => 'conjuncture_analysis',
            'raw_payload' => json_encode(['source' => 'conjuncture_analysis', 'analysis' => $analysis], JSON_THROW_ON_ERROR),
        ]);

        self::assertIsArray($resource);
        self::assertSame('conjuncture_analysis', $resource['price_source_kind']);
        self::assertSame('project_material_conjuncture:v1', $resource['price_provenance']['schema_version']);
        self::assertSame(18810.0, (float) $resource['project_material_requirement']['price_provenance']['median_price']);
        self::assertSame([701], $resource['project_material_requirement']['candidate_resource_price_ids']);
    }

    #[Test]
    public function tampered_conjuncture_median_is_rejected(): void
    {
        $provider = $this->provider();
        $analysis = $provider->resolve(
            'residential_led_ceiling_luminaire_18w',
            '59.1.20.03-0798',
            'шт',
            'RU-TA',
        );
        self::assertIsArray($analysis);
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog(
            $scenarios,
            new DateTimeImmutable('2026-07-20 23:59:59 UTC'),
        );
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('lighting.fixtures', 'residential'),
        ]);
        self::assertIsArray($requirement);

        self::assertNull($catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 702,
            'resource_code' => '59.1.20.03-0798',
            'resource_name' => $analysis['resource_name'],
            'unit' => 'шт',
            'base_price' => '999.0000',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'fgis-ru-ta-2026-q2',
            'source_price_kind' => 'conjuncture_analysis',
            'raw_payload' => ['source' => 'conjuncture_analysis', 'analysis' => $analysis],
        ]));
    }

    #[Test]
    public function stale_conjuncture_price_is_rejected_even_when_its_stored_median_is_valid(): void
    {
        $analysis = $this->provider()->resolve(
            'residential_led_ceiling_luminaire_18w',
            '59.1.20.03-0798',
            'шт',
            'RU-TA',
        );
        self::assertIsArray($analysis);
        $scenarios = new ResidentialMaterialScenarioCatalog;
        $catalog = new ResidentialProjectMaterialCatalog(
            $scenarios,
            new DateTimeImmutable('2026-09-10 00:00:00 UTC'),
        );
        $requirement = $catalog->requirementForIntent([
            'specialization_scenario' => $scenarios->issue('lighting.fixtures', 'residential'),
        ]);
        self::assertIsArray($requirement);

        self::assertNull($catalog->resourceFromPriceRow($requirement, (object) [
            'price_id' => 703,
            'resource_code' => '59.1.20.03-0798',
            'resource_name' => $analysis['resource_name'],
            'unit' => 'шт',
            'base_price' => '1260.0000',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'fgis-ru-ta-2026-q2-r1',
            'source_price_kind' => 'conjuncture_analysis',
            'raw_payload' => ['source' => 'conjuncture_analysis', 'analysis' => $analysis],
        ]));
    }

    private function provider(): ResidentialConjunctureOfferProvider
    {
        return new ResidentialConjunctureOfferProvider(
            $this->config(),
            new DateTimeImmutable('2026-07-20 23:59:59 UTC'),
        );
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return require dirname(__DIR__, 4).'/config/estimate_generation_project_material_conjuncture.php';
    }
}
