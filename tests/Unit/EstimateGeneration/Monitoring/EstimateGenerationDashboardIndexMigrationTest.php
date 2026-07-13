<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Monitoring;

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\DashboardFilters;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardQueryFactory;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationDashboardIndexMigrationTest extends TestCase
{
    public function test_online_migration_declares_concurrent_idempotent_indexes_and_safe_down(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000100_add_estimate_generation_dashboard_indexes.php');

        self::assertIsString($source);
        self::assertStringContainsString('public $withinTransaction = false;', $source);
        foreach ([
            'eg_sessions_created_at_idx',
            'eg_sessions_status_created_at_idx',
            'eg_documents_session_mime_idx',
        ] as $index) {
            self::assertStringContainsString("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index}", $source);
            self::assertStringContainsString("DROP INDEX CONCURRENTLY IF EXISTS {$index}", $source);
        }
    }

    public function test_dashboard_queries_are_addressable_by_the_online_index_inventory(): void
    {
        $filters = DashboardFilters::fromArray([
            'date_from' => '2026-07-01', 'date_to' => '2026-07-14',
            'status' => 'failed', 'document_type' => 'application/pdf',
        ], CarbonImmutable::parse('2026-07-14 UTC'));
        $sql = (new EstimateGenerationDashboardQueryFactory)->sessionMetrics($filters)->sql;

        self::assertStringContainsString('sessions.created_at >= ?', $sql);
        self::assertStringContainsString('sessions.status = ?', $sql);
        self::assertStringContainsString('documents.session_id = sessions.id AND documents.mime_type = ?', $sql);
    }
}
