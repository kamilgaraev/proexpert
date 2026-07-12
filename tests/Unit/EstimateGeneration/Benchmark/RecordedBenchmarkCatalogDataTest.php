<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedBenchmarkCatalogData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordedBenchmarkCatalogDataTest extends TestCase
{
    #[Test]
    public function independently_approved_catalog_contains_candidates_resources_and_prices_without_final_labels(): void
    {
        $catalog = RecordedBenchmarkCatalogData::fromArray($this->catalog());

        self::assertSame('fsnb-2022:benchmark-v1', $catalog->datasetVersion);
        self::assertSame('77.01', $catalog->regionCode);
        self::assertCount(1, $catalog->candidates);
        self::assertCount(1, $catalog->resources);
        self::assertCount(1, $catalog->prices);
    }

    #[Test]
    public function catalog_rejects_oracle_labels_recursively(): void
    {
        $payload = $this->catalog();
        $payload['candidates'][0]['expected_norm_id'] = 101;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recorded_catalog_forbidden_key');

        RecordedBenchmarkCatalogData::fromArray($payload);
    }

    private function catalog(): array
    {
        return [
            'schema_version' => 'recorded-benchmark-catalog:v1', 'dataset_id' => 11,
            'dataset_version' => 'fsnb-2022:benchmark-v1', 'dataset_status' => 'parsed',
            'region_code' => '77.01', 'price_period' => '2026-Q2', 'currency' => 'RUB',
            'candidates' => [['id' => '101', 'normative_id' => 101, 'code' => 'ГЭСН 15-01-001-01', 'name' => 'Штукатурка стен', 'unit' => 'm2', 'unit_dimension' => 'area', 'lexical_score' => 0.91, 'source_evidence' => ['norm:101']]],
            'resources' => [['normative_id' => 101, 'resource_id' => 501, 'code' => '01.7.03.01', 'name' => 'Рабочий строитель', 'type' => 'labor', 'unit' => 'h', 'quantity' => '0.5']],
            'prices' => [['resource_id' => 501, 'price_id' => 701, 'unit' => 'h', 'amount' => '500.00', 'source_reference' => 'catalog:price:701']],
            'privacy_scanner' => 'most-fixture-privacy', 'privacy_scanner_version' => '1.0.0',
            'approval_kind' => 'maintainer_code_review', 'approval_ref' => 'review:task11:catalog',
            'approved_at' => '2026-07-12T10:00:00Z',
        ];
    }
}
