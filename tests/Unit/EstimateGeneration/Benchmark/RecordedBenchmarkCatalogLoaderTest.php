<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedBenchmarkCatalogLoader;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordedBenchmarkCatalogLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/most-catalog-loader-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $path) {
            unlink($path);
        }
        @rmdir($this->root);
    }

    #[Test]
    public function loads_only_the_projection_declared_catalog_with_matching_hash(): void
    {
        $json = json_encode($this->catalog(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->root.'/catalog.json', $json);

        $catalog = (new RecordedBenchmarkCatalogLoader($this->root))->load($this->projection(hash('sha256', $json)));

        self::assertSame(41, $catalog->datasetId);
        self::assertSame('fsnb-2026.1', $catalog->datasetVersion);
    }

    #[Test]
    public function rejects_a_catalog_changed_after_projection_was_signed(): void
    {
        file_put_contents($this->root.'/catalog.json', json_encode($this->catalog(), JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recorded_catalog_integrity_failed');
        (new RecordedBenchmarkCatalogLoader($this->root))->load($this->projection(str_repeat('a', 64)));
    }

    private function projection(string $hash): BenchmarkPredictionCaseData
    {
        return new BenchmarkPredictionCaseData(
            'replay-vector-001', BenchmarkDatasetType::Regression, BenchmarkSourceType::VectorPdf,
            'replay/input.pdf', str_repeat('b', 64), ['production_replay'], ['geometry'], [], [],
            benchmarkCatalogReference: 'catalog.json', benchmarkCatalogSha256: $hash,
        );
    }

    private function catalog(): array
    {
        return [
            'schema_version' => 'recorded-benchmark-catalog:v1', 'dataset_id' => 41,
            'dataset_version' => 'fsnb-2026.1', 'dataset_status' => 'parsed', 'region_code' => '77',
            'price_period' => '2026-06', 'currency' => 'RUB',
            'candidates' => [['id' => 'norm-1']],
            'resources' => [['id' => 'resource-1', 'resources' => ['materials' => [['price_id' => 704813]]]]],
            'prices' => [[
                'id' => 704813, 'region_id' => 77, 'price_zone_id' => 3, 'period_id' => 202606,
                'regional_price_version_id' => 41, 'base_price' => '125.50', 'source_type' => 'reviewed_snapshot',
                'currency' => 'RUB', 'source_dataset' => 'fsnb-2026.1', 'source_version' => '2026-06',
                'snapshot_ref' => 'snapshot:catalog-loader:704813', 'snapshot_sha256' => str_repeat('a', 64),
                'reviewer_ref' => 'review:catalog-loader', 'approved_at' => '2026-07-12T00:00:00Z',
            ]], 'privacy_scanner' => 'most-fixture-privacy',
            'privacy_scanner_version' => '1.0', 'approval_kind' => 'maintainer_code_review',
            'approval_ref' => 'review:task11', 'approved_at' => '2026-07-12T00:00:00Z',
        ];
    }
}
