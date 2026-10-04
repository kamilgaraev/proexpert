<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Retention\RegionalPriceRetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RegionalPriceRetentionPolicyTest extends TestCase
{
    public function test_retains_four_distinct_published_quarters_and_latest_revision_in_each_context(): void
    {
        $versions = [];
        for ($quarter = 1; $quarter <= 5; $quarter++) {
            $versions[] = $this->version($quarter, 2025 + intdiv($quarter - 1, 4), (($quarter - 1) % 4) + 1);
        }
        $versions[] = $this->version(6, 2026, 1);
        $versions[] = $this->version(7, 2020, 1, region: 2);
        self::assertSame([1, 5], (new RegionalPriceRetentionPolicy)->candidates($versions, 4, '2026-08-20 00:00:00'));
    }

    public function test_protects_active_and_incomplete_imports_and_failed_grace_boundary(): void
    {
        $versions = [
            $this->version(1, 2020, 1, 'active'),
            $this->version(2, 2020, 1, 'parsing'),
            $this->version(3, 2020, 1, 'failed', updated: '2026-08-19 23:59:59'),
            $this->version(4, 2020, 1, 'failed', updated: '2026-08-20 00:00:00'),
            $this->version(5, 2020, 1, 'checked'),
        ];
        self::assertSame([3], (new RegionalPriceRetentionPolicy)->candidates($versions, 4, '2026-08-20 00:00:00'));
    }

    private function version(int $id, int $year, int $quarter, string $status = 'superseded', int $region = 1, string $updated = '2026-01-01 00:00:00'): array
    {
        return ['id' => $id, 'source' => 'fgis_labor_prices', 'region_id' => $region, 'price_zone_id' => 1,
            'year' => $year, 'quarter' => $quarter, 'status' => $status,
            'activated_at' => sprintf('2026-01-%02d 00:00:00', $id), 'updated_at' => $updated];
    }
}
