<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeAcceptedAllocationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Modules\Core\AccessController;
use App\Models\User;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\WorkType;
use App\Services\Acting\ActingActWizardService;
use App\Services\ActReport\ActReportWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementAcceptedFloorTest extends TestCase
{
    use \Tests\Support\SubmitsWorkVolumeStatements;

    public function test_actual_accepted_eighty_protects_only_explicitly_mapped_place_and_annulment_releases_it(): void
    {
        $context = AdminApiTestContext::create();
        $actor = $context->user;
        $organization = $context->organization;
        $actor->organizations()->updateExistingPivot($organization->id, ['project_access_mode' => 'all_projects']);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('hasRole')->andReturnTrue();
        $authorization->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        $authorization->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()->where('is_active', true)->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))->get());
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::query()->create(['organization_id' => $organization->id, 'name' => 'Подрядчик']);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'WVS-FLOOR', 'date' => '2026-09-01', 'total_amount' => 100000, 'currency' => 'RUB', 'status' => 'active',
        ]);
        $unit = MeasurementUnit::query()->where('organization_id', $organization->id)->where('short_name', 'м²')->firstOrFail();
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'number' => 'WVS-FLOOR', 'name' => 'Основание', 'status' => 'draft', 'estimate_date' => '2026-09-01',
            'total_amount' => 2000, 'total_amount_with_vat' => 2000, 'vat_rate' => 0,
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id, 'measurement_unit_id' => $unit->id, 'position_number' => '1', 'item_type' => 'work',
            'name' => 'Стена', 'quantity' => 200, 'quantity_total' => 200, 'unit_price' => 10, 'current_unit_price' => 10,
            'total_amount' => 2000, 'current_total_amount' => 2000,
        ]);
        ContractEstimateItem::query()->create([
            'contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id,
            'quantity' => 200, 'amount' => 2000, 'amount_without_vat' => 2000, 'finance_managed' => false,
        ]);
        app(EstimateVersioningService::class)->createSnapshot(estimate: $estimate, actorId: $actor->id, label: 'Согласованная смета', snapshotType: 'approval');
        $estimate->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $actor->id])->save();
        $workType = WorkType::query()->create(['organization_id' => $organization->id, 'name' => 'Стена', 'measurement_unit_id' => $unit->id]);
        $service = app(WorkVolumeStatementService::class);
        $first = ['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1'], 'estimate_item_id' => $item->id];
        $second = [...$first, 'line_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'place' => ['axis' => 'А-2']];
        $statement = $this->approveReviewed($service, $actor, $service->createDraft($actor, $project->id, ['lines' => [$first, $second]]));
        $work = CompletedWork::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'estimate_item_id' => $item->id, 'work_type_id' => $workType->id, 'user_id' => $actor->id,
            'quantity' => 100, 'completed_quantity' => 100, 'price' => 10, 'total_amount' => 1000,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED,
            'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_CONFIRMED,
        ]);
        $act = app(ActingActWizardService::class)->createFromWizard($organization->id, [
            'contract_id' => $contract->id, 'act_document_number' => 'WVS-FLOOR-80', 'act_date' => '2026-09-20',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'selected_works' => [['completed_work_id' => $work->id, 'quantity' => '80']],
        ], $actor->id, false);
        $workflow = app(ActReportWorkflowService::class);
        $workflow->submit($act, $actor->id);
        $approvalLocks = [];
        $captureApprovalLocks = true;
        DB::listen(static function ($query) use (&$approvalLocks, &$captureApprovalLocks): void {
            if ($captureApprovalLocks && str_contains($query->sql, 'for update')) {
                $approvalLocks[] = $query->sql;
            }
        });
        $workflow->approve($act->fresh(), $actor->id);
        $captureApprovalLocks = false;
        $projectLock = collect($approvalLocks)->search(static fn (string $sql): bool => str_contains($sql, '"projects"'));
        $contractLock = collect($approvalLocks)->search(static fn (string $sql): bool => str_starts_with($sql, 'select * from "contracts"'));
        self::assertNotFalse($projectLock, 'Согласование акта должно сериализоваться с согласованием ВОР');
        self::assertNotFalse($contractLock);
        self::assertLessThan($contractLock, $projectLock);
        $actLine = $act->lines()->firstOrFail();
        $unmapped = $service->createRevision($actor, $statement, ['lines' => [[...$first, 'quantity' => '70'], $second], 'change_reason' => 'До сопоставления']);
        try {
            $this->approveReviewed($service, $actor, $unmapped);
            self::fail('Несопоставленные принятые объёмы нельзя считать нулевыми');
        } catch (BusinessLogicException $exception) {
            self::assertSame(trans_message('budget_estimates.work_volume_statements.accepted_source_unmapped'), $exception->getMessage());
        }
        app(WorkVolumeAcceptedAllocationService::class)->mapActLine($actor, $project->id, $actLine->id, [
            ['statement_line_id' => $statement->lines->firstWhere('line_key', $first['line_key'])->id, 'quantity' => '40'],
        ], 'Обмер по осям А-1', 'wvs-floor-mapping');
        $partial = $service->createRevision($actor, $statement, ['lines' => [[...$first, 'quantity' => '70'], $second], 'change_reason' => 'Частичное сопоставление']);
        try {
            $this->approveReviewed($service, $actor, $partial);
            self::fail('Остаток несопоставленного принятого объёма должен оставаться конфликтом');
        } catch (BusinessLogicException $exception) {
            self::assertSame(trans_message('budget_estimates.work_volume_statements.accepted_source_unmapped'), $exception->getMessage());
        }
        $mappingUrl = '/api/v1/admin/projects/'.$project->id.'/work-volume-statements/accepted-mappings/'.$actLine->id;
        $mappingPayload = [
            'allocations' => [['statement_line_id' => $statement->lines->firstWhere('line_key', $first['line_key'])->id, 'quantity' => '80']],
            'reason' => 'Завершение обмера', 'operation_key' => 'wvs-floor-mapping-complete', 'expected_revision' => 1,
        ];
        $response = $this->withHeaders($context->authHeaders())->putJson($mappingUrl, $mappingPayload)
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.allocations.0.quantity', '80.000000');
        $this->withHeaders($context->authHeaders())->putJson($mappingUrl, $mappingPayload)
            ->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->withHeaders($context->authHeaders())->putJson($mappingUrl, [...$mappingPayload, 'reason' => 'Другое основание'])->assertConflict();
        $this->withHeaders($context->authHeaders())->putJson($mappingUrl, [...$mappingPayload, 'operation_key' => 'outdated-mapping'])->assertConflict();
        $this->withHeaders($context->authHeaders())->putJson($mappingUrl, [
            ...$mappingPayload, 'operation_key' => 'excess-mapping', 'expected_revision' => 2,
            'allocations' => [['statement_line_id' => $statement->lines->firstWhere('line_key', $first['line_key'])->id, 'quantity' => '81']],
        ])->assertUnprocessable();
        $this->withHeaders($context->authHeaders())->getJson($mappingUrl)
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.revision', 2)->assertJsonPath('data.0.allocations.0.quantity', '80.000000')
            ->assertJsonPath('data.1.revision', 1)->assertJsonPath('data.1.allocations.0.quantity', '40.000000');

        $moved = $service->createRevision($actor, $statement, ['lines' => [[...$first, 'place' => ['axis' => 'А-9']], $second], 'change_reason' => 'Перенос осей']);
        try {
            $this->approveReviewed($service, $actor, $moved);
            self::fail('Принятый объём нельзя незаметно перенести на другое место');
        } catch (BusinessLogicException $exception) {
            self::assertSame(trans_message('budget_estimates.work_volume_statements.identity_change_after_acceptance'), $exception->getMessage());
        }
        $omitted = $service->createRevision($actor, $statement, ['lines' => [$second], 'change_reason' => 'Исключение строки']);
        try {
            $this->approveReviewed($service, $actor, $omitted);
            self::fail('Принятая строка должна сохраняться в новой редакции');
        } catch (BusinessLogicException $exception) {
            self::assertSame(trans_message('budget_estimates.work_volume_statements.below_accepted_quantity'), $exception->getMessage());
        }

        $reduced = $service->createRevision($actor, $statement, ['lines' => [[...$first, 'quantity' => '70'], $second], 'change_reason' => 'Сокращение']);
        try {
            $this->approveReviewed($service, $actor, $reduced);
            self::fail('Редакция 70 не должна уменьшать принятые 80');
        } catch (BusinessLogicException $exception) {
            self::assertSame(trans_message('budget_estimates.work_volume_statements.below_accepted_quantity'), $exception->getMessage());
        }
        $exact = $service->createRevision($actor, $statement, ['lines' => [[...$first, 'quantity' => '80'], [...$second, 'quantity' => '0']], 'change_reason' => 'По принятому объёму']);
        $exact = $this->approveReviewed($service, $actor, $exact);
        self::assertSame('approved', $exact->status);
        self::assertSame('100.000000', $statement->fresh('lines')->lines->firstWhere('line_key', $first['line_key'])->quantity);
        $workflow->annul($act->fresh(), $actor->id, 'Исправление акта', 'wvs-floor-annul');
        $released = $service->createRevision($actor, $exact, ['lines' => [[...$first, 'quantity' => '0'], [...$second, 'quantity' => '0']], 'change_reason' => 'После аннулирования']);
        self::assertSame('approved', $this->approveReviewed($service, $actor, $released)->status);
        $mappingId = $response->json('data.id');
        $allocationId = $response->json('data.allocations.0.id');
        foreach ([
            fn () => DB::table('work_volume_acceptance_mappings')->where('id', $mappingId)->update(['reason' => 'Подмена']),
            fn () => DB::table('work_volume_acceptance_mappings')->where('id', $mappingId)->delete(),
            fn () => DB::table('work_volume_accepted_allocations')->where('id', $allocationId)->update(['quantity' => '1']),
            fn () => DB::table('work_volume_accepted_allocations')->where('id', $allocationId)->delete(),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                self::fail('История сопоставления должна оставаться неизменяемой после аннулирования акта');
            } catch (QueryException $exception) {
                self::assertSame('55000', $exception->errorInfo[0]);
            } finally {
                DB::rollBack();
            }
        }
        $foreign = AdminApiTestContext::create();
        $foreign->user->organizations()->updateExistingPivot($foreign->organization->id, ['project_access_mode' => 'all_projects']);
        $project->organizations()->attach($foreign->organization->id, ['role' => 'contractor', 'is_active' => true]);
        $this->withHeaders($foreign->authHeaders())->getJson($mappingUrl)->assertNotFound();
        $this->withHeaders($foreign->authHeaders())->putJson($mappingUrl, [...$mappingPayload, 'operation_key' => 'foreign-mapping'])->assertNotFound();
    }
}
