<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Enums\AssetOperationalMode;
use App\BusinessModules\Core\AssetManagement\Services\OrganizationAssetService;
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
    public function test_unsnapshotted_shift_uses_canonical_operation_profile_rate(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $canonical = app(OrganizationAssetService::class)->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData(
                name: 'Canonical excavator',
                inventoryNumber: 'COST-CANONICAL',
                operationalMode: AssetOperationalMode::ShiftOperation,
                operatingCostPerHour: 1250,
            ),
        );
        $legacy = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'organization_asset_id' => $canonical->id,
            'current_project_id' => $project->id,
            'asset_code' => 'COST-CANONICAL',
            'name' => 'Legacy excavator',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 9999,
        ]);
        $this->shift($legacy, $project, 'approved', '2026-08-10', 2, null, (int) $canonical->id);

        $report = app(MachineryCostService::class)->calculate(
            (int) $context->organization->id,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
            (int) $project->id,
        );

        self::assertSame(2500.0, $report['totals']['operation_cost']);
        self::assertSame('canonical_operation_profile', $report['evidence']['shifts'][0]['rate_source']);
    }

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

    public function test_rental_cost_uses_canonical_asset_terms(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $canonical = app(OrganizationAssetService::class)->create(
            (int) $context->organization->id,
            new CreateOrganizationAssetData(
                name: 'Rented crane',
                inventoryNumber: 'RENT-CANONICAL',
                ownershipType: 'leased',
                metadata: ['rental_terms' => [
                    'daily_rate' => 5000,
                    'project_id' => (int) $project->id,
                    'valid_from' => '2026-08-10',
                    'valid_to' => '2026-08-11',
                    'version' => 'contract-v2',
                ]],
            ),
        );
        $legacy = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'organization_asset_id' => $canonical->id,
            'current_project_id' => $project->id,
            'asset_code' => 'RENT-CANONICAL',
            'name' => 'Legacy crane',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 0,
            'metadata' => ['rental_terms' => ['daily_rate' => 1]],
        ]);
        $this->shift($legacy, $project, 'approved', '2026-08-10', 1, 0, (int) $canonical->id);

        $report = app(MachineryCostService::class)->calculate(
            (int) $context->organization->id,
            CarbonImmutable::parse('2026-08-10'),
            CarbonImmutable::parse('2026-08-11'),
            (int) $project->id,
        );

        self::assertSame(10000.0, $report['totals']['rental_cost']);
        self::assertSame((int) $canonical->id, $report['evidence']['rental'][0]['organization_asset_id']);
        self::assertSame('contract-v2', $report['evidence']['rental'][0]['terms_version']);
    }

    private function shift(
        MachineryAsset $asset,
        Project $project,
        string $status,
        string $date,
        float $hours,
        ?float $rate,
        ?int $organizationAssetId = null,
    ): void {
        MachineryShiftReport::query()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'organization_asset_id' => $organizationAssetId,
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
