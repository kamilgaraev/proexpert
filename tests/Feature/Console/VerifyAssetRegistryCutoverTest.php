<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\BusinessModules\Core\AssetManagement\DTO\CreateOrganizationAssetData;
use App\BusinessModules\Core\AssetManagement\Services\OrganizationAssetService;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class VerifyAssetRegistryCutoverTest extends TestCase
{
    public function test_command_returns_go_only_when_every_cutover_gate_is_zero(): void
    {
        $context = AdminApiTestContext::create();
        app(MachineryOperationsService::class)->createAsset((int) $context->organization->id, [
            'asset_code' => 'CUTOVER-READY',
            'name' => 'Готовая единица',
            'inventory_number' => 'CUTOVER-READY',
        ]);

        $exitCode = Artisan::call('assets:verify-cutover', ['--format' => 'json']);

        self::assertSame(0, $exitCode);
        self::assertSame([
            'missing_links' => 0,
            'duplicate_canonical_assets' => 0,
            'dual_write_divergence' => 0,
            'operations_without_organization_asset_id' => 0,
            'open_assignments_with_inconsistent_placement' => 0,
            'ready' => true,
        ], $this->jsonOutput());
    }

    public function test_command_returns_no_go_and_reports_every_broken_invariant(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $service = app(MachineryOperationsService::class);
        $linked = $service->createAsset($organizationId, [
            'asset_code' => 'CUTOVER-DIVERGED',
            'name' => 'Исходное имя',
            'inventory_number' => 'CUTOVER-DIVERGED',
        ]);
        $linked->organizationAsset()->firstOrFail()->update(['name' => 'Несогласованное имя']);

        MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'asset_code' => 'CUTOVER-MISSING',
            'name' => 'Без ссылки',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 0,
            'meter_hours' => 0,
        ]);

        $assets = app(OrganizationAssetService::class);
        foreach (['DUP-A', 'DUP-B'] as $inventoryNumber) {
            $assets->create($organizationId, new CreateOrganizationAssetData(
                name: 'Дубликат источника',
                inventoryNumber: $inventoryNumber,
                metadata: ['legacy_source' => ['table' => 'machinery_assets', 'id' => 999999]],
            ));
        }

        $project = Project::factory()->create(['organization_id' => $organizationId]);
        MachineryAssignment::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $linked->id,
            'organization_asset_id' => null,
            'project_id' => $project->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
        ]);
        MachineryAssignment::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $linked->id,
            'organization_asset_id' => $linked->organization_asset_id,
            'project_id' => $project->id,
            'status' => 'active',
            'planned_start_at' => now()->subMinutes(30),
        ]);

        $exitCode = Artisan::call('assets:verify-cutover', ['--format' => 'json']);
        $report = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertGreaterThanOrEqual(1, $report['missing_links']);
        self::assertSame(1, $report['duplicate_canonical_assets']);
        self::assertSame(1, $report['dual_write_divergence']);
        self::assertSame(1, $report['operations_without_organization_asset_id']);
        self::assertSame(2, $report['open_assignments_with_inconsistent_placement']);
        self::assertFalse($report['ready']);
    }

    public function test_details_report_breaks_down_missing_links_operations_and_assignment_risk(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId]);
        $legacy = MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'asset_code' => 'CUTOVER-DETAILS',
            'name' => 'Диагностируемая единица',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 0,
            'meter_hours' => 0,
        ]);
        foreach ([now()->subHours(2), now()->subHour()] as $plannedStartAt) {
            MachineryAssignment::query()->create([
                'organization_id' => $organizationId,
                'asset_id' => $legacy->id,
                'project_id' => $project->id,
                'status' => 'active',
                'planned_start_at' => $plannedStartAt,
            ]);
        }

        $exitCode = Artisan::call('assets:verify-cutover', ['--format' => 'json', '--details' => true]);
        $report = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertSame([
            'machinery_assets' => 1,
            'operation_profiles' => 0,
            'serialized_projections' => 0,
        ], $report['details']['missing_links']);
        self::assertSame(2, $report['details']['operations_without_canonical_id']['machinery_assignments']);
        self::assertSame(2, $report['details']['assignments']['active']);
        self::assertSame(2, $report['details']['assignments']['currently_effective']);
        self::assertSame(1, $report['details']['assignments']['overlapping_pairs']);
        self::assertSame(1, $report['details']['assignments']['distinct_projects']);
        self::assertSame(0, $report['details']['assignments']['assignment_organization_mismatches']);
        self::assertSame(0, $report['details']['assignments']['project_organization_mismatches']);
        self::assertSame(2, DB::table('machinery_assignments')->whereNull('organization_asset_id')->count());
    }

    public function test_details_report_whether_foreign_project_links_have_one_safe_local_candidate(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        Project::factory()->create(['organization_id' => $organizationId]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $legacy = MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'asset_code' => 'CUTOVER-SCOPE-CANDIDATE',
            'name' => 'Техника с ошибочным проектом',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 0,
            'meter_hours' => 0,
        ]);
        MachineryAssignment::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $legacy->id,
            'project_id' => $foreignProject->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
        ]);

        Artisan::call('assets:verify-cutover', ['--format' => 'json', '--details' => true]);
        $assignments = $this->jsonOutput()['details']['assignments'];

        self::assertSame(1, $assignments['project_organization_mismatches']);
        self::assertSame(1, $assignments['scope_mismatch_assets']);
        self::assertSame(0, $assignments['scope_mismatch_assets_without_candidate']);
        self::assertSame(1, $assignments['scope_mismatch_assets_with_one_candidate']);
        self::assertSame(0, $assignments['scope_mismatch_assets_with_multiple_candidates']);
    }

    public function test_details_report_scope_repair_evidence_without_exposing_names(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $localCurrentProject = Project::factory()->create(['organization_id' => $organizationId]);
        $otherLocalProject = Project::factory()->create(['organization_id' => $organizationId]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $legacy = MachineryAsset::query()->create([
            'organization_id' => $organizationId,
            'current_project_id' => $localCurrentProject->id,
            'asset_code' => 'CUTOVER-SCOPE-EVIDENCE',
            'name' => 'Техника с восстанавливаемым проектом',
            'ownership_type' => 'owned',
            'status' => 'available',
            'operating_cost_per_hour' => 0,
            'meter_hours' => 0,
        ]);
        MachineryAssignment::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $legacy->id,
            'project_id' => $foreignProject->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
        ]);

        Artisan::call('assets:verify-cutover', ['--format' => 'json', '--details' => true]);
        $evidence = $this->jsonOutput()['details']['scope_repair_evidence'];

        self::assertSame([[
            'asset_id' => (int) $legacy->id,
            'organization_id' => $organizationId,
            'foreign_assignment_project_ids' => [(int) $foreignProject->id],
            'legacy_current_project_id' => (int) $localCurrentProject->id,
            'legacy_current_project_is_local' => true,
            'schedule_project_ids' => [],
            'operation_project_ids' => [(int) $foreignProject->id],
            'local_operation_project_ids' => [],
            'local_candidate_project_ids' => collect([$localCurrentProject->id, $otherLocalProject->id])->map(static fn ($id): int => (int) $id)->sort()->values()->all(),
        ]], $evidence);
    }

    /** @return array<string, mixed> */
    private function jsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
