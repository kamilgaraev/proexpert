<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\LegacyNormativeRateCatalogAdapter;
use App\Models\NormativeCollection;
use App\Models\NormativeRate;
use App\Models\NormativeRateResource;
use App\Models\NormativeSection;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class LegacyNormativeRateCatalogAdapterTest extends TestCase
{
    public function test_maps_existing_normative_rate_with_priced_resources(): void
    {
        $rate = $this->rate();
        $resource = new NormativeRateResource([
            'resource_type' => 'material',
            'code' => '01.1.01.01-0001',
            'name' => 'Песок строительный',
            'measurement_unit' => 'м3',
            'consumption' => '1.02',
            'unit_price' => '850',
        ]);
        $resource->id = 501;
        $rate->setRelation('resources', new Collection([$resource]));

        $candidate = (new LegacyNormativeRateCatalogAdapter)->present($rate, [
            'name' => 'Разработка грунта под фундаменты',
            'unit' => '1000 м3',
        ]);

        self::assertSame('normative_rates', $candidate['catalog_source']);
        self::assertSame(42, $candidate['norm_id']);
        self::assertSame('01-01-001-01', $candidate['code']);
        self::assertSame(850.0, $candidate['resources']['materials'][0]['unit_price']);
        self::assertSame('normative_rate_base', $candidate['resources']['materials'][0]['price_source']);
        self::assertSame(501, $candidate['resources']['materials'][0]['embedded_price']['normative_rate_resource_id']);
    }

    public function test_uses_rate_base_price_when_detailed_resources_are_absent(): void
    {
        $rate = $this->rate();
        $rate->setRelation('resources', new Collection);

        $candidate = (new LegacyNormativeRateCatalogAdapter)->present($rate, [
            'name' => 'Разработка грунта под фундаменты',
            'unit' => '1000 м3',
        ]);

        self::assertSame([], $candidate['resources']['materials']);
        self::assertCount(1, $candidate['resources']['other']);
        self::assertSame(1250.0, $candidate['resources']['other'][0]['unit_price']);
        self::assertSame(42, $candidate['resources']['other'][0]['embedded_price']['normative_rate_id']);
        self::assertNotContains('norm_without_resources', $candidate['warnings']);
    }

    private function rate(): NormativeRate
    {
        $rate = new NormativeRate([
            'code' => '01-01-001-01',
            'name' => 'Разработка грунта экскаваторами',
            'measurement_unit' => '1000 м3',
            'base_price' => '1250',
            'base_price_year' => 2022,
        ]);
        $rate->id = 42;

        $collection = new NormativeCollection([
            'code' => 'ГЭСН',
            'name' => 'Государственные элементные сметные нормы',
            'version' => '2022',
        ]);
        $collection->id = 7;

        $section = new NormativeSection([
            'code' => '01',
            'name' => 'Земляные работы',
            'path' => '/01/',
        ]);
        $section->id = 11;

        $rate->setRelation('collection', $collection);
        $rate->setRelation('section', $section);

        return $rate;
    }
}
