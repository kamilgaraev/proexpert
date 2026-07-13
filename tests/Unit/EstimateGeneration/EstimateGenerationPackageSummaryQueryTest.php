<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackageSummaryQuery;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationPackageSummaryQueryTest extends TestCase
{
    public function test_uses_database_aggregates_and_maps_the_complete_summary_without_materializing_packages(): void
    {
        $query = new EstimateGenerationPackageSummaryQuery;
        $sql = $query->aggregateSql();

        self::assertStringContainsString('COUNT(*) FILTER', $sql);
        self::assertStringContainsString('SUM(', $sql);
        self::assertStringNotContainsString('SELECT *', $sql);
        self::assertSame([
            'total' => 12,
            'planned' => 1,
            'processing' => 2,
            'ready' => 3,
            'review_required' => 1,
            'approved' => 2,
            'blocked' => 1,
            'failed' => 2,
            'priced_items_count' => 41,
            'quantity_review_items_count' => 4,
            'operation_items_count' => 0,
            'hidden_service_items_count' => 7,
        ], $query->fromRow([
            'total' => '12', 'planned' => '1', 'processing' => '2', 'ready' => '3',
            'review_required' => '1', 'approved' => '2', 'blocked' => '1', 'failed' => '2',
            'priced_items_count' => '41', 'quantity_review_items_count' => '4',
            'operation_items_count' => '0', 'hidden_service_items_count' => '7',
        ]));

        $controller = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationPackageController.php');
        self::assertIsString($controller);
        self::assertStringNotContainsString('summaryPackages', $controller);
        self::assertStringNotContainsString('->get()', $controller);
    }
}
