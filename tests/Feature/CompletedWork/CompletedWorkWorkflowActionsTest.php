<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\BusinessModules\Features\WorkflowManagement\Services\MobileWorkflowTaskService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Project;
use App\Models\User;
use App\Services\CompletedWork\CompletedWorkWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class CompletedWorkWorkflowActionsTest extends TestCase
{
    use EnablesImmutableAuditWriter;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnTrue();
            $mock->shouldReceive('canAccessInterface')->andReturnTrue();
            $mock->shouldReceive('hasRole')->andReturnTrue();
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $context = null) => $user->roleAssignments()
                ->where('is_active', true)->when($context, static fn ($query) => $query->where('context_id', $context->id))->get());
        });
    }

    public function test_http_rejection_edit_and_resubmit_preserve_history_and_repeat_safely(): void
    {
        [$context, $work] = $this->fixture();
        $url = '/api/v1/admin/projects/'.$work->project_id.'/works/'.$work->id;
        $this->withHeaders($context->authHeaders())->postJson($url.'/reject', ['reason' => 'Исправьте объём'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.status_history.0.action', 'reject');
        $this->withHeaders($context->authHeaders())->postJson($url.'/reject', ['reason' => 'Исправьте объём'])->assertOk();
        self::assertCount(1, data_get($work->fresh()->additional_info, 'mobile_workflow.status_history'));
        $this->withHeaders($context->authHeaders())->putJson($url, ['status' => 'pending'])->assertUnprocessable();
        $edit = $this->withHeaders($context->authHeaders())->putJson($url, ['notes' => 'Объём исправлен', 'additional_info' => [
            'work_name' => 'Монтаж секции', 'unit_of_measurement' => 'шт.', 'location' => 'Секция А',
        ]])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonCount(1, 'data.status_history');
        self::assertContains('submit', $edit->json('data.available_actions'));
        self::assertNotContains('confirm', $edit->json('data.available_actions'));
        $this->withHeaders($context->authHeaders())->postJson($url.'/submit')->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->withHeaders($context->authHeaders())->postJson($url.'/submit')->assertOk();
        $confirmed = $this->withHeaders($context->authHeaders())->postJson($url.'/confirm')->assertOk()->assertJsonPath('data.status', 'confirmed');
        self::assertSame(['reject', 'submit', 'confirm'], array_column($confirmed->json('data.status_history'), 'action'));
        self::assertCount(1, $confirmed->json('data.comments'));
        $this->withHeaders($context->authHeaders())->postJson($url.'/confirm')->assertOk()->assertJsonCount(3, 'data.status_history');
    }

    public function test_mobile_alias_and_old_history_are_preserved_by_shared_workflow(): void
    {
        [$context, $work] = $this->fixture();
        $work->update(['additional_info' => [...$work->additional_info, 'mobile_workflow' => [
            'status_history' => [['id' => 'old', 'action' => 'submit', 'from_status' => 'draft', 'to_status' => 'pending', 'comment' => null, 'user_id' => $context->user->id, 'created_at' => '2026-10-10T10:00:00Z']],
            'comments' => [],
        ]]]);
        $mobile = app(MobileWorkflowTaskService::class);
        self::assertContains('approve', $mobile->availableActions($context->user, $work));
        $approved = $mobile->approve($work, $context->user, 'Проверено');
        $history = data_get($approved->additional_info, 'mobile_workflow.status_history');
        self::assertSame(['submit', 'approve'], array_column($history, 'action'));
        self::assertSame('old', $history[0]['id']);
        self::assertSame('Проверено', data_get($approved->additional_info, 'mobile_workflow.comments.0.comment'));
    }

    public function test_request_changes_requires_comment_and_readiness_and_writes_history(): void
    {
        [$context, $work] = $this->fixture();
        $workflow = app(CompletedWorkWorkflowService::class);
        try {
            $workflow->requestChanges($work, $context->user, ' ');
            self::fail('Пустой комментарий недопустим');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $work->update(['quantity' => 0, 'completed_quantity' => 0]);
        self::assertNotContains('request_changes', $workflow->availableActions($context->user, $work));
        try {
            $workflow->requestChanges($work, $context->user, 'Исправьте данные');
            self::fail('Неготовый факт недопустим');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        self::assertSame('pending', $work->fresh()->status);
        $work->update(['quantity' => 1, 'completed_quantity' => 1]);
        $review = $workflow->requestChanges($work, $context->user, 'Проверьте участок');
        self::assertSame('in_review', $review->status);
        self::assertSame('request_changes', data_get($review->additional_info, 'mobile_workflow.status_history.0.action'));
    }

    public function test_inactive_membership_and_unassigned_project_block_service_actions(): void
    {
        [$context, $work] = $this->fixture();
        $workflow = app(CompletedWorkWorkflowService::class);
        foreach (['inactive', 'unassigned', 'organization_disabled'] as $condition) {
            $context->organization->update(['is_active' => $condition !== 'organization_disabled']);
            $context->user->organizations()->updateExistingPivot($context->organization->id, [
                'is_active' => $condition !== 'inactive', 'project_access_mode' => $condition === 'unassigned' ? 'assigned_projects' : 'all_projects',
            ]);
            self::assertSame([], $workflow->availableActions($context->user, $work));
            try {
                $workflow->reject($work, $context->user, 'Не разрешено');
                self::fail('Нет доступа к действию');
            } catch (BusinessLogicException $exception) {
                self::assertContains($exception->getCode(), [403, 404]);
            }
        }
        self::assertSame('pending', $work->fresh()->status);
        self::assertNull(data_get($work->fresh()->additional_info, 'mobile_workflow'));
    }

    public function test_journal_origin_and_permissionless_actor_cannot_mutate_workflow(): void
    {
        [$context, $work] = $this->fixture();
        $work->update(['work_origin_type' => CompletedWork::ORIGIN_JOURNAL]);
        $workflow = app(CompletedWorkWorkflowService::class);
        self::assertSame([], $workflow->availableActions($context->user, $work));
        foreach (['confirm', 'submit', 'reject', 'requestChanges'] as $action) {
            try {
                $workflow->{$action}($work, $context->user, 'Комментарий');
                self::fail('Приёмка журнала только через журнал');
            } catch (BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
        $work->update(['work_origin_type' => CompletedWork::ORIGIN_MANUAL]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnFalse();
        self::assertSame([], app(CompletedWorkWorkflowService::class)->availableActions($context->user, $work));
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(403);
        app(CompletedWorkWorkflowService::class)->submit($work, $context->user);
    }

    public function test_participant_can_submit_own_work_but_cannot_review_or_submit_sibling_work(): void
    {
        [$owner, $work] = $this->fixture();
        $participant = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $participant->user->organizations()->updateExistingPivot($participant->organization->id, ['project_access_mode' => 'all_projects']);
        $work->project->organizations()->attach($participant->organization->id, ['is_active' => true, 'role' => 'contractor', 'role_new' => 'contractor']);
        $contractor = \App\Models\Contractor::query()->firstOrCreate([
            'organization_id' => $owner->organization->id, 'source_organization_id' => $participant->organization->id,
        ], ['name' => 'Подрядчик']);
        $work->update(['contractor_id' => $contractor->id]);
        $workflow = app(CompletedWorkWorkflowService::class);
        $actions = $workflow->availableActions($participant->user, $work);
        self::assertContains('submit', $actions);
        self::assertNotContains('confirm', $actions);
        self::assertNotContains('reject', $actions);
        try {
            $workflow->reject($work, $participant->user, 'Недопустимо');
            self::fail('Подрядчик не принимает свою работу');
        } catch (BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        self::assertSame('in_review', $workflow->submit($work, $participant->user)->status);
        $work->update(['status' => 'rejected', 'contractor_id' => null]);
        self::assertSame([], $workflow->availableActions($participant->user, $work->fresh()));
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(403);
        $workflow->submit($work->fresh(), $participant->user);
    }

    public function test_ordinary_put_cannot_replace_existing_workflow_history(): void
    {
        [$context, $work] = $this->fixture();
        app(CompletedWorkWorkflowService::class)->reject($work, $context->user, 'Причина');
        $originalWorkflow = data_get($work->fresh()->additional_info, 'mobile_workflow');
        $this->withHeaders($context->authHeaders())->putJson('/api/v1/admin/projects/'.$work->project_id.'/works/'.$work->id, [
            'additional_info' => ['mobile_workflow' => ['status_history' => [], 'comments' => []]],
        ])->assertOk();
        self::assertSame($originalWorkflow, data_get($work->fresh()->additional_info, 'mobile_workflow'));
        self::assertSame('rejected', $work->fresh()->status);
        $forgedWork = $work->fresh();
        $forgedWork->additional_info = [...$forgedWork->additional_info, 'mobile_workflow' => ['status_history' => [], 'comments' => []]];
        $dto = \App\DTOs\CompletedWork\CompletedWorkDTO::fromModel($forgedWork);
        try {
            app(\App\Services\CompletedWork\CompletedWorkService::class)->update($work->id, $dto, $context->user);
            self::fail('Сервис запрещает подмену истории при прямом вызове');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame(trans_message('completed_work.workflow_operation_required'), $exception->getMessage());
        }
        self::assertSame($originalWorkflow, data_get($work->fresh()->additional_info, 'mobile_workflow'));
        self::assertSame('rejected', $work->fresh()->status);
    }

    public function test_http_read_and_edit_reject_wrong_project_and_inactive_membership(): void
    {
        [$context, $work] = $this->fixture();
        $foreignProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignUrl = '/api/v1/admin/projects/'.$foreignProject->id.'/works/'.$work->id;
        $this->withHeaders($context->authHeaders())->getJson($foreignUrl)->assertNotFound();
        $this->withHeaders($context->authHeaders())->putJson($foreignUrl, ['notes' => 'Подмена'])->assertNotFound();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['is_active' => false]);
        $url = '/api/v1/admin/projects/'.$work->project_id.'/works/'.$work->id;
        foreach (['read', 'edit'] as $operation) {
            $response = $operation === 'read'
                ? $this->withHeaders($context->authHeaders())->getJson($url)
                : $this->withHeaders($context->authHeaders())->putJson($url, ['notes' => 'Подмена']);
            self::assertContains($response->status(), [403, 404]);
        }
        self::assertNull($work->fresh()->notes);
    }

    public function test_http_journal_origin_is_readable_and_direct_mutations_are_blocked(): void
    {
        [$context, $work] = $this->fixture();
        $work->update(['work_origin_type' => CompletedWork::ORIGIN_JOURNAL]);
        $url = '/api/v1/admin/projects/'.$work->project_id.'/works/'.$work->id;
        $this->withHeaders($context->authHeaders())->getJson($url)->assertOk()->assertJsonPath('data.available_actions', []);
        $this->withHeaders($context->authHeaders())->putJson($url, ['notes' => 'Подмена'])->assertUnprocessable();
        $this->withHeaders($context->authHeaders())->postJson($url.'/confirm')->assertUnprocessable();
        $this->withHeaders($context->authHeaders())->postJson($url.'/reject', ['reason' => 'Подмена'])->assertUnprocessable();
        $this->withHeaders($context->authHeaders())->postJson($url.'/submit')->assertUnprocessable();
        self::assertSame('pending', $work->fresh()->status);
        self::assertNull($work->fresh()->notes);
        self::assertNull(data_get($work->fresh()->additional_info, 'mobile_workflow'));
    }

    public function test_delete_action_uses_existing_delete_permission_and_mutation_guard(): void
    {
        [$context, $work] = $this->fixture();
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(
            static fn ($actor, string $permission): bool => $permission !== 'completed_works.delete'
        );
        self::assertNotContains('delete', app(CompletedWorkWorkflowService::class)->availableActions($context->user, $work));
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(
            static fn ($actor, string $permission): bool => $permission === 'completed_works.delete'
        );
        $work->update(['status' => CompletedWork::STATUS_CANCELLED]);
        self::assertSame(['delete'], app(CompletedWorkWorkflowService::class)->availableActions($context->user, $work));
        self::assertTrue(app(\App\Services\CompletedWork\CompletedWorkService::class)->delete($work->id, $work->organization_id, $context->user));
        $this->assertSoftDeleted('completed_works', ['id' => $work->id]);
    }

    public function test_mobile_wire_keeps_legacy_actions_safe_and_exposes_full_canonical_workflow(): void
    {
        [$context, $work] = $this->fixture();
        $request = \Illuminate\Http\Request::create('/api/v1/mobile/workflow-management/tasks/'.$work->id);
        $request->setUserResolver(static fn () => $context->user);
        $resource = new \App\BusinessModules\Features\WorkflowManagement\Http\Resources\MobileWorkflowTaskResource($work);
        $pending = $resource->toArray($request);
        $legacyKeys = ['approve', 'reject', 'request_changes', 'comment'];
        self::assertContains('submit', $pending['workflow_actions']);
        self::assertNotContains('submit', $pending['available_actions']);
        self::assertSame([], array_values(array_diff($pending['available_actions'], $legacyKeys)));
        self::assertSame($pending['available_actions'], $pending['workflow_summary']['available_actions']);
        self::assertContains($pending['workflow_summary']['next_action'], $legacyKeys);
        $workflow = app(CompletedWorkWorkflowService::class);
        $rejected = $workflow->reject($work, $context->user, 'Исправьте данные');
        $review = $workflow->submit($rejected, $context->user);
        $confirmed = $workflow->confirm($review, $context->user);
        $canonical = $workflow->workflowData($confirmed);
        $response = (new \App\BusinessModules\Features\WorkflowManagement\Http\Resources\MobileWorkflowTaskResource($confirmed))->toArray($request);
        self::assertSame(['reject', 'submit', 'confirm'], array_column($response['workflow_status_history'], 'action'));
        self::assertSame(['reject', 'approve'], array_column($response['status_history'], 'action'));
        self::assertSame($canonical['status_history'], $response['workflow_status_history']);
        self::assertSame($canonical['comments'], $response['comments']);
        self::assertSame($canonical['status_history'][2]['id'], $response['status_history'][1]['id']);
        self::assertSame($canonical, $workflow->workflowData($confirmed->fresh()));
    }

    public function test_legacy_put_review_uses_submit_history_and_rejects_other_status_bypasses(): void
    {
        [$context, $work] = $this->fixture();
        $url = '/api/v1/admin/projects/'.$work->project_id.'/works/'.$work->id;
        $this->withHeaders($context->authHeaders())->putJson($url, ['status' => 'in_review', 'completed_quantity' => 0, 'notes' => 'Не сохранять'])->assertUnprocessable();
        self::assertSame('pending', $work->fresh()->status);
        self::assertSame(1.0, $work->fresh()->effectiveCompletedQuantity());
        self::assertNull($work->fresh()->notes);
        $this->withHeaders($context->authHeaders())->putJson($url, ['status' => 'in_review', 'notes' => 'Исправлено'])
            ->assertOk()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.status_history.0.action', 'submit');
        $this->withHeaders($context->authHeaders())->putJson($url, ['status' => 'in_review', 'notes' => 'Исправлено'])->assertOk()->assertJsonCount(1, 'data.status_history');
        $this->withHeaders($context->authHeaders())->putJson($url, ['status' => 'rejected'])->assertUnprocessable();
        $this->withHeaders($context->authHeaders())->putJson($url, ['status' => 'confirmed'])->assertUnprocessable();
        self::assertSame('in_review', $work->fresh()->status);
        self::assertSame('submit', data_get($work->fresh()->additional_info, 'mobile_workflow.status_history.0.action'));
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'is_archived' => false]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'user_id' => $context->user->id,
            'quantity' => 1, 'completed_quantity' => 1, 'completion_date' => '2026-10-11', 'status' => 'pending',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED,
            'additional_info' => ['work_name' => 'Монтаж секции', 'unit_of_measurement' => 'шт.', 'location' => 'Секция А'],
        ]);

        return [$context, $work];
    }
}
