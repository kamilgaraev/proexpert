<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\CatalogManagement\CatalogManagementModule;
use App\BusinessModules\Features\ContractManagement\ContractManagementModule;
use App\BusinessModules\Features\ProjectManagement\ProjectManagementModule;
use App\BusinessModules\Features\WorkflowManagement\WorkflowManagementModule;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\MeasurementUnit;
use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkflowManagementMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_lists_assigned_workflow_tasks_and_loads_detail(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $assigned = $this->completedWork($context, $project, $workType, [
            'status' => 'pending',
            'notes' => 'Монолитный участок А',
        ]);
        $this->completedWork($context, $project, $workType, [
            'user_id' => User::factory()->create(['current_organization_id' => $context->organization->id])->id,
            'status' => 'pending',
        ]);
        $this->registerWorkflowFoundationModules((int) $context->organization->id);
        $this->assertWorkflowPermissions($context, ['completed_works.view', 'completed_works.edit']);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks?assigned_to_me=1&project_id='.$project->id);

        $this->assertMobileStatus($response, 200);
        $response
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $assigned->id)
            ->assertJsonPath('data.items.0.project_label', $project->name)
            ->assertJsonPath('data.items.0.work_type_label', $workType->name)
            ->assertJsonPath('data.items.0.status', 'pending')
            ->assertJsonPath('data.items.0.status_label', trans_message('workflow_management.statuses.pending'))
            ->assertJsonPath('data.summary.assigned_to_me', true);

        $this->assertContains('approve', $response->json('data.items.0.available_actions'));
        $this->assertContains('request_changes', $response->json('data.items.0.available_actions'));

        $detailResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks/'.$assigned->id);
        $this->assertMobileStatus($detailResponse, 200);
        $detailResponse
            ->assertJsonPath('data.id', $assigned->id)
            ->assertJsonPath('data.notes', 'Монолитный участок А');
    }

    public function test_mobile_actions_persist_status_history_and_comments(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $approved = $this->completedWork($context, $project, $workType, ['status' => 'pending']);
        $changes = $this->completedWork($context, $project, $workType, ['status' => 'pending']);
        $rejected = $this->completedWork($context, $project, $workType, ['status' => 'in_review']);
        $this->registerWorkflowFoundationModules((int) $context->organization->id);
        $this->assertWorkflowPermissions($context, ['completed_works.view', 'completed_works.edit']);

        $approvedResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$approved->id.'/approve', [
                'comment' => 'Объем проверен на объекте',
            ]);
        $this->assertMobileStatus($approvedResponse, 200);
        $approvedResponse
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.status_history.0.action', 'approve')
            ->assertJsonPath('data.comments.0.comment', 'Объем проверен на объекте');

        $changesResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$changes->id.'/request-changes', [
                'comment' => 'Нужно уточнить объем',
            ]);
        $this->assertMobileStatus($changesResponse, 200);
        $changesResponse
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.status_label', 'На проверке')
            ->assertJsonPath('data.status_history.0.action', 'request_changes');

        $rejectedResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$rejected->id.'/reject', [
                'reason' => 'Объем не подтвержден',
            ]);
        $this->assertMobileStatus($rejectedResponse, 200);
        $rejectedResponse
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.status_history.0.action', 'reject');

        $this->assertDatabaseHas('completed_works', [
            'id' => $approved->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('completed_works', [
            'id' => $changes->id,
            'status' => 'in_review',
        ]);
        $this->assertDatabaseHas('completed_works', [
            'id' => $rejected->id,
            'status' => 'rejected',
        ]);
    }

    public function test_mobile_workflow_actions_require_edit_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $task = $this->completedWork($context, $project, $workType, ['status' => 'pending']);
        $this->registerWorkflowFoundationModules((int) $context->organization->id);
        $this->assertWorkflowPermissions($context, ['completed_works.view'], ['completed_works.edit']);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks/'.$task->id)
            ->assertOk()
            ->assertJsonPath('data.available_actions', []);

        $deniedResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$task->id.'/approve');
        $this->assertMobileStatus($deniedResponse, 403);
        $this->assertMobileJsonPath($deniedResponse, 'error_code', 'PERMISSION_DENIED');

        $this->assertDatabaseHas('completed_works', [
            'id' => $task->id,
            'status' => 'pending',
        ]);
    }

    public function test_mobile_standalone_comment_persists_and_enforces_permission_and_organization_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->registerWorkflowFoundationModules((int) $context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $task = $this->completedWork($context, $project, $workType, ['status' => 'pending']);

        $commentResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$task->id.'/comments', [
                'comment' => 'Отдельный комментарий из мобильного приложения',
            ]);

        $this->assertMobileStatus($commentResponse, 200);
        $this->assertMobileJsonPath($commentResponse, 'data.status', 'pending');
        $this->assertMobileJsonPath($commentResponse, 'data.comments.0.action', 'comment');
        $this->assertMobileJsonPath(
            $commentResponse,
            'data.comments.0.comment',
            'Отдельный комментарий из мобильного приложения'
        );
        $persistedTask = $task->refresh();
        self::assertSame(
            'Отдельный комментарий из мобильного приложения',
            data_get($persistedTask->additional_info, 'mobile_workflow.comments.0.comment'),
            'Standalone mobile comment was not persisted in completed_work workflow data.'
        );
        self::assertSame('pending', $persistedTask->status, 'Comment changed the workflow task status.');

        $foreignContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->registerWorkflowFoundationModules((int) $foreignContext->organization->id);
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
        ]);
        $foreignWorkType = $this->workType($foreignContext);
        $foreignTask = $this->completedWork($foreignContext, $foreignProject, $foreignWorkType, [
            'status' => 'pending',
        ]);

        $foreignResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$foreignTask->id.'/comments', [
                'comment' => 'Не должен сохраниться',
            ]);
        $this->assertMobileStatus($foreignResponse, 422);
        self::assertSame(
            [],
            data_get($foreignTask->refresh()->additional_info, 'mobile_workflow.comments', []),
            'Foreign organization comment was persisted.'
        );

        $workerContext = AdminApiTestContext::create(roleSlug: 'worker');
        $this->registerWorkflowFoundationModules((int) $workerContext->organization->id);
        $this->assertWorkflowPermissions($workerContext, ['completed_works.view'], ['completed_works.edit']);
        $workerProject = Project::factory()->create([
            'organization_id' => $workerContext->organization->id,
        ]);
        $workerWorkType = $this->workType($workerContext);
        $workerTask = $this->completedWork($workerContext, $workerProject, $workerWorkType, [
            'status' => 'pending',
        ]);

        $deniedResponse = $this->withHeaders($workerContext->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$workerTask->id.'/comments', [
                'comment' => 'Недостаточно прав',
            ]);
        $this->assertMobileStatus($deniedResponse, 403);
        $this->assertMobileJsonPath($deniedResponse, 'error_code', 'PERMISSION_DENIED');
        self::assertSame(
            [],
            data_get($workerTask->refresh()->additional_info, 'mobile_workflow.comments', []),
            'Comment from a user without edit permission was persisted.'
        );
    }

    public function test_organization_owner_loads_workflow_tasks_with_full_module_access(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $task = $this->completedWork($context, $project, $workType, ['status' => 'pending']);

        $this->registerWorkflowFoundationModules((int) $context->organization->id);
        $this->assertWorkflowPermissions($context, ['completed_works.view']);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks?assigned_to_me=1&project_id='.$project->id);
        $this->assertMobileStatus($response, 200);
        $response
            ->assertJsonPath('data.items.0.id', $task->id)
            ->assertJsonPath('data.items.0.status', 'pending');
    }

    public function test_mobile_detail_exposes_manual_work_name_description_and_review_label(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $task = $this->completedWork($context, $project, $workType, [
            'status' => 'in_review',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
            'quantity' => 0.001,
            'completed_quantity' => 0.001,
            'price' => 0,
            'total_amount' => 0,
            'description' => 'Тестовая ручная запись для мобильной проверки.',
            'additional_info' => ['work_name' => 'QA: кладка в зоне Z'],
        ]);
        $this->registerWorkflowFoundationModules((int) $context->organization->id);
        $this->assertWorkflowPermissions($context, ['completed_works.view']);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks/'.$task->id);

        $this->assertMobileStatus($response, 200);
        $response
            ->assertJsonPath('data.id', $task->id)
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.status_label', 'На проверке')
            ->assertJsonPath('data.work_name', 'QA: кладка в зоне Z')
            ->assertJsonPath('data.description', 'Тестовая ручная запись для мобильной проверки.')
            ->assertJsonPath('data.work_origin_label', 'Ручной ввод')
            ->assertJsonPath('data.planning_status_label', trans_message('workflow_management.planning_statuses.requires_schedule'))
            ->assertJsonPath('data.quantity', 0.001)
            ->assertJsonPath('data.completed_quantity', 0.001)
            ->assertJsonPath('data.price', 0)
            ->assertJsonPath('data.total_amount', 0);
    }

    public function test_mobile_workflow_respects_assigned_project_scope_for_reading_and_mutations(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $task = $this->completedWork($context, $project, $this->workType($context));
        $project->users()->detach($context->user->id);
        DB::table('organization_user')->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)->update(['project_access_mode' => 'assigned_projects']);
        $this->registerWorkflowFoundationModules((int) $context->organization->id);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks')->assertOk()->assertJsonCount(0, 'data.items');
        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks/'.$task->id)->assertNotFound();

        foreach (['approve' => [], 'reject' => ['reason' => 'Объём требует проверки'],
            'request-changes' => ['comment' => 'Уточните объём'], 'comments' => ['comment' => 'Проверка']] as $action => $payload) {
            $this->withHeaders($context->mobileAuthHeaders())
                ->postJson('/api/v1/mobile/workflow-management/tasks/'.$task->id.'/'.$action, $payload)->assertStatus(422);
        }
        self::assertSame('pending', $task->refresh()->status);
        self::assertSame([], data_get($task->additional_info, 'mobile_workflow.status_history', []));
    }

    public function test_mobile_workflow_does_not_expose_or_confirm_journal_origin_facts(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $task = $this->completedWork($context, $project, $this->workType($context), [
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'status' => 'in_review',
        ]);
        $this->registerWorkflowFoundationModules((int) $context->organization->id);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks')->assertOk()->assertJsonCount(0, 'data.items');
        foreach (['approve' => [], 'reject' => ['reason' => 'Проверить исходный журнал'],
            'request-changes' => ['comment' => 'Уточните запись'], 'comments' => ['comment' => 'Проверка']] as $action => $payload) {
            $this->withHeaders($context->mobileAuthHeaders())
                ->postJson('/api/v1/mobile/workflow-management/tasks/'.$task->id.'/'.$action, $payload)->assertStatus(422);
        }
        self::assertSame('in_review', $task->refresh()->status);
    }

    public function test_mobile_confirmation_uses_common_fact_readiness(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $task = $this->completedWork($context, $project, $this->workType($context), [
            'status' => 'draft', 'quantity' => 0, 'completed_quantity' => 0,
        ]);
        $this->registerWorkflowFoundationModules((int) $context->organization->id);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks/'.$task->id)
            ->assertOk()->assertJsonPath('data.available_actions', ['reject', 'comment']);
        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$task->id.'/approve')
            ->assertStatus(422);
        self::assertSame('draft', $task->refresh()->status);
        self::assertSame([], data_get($task->additional_info, 'mobile_workflow.status_history', []));
    }

    public function test_mobile_confirmation_requires_manual_name_and_location(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $task = $this->completedWork($context, $project, $this->workType($context), [
            'additional_info' => ['unit_of_measurement' => 'м³'],
        ]);
        $this->registerWorkflowFoundationModules((int) $context->organization->id);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/'.$task->id.'/approve')->assertStatus(422);
        self::assertSame('pending', $task->refresh()->status);
    }

    private function workType(AdminApiTestContext $context): WorkType
    {
        $unit = MeasurementUnit::query()->firstOrCreate([
            'organization_id' => $context->organization->id,
            'short_name' => 'м³',
        ], [
            'name' => 'Кубический метр', 'is_active' => true,
        ]);
        return WorkType::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Бетонирование',
            'code' => 'CONCRETE',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
    }

    private function completedWork(
        AdminApiTestContext $context,
        Project $project,
        WorkType $workType,
        array $attributes = []
    ): CompletedWork {
        $project->users()->syncWithoutDetaching([$context->user->id => ['role' => 'member', 'is_active' => true]]);
        return CompletedWork::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'work_type_id' => $workType->id,
            'user_id' => $context->user->id,
            'quantity' => 12.5,
            'completed_quantity' => 12.5,
            'price' => 1500,
            'total_amount' => 18750,
            'completion_date' => '2026-05-20',
            'status' => 'pending',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'additional_info' => ['work_name' => 'Бетонирование', 'unit_of_measurement' => 'м³', 'location' => 'Участок А'],
        ], $attributes));
    }

    private function registerWorkflowFoundationModules(int $organizationId): void
    {
        /** @var list<array{ModuleInterface, string}> $modules */
        $modules = [
            [new WorkflowManagementModule, 'ModuleList/features/workflow-management.json'],
            [new ProjectManagementModule, 'ModuleList/features/project-management.json'],
            [new ContractManagementModule, 'ModuleList/features/contract-management.json'],
            [new CatalogManagementModule, 'ModuleList/features/catalog-management.json'],
        ];

        foreach ($modules as [$module, $configFile]) {
            $manifest = $module->getManifest();
            Module::query()->updateOrCreate(
                ['slug' => $module->getSlug()],
                [
                    'name' => $module->getName(),
                    'version' => $module->getVersion(),
                    'type' => $module->getType()->value,
                    'billing_model' => $module->getBillingModel()->value,
                    'category' => $manifest['category'] ?? 'management',
                    'description' => $module->getDescription(),
                    'features' => $module->getFeatures(),
                    'permissions' => $module->getPermissions(),
                    'dependencies' => $module->getDependencies(),
                    'conflicts' => $module->getConflicts(),
                    'limits' => $module->getLimits(),
                    'class_name' => $module::class,
                    'config_file' => $configFile,
                    'display_order' => $manifest['display_order'] ?? 0,
                    'is_active' => true,
                    'is_system_module' => true,
                ]
            );
        }

        $access = $this->app->make(AccessController::class);
        $access->clearAccessCache($organizationId);
        foreach ([
            'workflow-management',
            'project-management',
            'contract-management',
            'catalog-management',
        ] as $moduleSlug) {
            self::assertTrue(
                $access->hasModuleAccess($organizationId, $moduleSlug),
                "Required foundation module {$moduleSlug} is not active for the test organization."
            );
        }
    }

    private function assertWorkflowPermissions(
        AdminApiTestContext $context,
        array $grantedPermissions,
        array $deniedPermissions = [],
    ): void {
        $authorization = $this->app->make(AuthorizationService::class);
        self::assertTrue($authorization->canAccessInterface($context->user, 'mobile'));

        foreach ($grantedPermissions as $permission) {
            self::assertTrue(
                $authorization->can($context->user, $permission, [
                    'organization_id' => $context->organization->id,
                ]),
                "Expected the organization role to grant {$permission}."
            );
        }

        foreach ($deniedPermissions as $permission) {
            self::assertFalse(
                $authorization->can($context->user, $permission, [
                    'organization_id' => $context->organization->id,
                ]),
                "Expected the organization role not to grant {$permission}."
            );
        }
    }

    private function assertMobileStatus(\Illuminate\Testing\TestResponse $response, int $expectedStatus): void
    {
        self::assertSame(
            $expectedStatus,
            $response->status(),
            "Unexpected mobile HTTP response: status={$response->status()}, body={$response->getContent()}"
        );
    }

    private function assertMobileJsonPath(
        \Illuminate\Testing\TestResponse $response,
        string $path,
        mixed $expected
    ): void {
        self::assertSame(
            $expected,
            data_get($response->json(), $path),
            "Unexpected mobile response data at {$path}; body={$response->getContent()}"
        );
    }
}
