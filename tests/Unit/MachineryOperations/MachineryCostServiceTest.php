<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryCostService;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryCostServiceTest extends TestCase
{
    public function test_cost_is_period_bound_approved_only_and_uses_rate_snapshot(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $project->id,
            'asset_code' => 'COST-1',
            'name' => 'Экскаватор',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 9999,
        ]);
        $this->shift($asset, $project, 'approved', '2026-08-10', 8, 1500);
        $this->shift($asset, $project, 'draft', '2026-08-10', 20, 5000);
        $this->shift($asset, $project, 'approved', '2026-09-01', 20, 5000);
        MachineryFuelIssue::query()->create([
            'organization_id' => $context->organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'issued_at' => '2026-08-10 12:00:00',
            'fuel_type' => 'diesel',
            'quantity' => 20,
            'unit' => 'l',
            'cost' => 2000,
        ]);
        MachineryMaintenanceOrder::query()->create([
            'organization_id' => $context->organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'order_number' => 'MO-COST-1',
            'title' => 'Ремонт',
            'status' => 'completed',
            'completed_at' => '2026-08-11 12:00:00',
            'cost' => 3000,
        ]);

        $report = $this->app->make(MachineryCostService::class)->calculate(
            (int) $context->organization->id,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
            (int) $project->id,
        );

        self::assertSame('approved_only', $report['source_status']);
        self::assertSame(12000.0, $report['totals']['operation_cost']);
        self::assertSame(2000.0, $report['totals']['fuel_cost']);
        self::assertSame(3000.0, $report['totals']['maintenance_cost']);
        self::assertSame(17000.0, $report['totals']['total_cost']);
        self::assertCount(1, $report['evidence']['shifts']);
        self::assertSame('approval_snapshot', $report['evidence']['shifts'][0]['rate_source']);
    }

    private function shift(
        MachineryAsset $asset,
        Project $project,
        string $status,
        string $date,
        float $hours,
        float $rate,
    ): void {
        MachineryShiftReport::query()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'report_date' => $date,
            'status' => $status,
            'actual_hours' => $hours,
            'planned_hours' => $hours,
            'fuel_consumed' => 0,
            'hourly_rate_snapshot' => $rate,
            'approved_at' => $status === 'approved' ? $date.' 20:00:00' : null,
            'cost_evidence' => ['version' => 1],
        ]);
    }
}
