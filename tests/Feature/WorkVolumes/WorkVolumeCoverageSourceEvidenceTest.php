<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeCoverageSourceEvidence;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\BusinessModules\Features\DesignManagement\Services\DesignSourceLinkService;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Exceptions\BusinessLogicException;
use App\Modules\Core\AccessController;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeCoverageSourceEvidenceTest extends TestCase
{
    public function test_design_link_evidence_is_scoped_and_snapshot_stays_immutable(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => 'E-1', 'name' => 'Estimate', 'type' => 'local',
            'status' => 'approved', 'version' => 1, 'estimate_date' => '2026-09-20',
        ]);
        $unit = MeasurementUnit::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'short_name' => 'м'],
            ['name' => 'Метр', 'type' => 'work'],
        );
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Работа',
            'quantity' => 10, 'measurement_unit_id' => $unit->id,
        ]);
        $package = DesignPackage::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'title' => 'Проект',
        ]);
        $artifact = DesignArtifact::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'created_by' => $user->id,
            'artifact_type' => 'model', 'title' => 'Модель',
        ]);
        $version = DesignArtifactVersion::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'artifact_id' => $artifact->id, 'uploaded_by' => $user->id,
            'title' => 'Модель, редакция 1', 'version_number' => '1',
            'source_file_path' => 'test/model.ifc', 'source_original_name' => 'model.ifc',
            'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1,
            'file_format' => 'ifc', 'is_current' => true,
        ]);
        $sheet = DesignDocumentSheet::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'package_id' => $package->id, 'artifact_id' => $artifact->id, 'version_id' => $version->id,
            'sheet_number' => 'АР-1', 'sheet_title' => 'План',
        ]);
        $element = DesignIfcModelElement::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'version_id' => $version->id, 'express_id' => 7, 'global_id' => 'global-7',
            'name' => 'Стена', 'category' => 'Wall',
        ]);
        $link = DesignSourceLink::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'source_version_id' => $version->id, 'source_sheet_id' => $sheet->id,
            'source_element_id' => $element->express_id,
            'source_snapshot' => ['title' => 'Модель, редакция 1', 'revision' => 'A'],
            'target_type' => 'estimate_item', 'target_id' => $item->id,
            'target_snapshot' => ['type' => 'estimate_item', 'label' => 'Работа'],
            'created_by' => $user->id,
        ]);
        $link->forceFill(['row_version' => 4])->save();
        $review = DesignImpactReview::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'link_id' => $link->id, 'previous_version_id' => $version->id,
            'new_version_id' => $version->id, 'status' => 'pending',
        ]);

        $evidence = app(WorkVolumeCoverageSourceEvidence::class)->resolve(
            $organization->id, $project->id, $item->id, $link->id,
        );

        self::assertNotNull($evidence);
        self::assertSame(4, $evidence['source_link_row_version']);
        self::assertSame(['title' => 'Модель, редакция 1', 'revision' => 'A'], $evidence['source_snapshot']);
        self::assertSame($sheet->id, $evidence['source_sheet']['id']);
        self::assertSame($element->id, $evidence['source_element']['id']);
        self::assertSame([$review->id], array_column($evidence['pending_impact_reviews'], 'id'));

        $link->forceFill(['source_snapshot' => ['title' => 'Изменённая запись'], 'row_version' => 5])->save();
        self::assertSame(4, $evidence['source_link_row_version']);
        self::assertSame('Модель, редакция 1', $evidence['source_snapshot']['title']);
        self::assertNull(app(WorkVolumeCoverageSourceEvidence::class)->resolve($organization->id, $project->id, $item->id, null));
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $this->expectExceptionCode(422);
        app(WorkVolumeCoverageSourceEvidence::class)->resolve($organization->id, $project->id, $item->id + 999999, $link->id);
    }

    public function test_replace_allocations_persists_design_snapshot_across_link_revision(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('hasRole')->andReturnTrue();
        $authorization->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        $authorization->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()->where('is_active', true)->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))->get());
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $context->organization->id, 'short_name' => 'м'], ['name' => 'Метр', 'type' => 'work']);
        $estimate = Estimate::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'number' => 'E-SOURCE', 'name' => 'Смета', 'status' => 'approved', 'estimate_date' => '2026-09-20']);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Работа', 'quantity' => 10, 'measurement_unit_id' => $unit->id]);
        $contractor = Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'Подрядчик']);
        $contract = Contract::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'C-SOURCE', 'date' => '2026-09-20', 'total_amount' => 10000]);
        ContractEstimateItem::query()->create(['contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'quantity' => 10]);
        $link = $this->designLink($context->organization->id, $project->id, $context->user->id, $item->id, ['title' => 'Основание A']);
        $statement = app(WorkVolumeStatementService::class)->createDraft($context->user, $project->id, ['lines' => [['line_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Работа', 'unit_code' => 'м', 'quantity' => '10', 'place' => ['axis' => 'А-1']]]]);
        $statement = app(WorkVolumeStatementService::class)->approve($context->user, app(WorkVolumeStatementService::class)->submitForReview($context->user, $statement, 0), 1);
        $row = ['statement_line_id' => $statement->lines->first()->id, 'contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'quantity' => '4', 'unit_code' => 'м', 'source_link_id' => $link->id];
        $coverage = app(\App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeCoverageService::class);
        $coverageUrl = "/api/v1/admin/projects/{$project->id}/work-volume-statements/{$statement->id}/coverage";
        $payload = ['allocations' => [$row], 'operation_key' => 'source-snapshot-1', 'expected_coverage_revision' => 0];
        $this->withHeaders($context->authHeaders())->putJson($coverageUrl, ['allocations' => [$row]])->assertUnprocessable();
        $first = $this->withHeaders($context->authHeaders())->putJson($coverageUrl, $payload)
            ->assertOk()->assertJsonPath('data.coverage_revision', 1)->json('data');
        $stored = DB::table('work_volume_statement_coverages')->where('statement_id', $statement->id)->where('coverage_revision', 1)->first();
        self::assertNotNull($stored);
        self::assertSame('Основание A', json_decode((string) $stored->source_snapshot, true)['allocation_source']['source_snapshot']['title']);
        $oldVersion = DesignArtifactVersion::query()->findOrFail($link->source_version_id);
        $newVersion = DesignArtifactVersion::query()->create([
            'organization_id' => $oldVersion->organization_id, 'project_id' => $oldVersion->project_id,
            'artifact_id' => $oldVersion->artifact_id, 'uploaded_by' => $context->user->id,
            'title' => 'Новая версия', 'version_number' => (string) ((int) $oldVersion->version_number + 1), 'source_file_path' => 'test/model-v2.ifc',
            'source_original_name' => 'model-v2.ifc', 'source_mime_type' => 'application/octet-stream',
            'source_size_bytes' => 1, 'file_format' => 'ifc', 'is_current' => true,
        ]);
        self::assertSame(1, app(DesignSourceLinkService::class)->createImpactReviewsForRevision($oldVersion, $newVersion));
        $reviews = $coverage->sourceReviews($context->user, $statement, 10);
        self::assertSame(1, $reviews->total());
        $review = $reviews->getCollection()->first();
        self::assertSame($oldVersion->id, $review->previous_version_id);
        self::assertSame($newVersion->id, $review->new_version_id);
        self::assertSame('pending', $review->status);
        self::assertSame('Основание A', $review->link->source_snapshot['title']);
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/projects/{$project->id}/work-volume-statements/{$statement->id}/coverage/source-reviews?per_page=10")
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->withHeaders($context->authHeaders())->getJson("/api/v1/admin/projects/999999/work-volume-statements/{$statement->id}/coverage/source-reviews")
            ->assertNotFound();
        $link->forceFill(['source_snapshot' => ['title' => 'Основание B'], 'row_version' => 2])->save();
        $actual = $coverage->allocations($context->user, $statement);
        self::assertSame('Основание A', $actual['allocations'][0]['source_snapshot']['allocation_source']['source_snapshot']['title']);
        $this->withHeaders($context->authHeaders())->putJson($coverageUrl, $payload)->assertOk()
            ->assertJsonPath('data.allocations.0.source_snapshot.allocation_source.source_snapshot.title', 'Основание A');
        self::assertSame(1, $first['coverage_revision']);
    }

    public function test_invalid_cross_project_target_and_ended_source_are_rejected(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('hasRole')->andReturnTrue();
        $authorization->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        $authorization->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()->where('is_active', true)->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))->get());
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = Estimate::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'number' => 'E-INVALID', 'name' => 'Смета', 'status' => 'approved', 'estimate_date' => '2026-09-20']);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $context->organization->id, 'short_name' => 'м'], ['name' => 'Метр', 'type' => 'work']);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Работа', 'quantity' => 10, 'measurement_unit_id' => $unit->id]);
        $link = $this->designLink($context->organization->id, $project->id, $context->user->id, $item->id, ['title' => 'Активный источник']);
        try {
            app(WorkVolumeCoverageSourceEvidence::class)->resolve($context->organization->id, $project->id, $item->id + 1, $link->id);
            self::fail('Чужая целевая сметная позиция должна быть отклонена');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $foreignProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignEstimate = Estimate::query()->create(['organization_id' => $context->organization->id, 'project_id' => $foreignProject->id, 'number' => 'E-FOREIGN', 'name' => 'Чужая смета', 'status' => 'approved', 'estimate_date' => '2026-09-20']);
        $foreignItem = EstimateItem::query()->create(['estimate_id' => $foreignEstimate->id, 'position_number' => '1', 'name' => 'Чужая работа', 'quantity' => 1, 'measurement_unit_id' => $unit->id]);
        try {
            app(WorkVolumeCoverageSourceEvidence::class)->resolve($context->organization->id, $foreignProject->id, $foreignItem->id, $link->id);
            self::fail('Источник другого проекта должен быть отклонён');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $link->forceFill(['status' => 'ended'])->save();
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        app(WorkVolumeCoverageSourceEvidence::class)->resolve($context->organization->id, $project->id, $item->id, $link->id);
    }

    public function test_batch_resolve_keeps_query_count_bounded_for_twenty_links(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = Estimate::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'number' => 'E-BATCH', 'name' => 'Смета', 'status' => 'approved', 'estimate_date' => '2026-09-20']);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $context->organization->id, 'short_name' => 'м'], ['name' => 'Метр', 'type' => 'work']);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Работа', 'quantity' => 10, 'measurement_unit_id' => $unit->id]);
        $links = collect(range(1, 20))->mapWithKeys(fn (int $number): array => [($link = $this->designLink($context->organization->id, $project->id, $context->user->id, $item->id, ['title' => 'Источник '.$number]))->id => $item->id])->all();
        $queries = 0;
        DB::listen(static function () use (&$queries): void { $queries++; });
        $resolved = app(WorkVolumeCoverageSourceEvidence::class)->resolveMany($context->organization->id, $project->id, $links);
        self::assertCount(20, $resolved);
        self::assertLessThanOrEqual(9, $queries);
    }

    private function designLink(int $organizationId, int $projectId, int $userId, int $itemId, array $snapshot): DesignSourceLink
    {
        $package = DesignPackage::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'created_by' => $userId, 'title' => 'Проект']);
        $artifact = DesignArtifact::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'package_id' => $package->id, 'created_by' => $userId, 'artifact_type' => 'model', 'title' => 'Модель']);
        $version = DesignArtifactVersion::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'artifact_id' => $artifact->id, 'uploaded_by' => $userId, 'title' => 'Версия', 'version_number' => (string) $artifact->id, 'source_file_path' => 'test/model.ifc', 'source_original_name' => 'model.ifc', 'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1, 'file_format' => 'ifc', 'is_current' => true]);
        return DesignSourceLink::query()->create(['organization_id' => $organizationId, 'project_id' => $projectId, 'source_version_id' => $version->id, 'source_snapshot' => $snapshot, 'target_type' => 'estimate_item', 'target_id' => $itemId, 'target_snapshot' => ['type' => 'estimate_item', 'label' => 'Работа'], 'created_by' => $userId]);
    }
}
