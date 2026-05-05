<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateVersionRestoreService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class EstimateVersioningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_version_routes_use_canonical_nested_api_only(): void
    {
        $this->assertTrue(Route::has('admin.estimates.versions.index'));
        $this->assertTrue(Route::has('admin.estimates.versions.store'));
        $this->assertTrue(Route::has('admin.estimates.versions.compare'));
        $this->assertTrue(Route::has('admin.estimates.versions.rollback'));
        $this->assertFalse(Route::has('admin.estimate_versions.index'));
        $this->assertFalse(Route::has('admin.estimate_versions.store'));
        $this->assertFalse(Route::has('admin.estimate_versions.compare'));
        $this->assertFalse(Route::has('admin.estimate_versions.rollback'));

        $this->assertSame(
            'api/v1/admin/estimates/{estimateId}/versions',
            Route::getRoutes()->getByName('admin.estimates.versions.index')?->uri()
        );
        $this->assertSame(
            'api/v1/admin/estimates/{estimateId}/versions/compare',
            Route::getRoutes()->getByName('admin.estimates.versions.compare')?->uri()
        );
        $this->assertSame(
            'api/v1/admin/estimates/{estimateId}/versions/{versionId}/rollback',
            Route::getRoutes()->getByName('admin.estimates.versions.rollback')?->uri()
        );
    }

    public function test_manual_snapshot_creates_immutable_estimate_version(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate();
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Подготовительные работы',
            'sort_order' => 1,
            'section_total_amount' => 1200,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.1',
            'name' => 'Разметка',
            'item_type' => 'work',
            'quantity' => 2,
            'unit_price' => 600,
            'total_amount' => 1200,
        ]);

        $version = app(EstimateVersioningService::class)->createSnapshot(
            estimate: $estimate,
            actorId: $actor->id,
            label: 'Контрольная версия',
            comment: 'Перед изменениями'
        );

        $estimate->update([
            'name' => 'Измененная смета',
            'total_amount' => 2400,
            'total_amount_with_vat' => 2880,
        ]);
        EstimateItem::query()->where('estimate_id', $estimate->id)->update([
            'name' => 'Измененная позиция',
            'total_amount' => 2400,
        ]);

        $version->refresh();

        $this->assertSame(1, $version->version_number);
        $this->assertSame($estimate->organization_id, $version->organization_id);
        $this->assertSame($actor->id, $version->created_by_user_id);
        $this->assertSame('manual', $version->snapshot_type);
        $this->assertSame('draft', $version->estimate_status);
        $this->assertSame('Контрольная версия', $version->label);
        $this->assertSame('Перед изменениями', $version->comment);
        $this->assertSame('1200.00', $version->total_amount);
        $this->assertSame('1440.00', $version->total_amount_with_vat);
        $this->assertSame('1200.00', $version->total_direct_costs);
        $this->assertSame('Test estimate', $version->snapshot['estimate']['name']);
        $this->assertSame('Разметка', $version->snapshot['sections'][0]['items'][0]['name']);
        $this->assertNotSame($estimate->fresh()->name, $version->snapshot['estimate']['name']);
    }

    public function test_identical_approval_snapshot_is_idempotent_by_hash(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Основные работы',
            'sort_order' => 1,
            'section_total_amount' => 1200,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.1',
            'name' => 'Монтаж',
            'item_type' => 'work',
            'quantity' => 2,
            'unit_price' => 600,
            'total_amount' => 1200,
        ]);

        $service = app(EstimateVersioningService::class);

        $firstVersion = $service->createApprovalSnapshot($estimate, $actor->id);
        $secondVersion = $service->createApprovalSnapshot($estimate->fresh(), $actor->id);

        $this->assertSame($firstVersion->id, $secondVersion->id);
        $this->assertSame($firstVersion->snapshot_hash, $secondVersion->snapshot_hash);
        $this->assertSame(1, $firstVersion->version_number);
        $this->assertSame('approval', $firstVersion->snapshot_type);
        $this->assertSame($actor->id, $firstVersion->approved_by_user_id);
        $this->assertDatabaseCount('estimate_versions', 1);
    }

    public function test_list_versions_returns_canonical_resource_payload(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate();
        $service = app(EstimateVersioningService::class);

        $version = $service->createSnapshot($estimate, $actor->id, 'Baseline');
        $payload = $service->listVersions($estimate);

        $this->assertCount(1, $payload);
        $this->assertSame($version->id, $payload[0]['id']);
        $this->assertSame($estimate->id, $payload[0]['estimateId']);
        $this->assertSame($estimate->id, $payload[0]['estimate_id']);
        $this->assertSame($estimate->organization_id, $payload[0]['organizationId']);
        $this->assertSame($estimate->organization_id, $payload[0]['organization_id']);
        $this->assertSame('manual', $payload[0]['snapshotType']);
        $this->assertSame('manual', $payload[0]['snapshot_type']);
        $this->assertNotEmpty($payload[0]['snapshotHash']);
        $this->assertSame($payload[0]['snapshotHash'], $payload[0]['snapshot_hash']);
        $this->assertSame($payload[0]['totals']['totalAmount'], $payload[0]['total_amount']);
        $this->assertArrayHasKey('created_at', $payload[0]);
        $this->assertArrayHasKey('snapshot', $payload[0]);
        $this->assertArrayHasKey('totals', $payload[0]);
    }

    public function test_approval_status_change_creates_approval_snapshot(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate(['status' => 'in_review']);

        $estimate->update([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);

        $this->assertDatabaseHas('estimate_versions', [
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'snapshot_type' => 'approval',
            'estimate_status' => 'approved',
            'approved_by_user_id' => $actor->id,
        ]);
    }

    public function test_update_status_approval_workflow_creates_one_approval_snapshot_with_metadata(): void
    {
        Gate::before(static fn (): bool => true);

        $estimate = $this->createEstimate(['status' => 'in_review']);
        $actor = User::factory()->create([
            'current_organization_id' => $estimate->organization_id,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('estimate.status_updated', Mockery::on(static function (array $context) use ($estimate, $actor): bool {
                return $context['estimate_id'] === $estimate->id
                    && $context['old_status'] === 'in_review'
                    && $context['new_status'] === 'approved'
                    && $context['user_id'] === $actor->id;
            }));

        $this->updateEstimateStatus($estimate, $actor, 'approved');

        $estimate->refresh();

        $this->assertSame('approved', $estimate->status);
        $this->assertSame($actor->id, $estimate->approved_by_user_id);
        $this->assertNotNull($estimate->approved_at);
        $this->assertSame(1, DB::table('estimate_versions')->where('estimate_id', $estimate->id)->count());
        $this->assertDatabaseHas('estimate_versions', [
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'snapshot_type' => 'approval',
            'estimate_status' => 'approved',
            'approved_by_user_id' => $actor->id,
        ]);
    }

    public function test_update_status_leaving_approved_clears_approval_metadata(): void
    {
        Gate::before(static fn (): bool => true);

        $actor = User::factory()->create();
        $estimate = $this->createEstimate([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
        $actor->forceFill(['current_organization_id' => $estimate->organization_id])->save();

        $this->updateEstimateStatus($estimate, $actor, 'in_review');

        $estimate->refresh();

        $this->assertSame('in_review', $estimate->status);
        $this->assertNull($estimate->approved_by_user_id);
        $this->assertNull($estimate->approved_at);
    }

    public function test_restore_recreates_working_estimate_from_snapshot(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'stable_key' => '11111111-1111-1111-1111-111111111111',
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Snapshot section',
            'sort_order' => 1,
            'section_total_amount' => 1200,
        ]);
        $childSection = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'stable_key' => '22222222-2222-2222-2222-222222222222',
            'parent_section_id' => $section->id,
            'section_number' => '1',
            'full_section_number' => '1.1',
            'name' => 'Snapshot child section',
            'sort_order' => 1,
            'section_total_amount' => 300,
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'stable_key' => '33333333-3333-3333-3333-333333333333',
            'estimate_section_id' => $childSection->id,
            'position_number' => '1.1',
            'name' => 'Snapshot item',
            'item_type' => 'work',
            'quantity' => 2,
            'unit_price' => 600,
            'total_amount' => 1200,
            'direct_costs' => 1200,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'stable_key' => '44444444-4444-4444-4444-444444444444',
            'parent_work_id' => $item->id,
            'position_number' => '1.1.1',
            'name' => 'Snapshot child item',
            'item_type' => 'material',
            'quantity' => 1,
            'unit_price' => 300,
            'total_amount' => 300,
            'direct_costs' => 300,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'stable_key' => '55555555-5555-5555-5555-555555555555',
            'position_number' => '2',
            'name' => 'Snapshot unsectioned item',
            'item_type' => 'work',
            'quantity' => 1,
            'unit_price' => 50,
            'total_amount' => 50,
            'direct_costs' => 50,
        ]);

        $version = app(EstimateVersioningService::class)->createSnapshot(
            estimate: $estimate,
            actorId: $actor->id,
            label: 'Baseline'
        );

        DB::table('estimates')->whereKey($estimate->id)->update([
            'status' => 'approved',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'name' => 'Mutated estimate',
            'total_direct_costs' => 2400,
            'total_amount' => 2400,
            'total_amount_with_vat' => 2880,
        ]);
        EstimateItem::query()->whereKey($item->id)->update([
            'name' => 'Mutated item',
            'total_amount' => 2400,
            'direct_costs' => 2400,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '999',
            'name' => 'Extra item',
            'item_type' => 'work',
            'quantity' => 1,
            'unit_price' => 1,
            'total_amount' => 1,
        ]);

        $restored = app(EstimateVersionRestoreService::class)->restore($estimate->fresh(), $version, $actor->id);

        $restoredItem = $restored->items->firstWhere('stable_key', '33333333-3333-3333-3333-333333333333');
        $restoredChildItem = $restored->items->firstWhere('stable_key', '44444444-4444-4444-4444-444444444444');
        $restoredUnsectionedItem = $restored->items->firstWhere('stable_key', '55555555-5555-5555-5555-555555555555');
        $restoredChildSection = $restored->sections->firstWhere('stable_key', '22222222-2222-2222-2222-222222222222');

        $this->assertSame('Test estimate', $restored->name);
        $this->assertSame('1200.00', $restored->total_amount);
        $this->assertSame('1440.00', $restored->total_amount_with_vat);
        $this->assertSame('1200.00', $restored->total_direct_costs);
        $this->assertSame('draft', $restored->status);
        $this->assertNull($restored->approved_at);
        $this->assertNull($restored->approved_by_user_id);
        $this->assertNotNull($restoredItem);
        $this->assertSame('Snapshot item', $restoredItem->name);
        $this->assertSame('1200.00', $restoredItem->total_amount);
        $this->assertSame($item->id, $restoredItem->id);
        $this->assertNotNull($restoredChildItem);
        $this->assertSame($restoredItem->id, $restoredChildItem->parent_work_id);
        $this->assertNull($restoredChildItem->estimate_section_id);
        $this->assertNotNull($restoredChildSection);
        $this->assertSame($restoredChildSection->id, $restoredItem->estimate_section_id);
        $this->assertNotNull($restoredUnsectionedItem);
        $this->assertNull($restoredUnsectionedItem->estimate_section_id);
        $this->assertFalse($restored->items->contains('name', 'Extra item'));
        $this->assertDatabaseCount('estimate_versions', 3);
        $this->assertSame(
            ['manual', 'pre_restore', 'restore'],
            DB::table('estimate_versions')
                ->where('estimate_id', $estimate->id)
                ->orderBy('version_number')
                ->pluck('snapshot_type')
                ->all()
        );
    }

    public function test_restore_reuses_soft_deleted_item_with_same_stable_key(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate();
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'stable_key' => '66666666-6666-6666-6666-666666666666',
            'position_number' => '1',
            'name' => 'Version A item',
            'item_type' => 'work',
            'quantity' => 1,
            'unit_price' => 100,
            'total_amount' => 100,
            'direct_costs' => 100,
        ]);
        $versionA = app(EstimateVersioningService::class)->createSnapshot(
            estimate: $estimate,
            actorId: $actor->id,
            label: 'Version A'
        );

        $item->delete();
        $versionB = app(EstimateVersioningService::class)->createSnapshot(
            estimate: $estimate->fresh(),
            actorId: $actor->id,
            label: 'Version B'
        );

        $restoreService = app(EstimateVersionRestoreService::class);
        $restoreService->restore($estimate->fresh(), $versionA, $actor->id);
        $restoreService->restore($estimate->fresh(), $versionB, $actor->id);

        $this->assertSoftDeleted('estimate_items', ['id' => $item->id]);

        $restored = $restoreService->restore($estimate->fresh(), $versionA, $actor->id);
        $restoredItem = $restored->items->firstWhere('stable_key', '66666666-6666-6666-6666-666666666666');

        $this->assertNotNull($restoredItem);
        $this->assertSame($item->id, $restoredItem->id);
        $this->assertNull($restoredItem->deleted_at);
        $this->assertDatabaseHas('estimate_items', [
            'id' => $item->id,
            'stable_key' => '66666666-6666-6666-6666-666666666666',
            'deleted_at' => null,
        ]);
    }

    public function test_restore_rejects_version_from_another_estimate(): void
    {
        $actor = User::factory()->create();
        $estimate = $this->createEstimate();
        $anotherEstimate = $this->createEstimate();
        $version = app(EstimateVersioningService::class)->createSnapshot(
            estimate: $anotherEstimate,
            actorId: $actor->id,
            label: 'Foreign version'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Версия не принадлежит выбранной смете');

        app(EstimateVersionRestoreService::class)->restore($estimate, $version, $actor->id);
    }

    private function createEstimate(array $overrides = []): Estimate
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-' . (DB::table('estimates')->count() + 1),
            'name' => 'Test estimate',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-05-05',
            'base_price_date' => '2026-05-01',
            'total_direct_costs' => 1200,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_equipment_costs' => 0,
            'total_amount' => 1200,
            'total_amount_with_vat' => 1440,
            'vat_rate' => 20,
            'overhead_rate' => 0,
            'profit_rate' => 0,
            'calculation_method' => 'resource',
        ], $overrides));
    }

    private function updateEstimateStatus(Estimate $estimate, User $actor, string $status): void
    {
        $this->actingAs($actor);

        $request = \App\Http\Requests\Admin\Estimate\UpdateEstimateStatusRequest::create(
            "/api/v1/admin/projects/{$estimate->project_id}/estimates/{$estimate->id}/status",
            'PUT',
            ['status' => $status]
        );
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $actor);
        $request->attributes->set('current_organization_id', $estimate->organization_id);
        $request->validateResolved();

        $this->app->make(\App\Http\Controllers\Api\V1\Admin\EstimateController::class)
            ->updateStatus($request, $estimate->project_id, $estimate->id);
    }
}
