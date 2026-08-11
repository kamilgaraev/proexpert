<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Models\Project;
use DomainException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryOperationsIntegrityTest extends TestCase
{
    public function test_shift_report_rejects_asset_project_mismatch_within_organization(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = $this->createAsset((int) $context->organization->id, (int) $projectA->id, 'INT-ASSET-1');
        $assignment = $this->createAssignment($asset, (int) $projectA->id, (int) $context->user->id);

        $this->expectException(DomainException::class);

        $this->service()->createShiftReport(
            (int) $context->organization->id,
            (int) $context->user->id,
            [
                'asset_id' => $asset->id,
                'project_id' => $projectB->id,
                'assignment_id' => $assignment->id,
                'report_date' => now()->toDateString(),
                'actual_hours' => 8,
                'fuel_consumed' => 10,
            ],
        );
    }

    public function test_shift_report_rejects_assignment_for_another_asset(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = $this->createAsset((int) $context->organization->id, (int) $project->id, 'INT-ASSET-2');
        $otherAsset = $this->createAsset((int) $context->organization->id, (int) $project->id, 'INT-ASSET-3');
        $this->createAssignment($asset, (int) $project->id, (int) $context->user->id);
        $otherAssignment = $this->createAssignment($otherAsset, (int) $project->id, (int) $context->user->id);

        $this->expectException(DomainException::class);

        $this->service()->createShiftReport(
            (int) $context->organization->id,
            (int) $context->user->id,
            [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'assignment_id' => $otherAssignment->id,
                'report_date' => now()->toDateString(),
                'actual_hours' => 8,
                'fuel_consumed' => 10,
            ],
        );
    }

    public function test_shift_report_requires_active_assignment_for_asset_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = $this->createAsset((int) $context->organization->id, (int) $project->id, 'INT-ASSET-8');

        $this->expectException(DomainException::class);

        $this->service()->createShiftReport(
            (int) $context->organization->id,
            (int) $context->user->id,
            [
                'asset_id' => $asset->id,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'actual_hours' => 8,
                'fuel_consumed' => 10,
            ],
        );
    }

    public function test_downtime_rejects_shift_for_another_asset(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $shiftAsset = $this->createAsset((int) $context->organization->id, (int) $project->id, 'INT-ASSET-4');
        $downtimeAsset = $this->createAsset((int) $context->organization->id, (int) $project->id, 'INT-ASSET-5');
        $this->createAssignment($shiftAsset, (int) $project->id, (int) $context->user->id);
        $this->createAssignment($downtimeAsset, (int) $project->id, (int) $context->user->id);
        $shift = $this->createShift($shiftAsset, (int) $project->id, (int) $context->user->id);

        $this->expectException(DomainException::class);

        $this->service()->createDowntime((int) $context->organization->id, [
            'asset_id' => $downtimeAsset->id,
            'project_id' => $project->id,
            'shift_report_id' => $shift->id,
            'reason' => 'waiting_material',
            'started_at' => now()->subHour(),
            'duration_minutes' => 60,
        ]);
    }

    public function test_production_record_rejects_shift_for_another_project(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = $this->createAsset((int) $context->organization->id, (int) $projectA->id, 'INT-ASSET-6');
        $this->createAssignment($asset, (int) $projectA->id, (int) $context->user->id);
        $shift = $this->createShift($asset, (int) $projectA->id, (int) $context->user->id);

        $this->expectException(DomainException::class);

        $this->service()->createProductionRecord(
            (int) $context->organization->id,
            (int) $context->user->id,
            [
                'asset_id' => $asset->id,
                'project_id' => $projectB->id,
                'shift_report_id' => $shift->id,
                'recorded_at' => now(),
                'quantity' => 100,
                'unit' => 'm3',
            ],
        );
    }

    public function test_fuel_issue_rejects_asset_project_mismatch_within_organization(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = $this->createAsset((int) $context->organization->id, (int) $projectA->id, 'INT-ASSET-10');
        $this->createAssignment($asset, (int) $projectA->id, (int) $context->user->id);

        $this->expectException(DomainException::class);

        $this->service()->createFuelIssue(
            (int) $context->organization->id,
            (int) $context->user->id,
            [
                'asset_id' => $asset->id,
                'project_id' => $projectB->id,
                'issued_at' => now(),
                'fuel_type' => 'diesel',
                'quantity' => 10,
                'unit' => 'l',
            ],
        );
    }

    public function test_asset_rejects_overlapping_active_assignment(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'asset_code' => 'INT-ASSET-7',
            'name' => 'Integrity asset INT-ASSET-7',
            'status' => 'available',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);

        $this->service()->assignAsset($asset, (int) $context->user->id, [
            'project_id' => $projectA->id,
            'planned_start_at' => now()->addDay(),
            'planned_end_at' => now()->addDays(3),
        ]);

        $this->expectException(DomainException::class);

        $this->service()->assignAsset($asset, (int) $context->user->id, [
            'project_id' => $projectB->id,
            'planned_start_at' => now()->addDays(2),
            'planned_end_at' => now()->addDays(4),
        ]);
    }

    public function test_asset_allows_adjacent_active_assignments(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'asset_code' => 'INT-ASSET-9',
            'name' => 'Integrity asset INT-ASSET-9',
            'status' => 'available',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
        $boundary = now()->addDays(3);

        $this->service()->assignAsset($asset, (int) $context->user->id, [
            'project_id' => $projectA->id,
            'planned_start_at' => now()->addDay(),
            'planned_end_at' => $boundary,
        ]);
        $this->service()->assignAsset($asset, (int) $context->user->id, [
            'project_id' => $projectB->id,
            'planned_start_at' => $boundary,
            'planned_end_at' => now()->addDays(5),
        ]);

        self::assertSame(
            2,
            MachineryAssignment::query()->where('asset_id', $asset->id)->where('status', 'active')->count(),
        );
    }

    private function service(): MachineryOperationsService
    {
        return $this->app->make(MachineryOperationsService::class);
    }

    private function createAsset(int $organizationId, int $projectId, string $assetCode): MachineryAsset
    {
        return MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'current_project_id' => $projectId,
            'asset_code' => $assetCode,
            'name' => "Integrity asset {$assetCode}",
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
    }

    private function createAssignment(MachineryAsset $asset, int $projectId, int $userId): MachineryAssignment
    {
        return MachineryAssignment::query()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'project_id' => $projectId,
            'requested_by_user_id' => $userId,
            'approved_by_user_id' => $userId,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ]);
    }

    private function createShift(MachineryAsset $asset, int $projectId, int $userId): MachineryShiftReport
    {
        return MachineryShiftReport::query()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'project_id' => $projectId,
            'reported_by_user_id' => $userId,
            'report_date' => now()->toDateString(),
            'status' => 'draft',
            'planned_hours' => 8,
            'actual_hours' => 8,
            'fuel_consumed' => 10,
        ]);
    }
}
