<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\DashboardFilters;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardMetrics;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardQueryFactory;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationDashboardTest extends TestCase
{
    public function test_dashboard_queries_apply_all_filters_and_never_select_sensitive_payloads(): void
    {
        $filters = DashboardFilters::fromArray([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-14',
            'organization_id' => 17,
            'project_id' => 31,
            'provider' => 'timeweb',
            'model' => 'vision-v2',
            'stage' => 'understand_documents',
            'status' => 'failed',
            'document_type' => 'application/pdf',
            'mode' => 'strict_documents',
        ], CarbonImmutable::parse('2026-07-14 12:00:00 UTC'));
        $factory = new EstimateGenerationDashboardQueryFactory;

        $queries = [
            $factory->sessionMetrics($filters),
            $factory->usageMetrics($filters),
            $factory->costTrend($filters),
            $factory->queueHealth($filters),
        ];

        foreach ($queries as $query) {
            self::assertLessThanOrEqual(1000, $query->rowLimit);
            self::assertStringNotContainsString('select *', strtolower($query->sql));
            self::assertStringNotContainsString('extracted_text', strtolower($query->sql));
            self::assertStringNotContainsString('structured_payload', strtolower($query->sql));
            self::assertStringNotContainsString('output_payload', strtolower($query->sql));
            self::assertStringNotContainsString('price_snapshot', strtolower($query->sql));
            self::assertStringNotContainsString('safe_context', strtolower($query->sql));
        }

        $sessionSql = $queries[0]->sql;
        self::assertStringContainsString('sessions.organization_id = ?', $sessionSql);
        self::assertStringContainsString('sessions.project_id = ?', $sessionSql);
        self::assertStringContainsString('sessions.status = ?', $sessionSql);
        self::assertStringContainsString('documents.mime_type = ?', $sessionSql);
        self::assertStringContainsString("sessions.input_payload->>'generation_mode' = ?", $sessionSql);

        $usageSql = $queries[1]->sql;
        self::assertStringContainsString('usage.provider = ?', $usageSql);
        self::assertStringContainsString('usage.requested_model = ?', $usageSql);
        self::assertStringContainsString('usage.stage = ?', $usageSql);
        self::assertStringContainsString('sessions.status = ?', $usageSql);
        self::assertStringContainsString('usage.created_at >= ?', $usageSql);
        self::assertStringContainsString('usage.created_at < ?', $usageSql);
        self::assertStringContainsString('GROUP BY DATE_TRUNC(\'day\', usage.created_at), usage.currency', $queries[2]->sql);
    }

    public function test_metrics_are_factual_and_cost_ratios_use_successful_and_applied_denominators(): void
    {
        $metrics = EstimateGenerationDashboardMetrics::fromRows(
            sessions: [
                'sessions_total' => '20',
                'successful_sessions' => '8',
                'applied_sessions' => '4',
                'review_sessions' => '5',
                'documents_total' => '36',
                'average_duration_ms' => '1250.5',
                'p95_duration_ms' => '4000',
            ],
            usage: [
                'total_cost' => '24.00000000',
                'currency' => 'RUB',
            ],
            queue: [
                'running_jobs' => '3',
                'stale_jobs' => '1',
                'oldest_queue_age_seconds' => '240',
            ],
        );

        self::assertSame(0.5, $metrics['apply_rate']);
        self::assertSame(0.625, $metrics['review_rate']);
        self::assertSame(3.0, $metrics['cost_per_successful_session']);
        self::assertSame(6.0, $metrics['cost_per_applied_session']);
        self::assertSame(4000, $metrics['p95_duration_ms']);
        self::assertSame(240, $metrics['oldest_queue_age_seconds']);
        self::assertSame('RUB', $metrics['currency']);
    }

    public function test_mixed_currency_cost_is_not_reported_as_one_false_total(): void
    {
        $metrics = EstimateGenerationDashboardMetrics::fromRows(
            ['sessions_total' => 2, 'successful_sessions' => 2, 'applied_sessions' => 1],
            ['total_cost' => null, 'currency' => null],
            [],
        );

        self::assertNull($metrics['total_cost']);
        self::assertNull($metrics['cost_per_successful_session']);
        self::assertNull($metrics['cost_per_applied_session']);
        self::assertNull($metrics['currency']);
    }

    public function test_dashboard_period_is_bounded_for_operational_queries(): void
    {
        $filters = DashboardFilters::fromArray([
            'date_from' => '2025-01-01',
            'date_to' => '2026-07-14',
        ], CarbonImmutable::parse('2026-07-14 12:00:00 UTC'));

        self::assertSame(93.0, $filters->from->diffInDays($filters->until));
    }
}
