<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\ProjectCommandCenter;

use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectDeliveryBuilder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ProjectDeliveryBuilderTest extends TestCase
{
    public function test_it_exposes_delivery_risks_and_project_scoped_drill_downs(): void
    {
        $result = (new ProjectDeliveryBuilder())->fromFacts([
            'schedule' => [
                'planned_end_date' => '2026-09-10',
                'baseline_end_date' => '2026-09-01',
                'progress_percent' => 42.5,
                'critical_path_calculated' => true,
                'critical_path_duration_days' => 85,
            ],
            'overdue_stages_count' => 3,
            'critical_work_count' => 2,
            'critical_milestones_count' => 1,
            'pending_work_confirmations_count' => 4,
            'active_safety_findings_count' => 1,
        ], CarbonImmutable::parse('2026-07-21'), 42)->toArray();

        self::assertTrue($result['available']);
        self::assertFalse($result['forecast_completion']['available']);
        self::assertSame('project_command_center.delivery.forecast_completion_unavailable', $result['forecast_completion']['reason_key']);
        self::assertSame(9, $result['schedule_deviation_days']);
        self::assertSame(3, $result['counts']['overdue_stages']);
        self::assertNull($result['counts']['critical_materials']);
        self::assertFalse($result['data_completeness']['critical_materials']['available']);
        self::assertSame(['project_id' => 42], $result['actions']['overdue_stages']['query']);
        self::assertContains('project_command_center.delivery.active_safety_findings', $result['risk_reasons']);
    }

    public function test_it_uses_a_recorded_actual_completion_without_calculating_a_forecast(): void
    {
        $result = (new ProjectDeliveryBuilder())->fromFacts([
            'schedule' => [
                'planned_end_date' => '2026-09-10',
                'actual_end_date' => '2026-09-12',
            ],
        ], CarbonImmutable::parse('2026-07-21'), 42)->toArray();

        self::assertTrue($result['forecast_completion']['available']);
        self::assertSame('2026-09-12', $result['forecast_completion']['date']);
    }

    public function test_it_marks_delivery_unavailable_when_schedule_is_missing(): void
    {
        $result = (new ProjectDeliveryBuilder())->fromFacts([], CarbonImmutable::parse('2026-07-21'), 42)->toArray();

        self::assertSame([
            'available' => false,
            'reason_key' => 'project_command_center.delivery.schedule_unavailable',
        ], $result);
    }
}
