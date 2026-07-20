<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowDecision;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Exceptions\ImmutableDataException;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowActorResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowAssignmentValidator;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\LegalArchive\Workflow\LegalWorkflowPermissions;
use App\Services\LegalArchive\Workflow\LegalWorkflowRecoveryService;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegalDocumentWorkflowTest extends TestCase
{
    private Capsule $database;

    private RecordingWorkflowAudit $audit;

    private LegalWorkflowTemplateService $templates;

    private LegalDocumentWorkflowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->createSchema();

        $this->audit = new RecordingWorkflowAudit;
        $integrity = new ImmutableAuditIntegrityService;
        $this->templates = new LegalWorkflowTemplateService($integrity, $this->database->getConnection());
        $authorization = new LegalWorkflowAuthorization;
        $actors = new LegalWorkflowActorResolver(
            roleLookup: static fn (User $actor, string $role): bool => in_array($role, $actor->workflowRoles, true),
        );
        $this->service = new LegalDocumentWorkflowService(
            $this->templates,
            $authorization,
            $actors,
            new LegalWorkflowAssignmentValidator(static fn (): bool => true),
            $this->audit,
            $integrity,
            $this->database->getConnection(),
        );
    }

    public function test_submit_locks_exact_ready_version_uses_snapshot_and_activates_first_parallel_group(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer', 'finance_reviewer']);
        $this->createTemplate($actor);

        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('submit-1'));

        self::assertSame('in_progress', $instance->status);
        self::assertSame((int) $version->id, (int) $instance->document_version_id);
        self::assertSame($version->content_hash, $instance->document_content_hash);
        self::assertSame(64, strlen((string) $instance->snapshot_hash));
        self::assertSame(['active', 'active', 'pending'], $instance->steps()->orderBy('sequence')->orderBy('step_key')->pluck('status')->all());
        $document->refresh();
        self::assertSame('pending', $document->approval_status);
        self::assertSame('under_review', $document->lifecycle_status);
        self::assertSame(['workflow_submitted'], $this->audit->events);
    }

    public function test_submit_is_idempotent_and_payload_mismatch_or_duplicate_active_workflow_conflicts(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $first = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('submit-1'));
        $replay = $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('submit-1'));
        self::assertSame($first->id, $replay->id);

        try {
            $this->service->submit(
                $document->refresh(),
                (int) $version->id,
                $actor,
                new WorkflowOverride('submit-1', stepOverrides: ['finance_a' => ['enabled' => false]]),
            );
            self::fail('Повторный ключ принял другой payload');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_idempotency_conflict', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_active_instance_exists');
        $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('submit-2'));
    }

    public function test_submit_replay_uses_stable_client_command_before_mutable_document_and_template_gates(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $override = new WorkflowOverride('stable-replay', expectedDocumentLockVersion: 0);
        $first = $this->service->submit($document, (int) $version->id, $actor, $override);
        $this->createTemplate($actor);

        $replay = $this->service->submit($document, (int) $version->id, $actor, $override);
        self::assertSame($first->id, $replay->id);
        self::assertSame(1, LegalWorkflowInstance::query()->count());
        self::assertSame(64, strlen((string) $first->client_request_hash));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_idempotency_conflict');
        $this->service->submit(
            $document,
            (int) $version->id,
            $actor,
            new WorkflowOverride('stable-replay', stepOverrides: ['finance_a' => ['enabled' => false]], expectedDocumentLockVersion: 0),
        );
    }

    public function test_parallel_approval_is_deterministic_and_duplicate_decision_is_idempotent(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer', 'finance_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('submit-1'));
        $steps = $instance->steps()->where('status', 'active')->orderBy('step_key')->get();

        $input = new WorkflowDecisionInput('approve', 'decision-1', 1, 0);
        $afterFirst = $this->service->decide($steps[0], $actor, $input);
        $replay = $this->service->decide($steps[0]->refresh(), $actor, $input);
        self::assertSame($afterFirst->id, $replay->id);
        self::assertSame(1, LegalWorkflowDecision::query()->where('idempotency_key', 'decision-1')->count());
        self::assertSame('pending', $instance->steps()->where('step_key', 'finance_a')->value('status'));

        $instance = $this->service->decide(
            $steps[1]->refresh(),
            $actor,
            new WorkflowDecisionInput('approve', 'decision-2', 2, 0),
        );
        self::assertSame('active', $instance->steps()->where('step_key', 'finance_a')->value('status'));
    }

    public function test_actor_authorization_version_hash_comments_return_and_reassign_are_enforced(): void
    {
        [$document, $version] = $this->dossier();
        $submitter = $this->actor(8, ['legal_reviewer', 'finance_reviewer']);
        $outsider = $this->actor(9, []);
        $this->createTemplate($submitter);
        $instance = $this->service->submit($document, (int) $version->id, $submitter, WorkflowOverride::none('submit-1'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();

        try {
            $this->service->decide($step, $outsider, new WorkflowDecisionInput('approve', 'd-out', 1, 0));
            self::fail('Неподходящий исполнитель согласовал шаг');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_actor_not_resolved', $exception->getMessage());
        }

        foreach (['reject', 'return', 'reassign'] as $action) {
            try {
                $this->service->decide($step->refresh(), $submitter, new WorkflowDecisionInput($action, "d-{$action}", 1, 0));
                self::fail("{$action} без причины принят");
            } catch (DomainException $exception) {
                self::assertSame(
                    $action === 'reassign' ? 'legal_workflow_reason_required' : 'legal_workflow_comment_required',
                    $exception->getMessage(),
                );
            }
        }

        $instance = $this->service->decide($step->refresh(), $submitter, new WorkflowDecisionInput(
            'reassign',
            'd-reassign-ok',
            1,
            0,
            reason: 'Замещение на период отпуска',
            reassignActorType: 'user',
            reassignActorReference: '9',
            dueAt: '2099-08-01T09:00:00+03:00',
        ));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        self::assertSame('user', $step->actor_type);
        self::assertSame('9', $step->actor_reference);

        $instance = $this->service->decide($step, $outsider, new WorkflowDecisionInput(
            'return',
            'd-return',
            2,
            1,
            comment: 'Исправьте реквизиты стороны',
        ));
        self::assertSame('returned', $instance->status);
        self::assertSame('revision_required', $document->refresh()->approval_status);
        self::assertSame('revision_required', $document->lifecycle_status);
        self::assertSame($version->content_hash, LegalWorkflowDecision::query()->latest('id')->value('document_content_hash'));
    }

    public function test_reassign_permission_can_rescue_a_step_without_current_actor_assignment(): void
    {
        [$document, $version] = $this->dossier();
        $submitter = $this->actor(8, ['legal_reviewer']);
        $manager = $this->actor(10, []);
        $this->createTemplate($submitter);
        $instance = $this->service->submit($document, (int) $version->id, $submitter, WorkflowOverride::none('rescue-submit'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();

        $updated = $this->service->decide($step, $manager, new WorkflowDecisionInput(
            'reassign',
            'rescue-reassign',
            1,
            0,
            reason: 'Замена недоступного исполнителя',
            reassignActorType: 'user',
            reassignActorReference: '9',
            dueAt: '2099-08-01T09:00:00+03:00',
        ));

        $reassigned = $updated->steps()->where('step_key', 'legal_review')->firstOrFail();
        self::assertSame('user', $reassigned->actor_type);
        self::assertSame('9', $reassigned->actor_reference);
        self::assertSame(1, $reassigned->assignment_revision);
        $decision = LegalWorkflowDecision::query()->where('action', 'reassign')->firstOrFail();
        self::assertSame('role', $decision->from_actor_type);
        self::assertSame('user', $decision->to_actor_type);
        self::assertSame((int) $decision->id, (int) $reassigned->last_reassign_decision_id);
        try {
            $reassigned->forceFill(['actor_reference' => '77'])->save();
            self::fail('Исполнитель изменён без неизменяемого решения');
        } catch (ImmutableDataException) {
        }
        $recovery = new LegalWorkflowRecoveryService(
            new ImmutableAuditIntegrityService,
            $this->audit,
            $this->database->getConnection(),
            $this->templates,
        );
        $recovery->markRequired($updated, 'Проверка цепочки переназначений');
        self::assertNull($recovery->reconcile(15, (int) $updated->id)->reconciliation_required_at);
    }

    public function test_stale_actions_and_audit_failure_roll_back_atomically(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('submit-1'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();

        try {
            $this->service->decide($step, $actor, new WorkflowDecisionInput('approve', 'stale', 99, 0));
            self::fail('Устаревшее действие принято');
        } catch (LegalArchiveLockConflict $exception) {
            self::assertSame('legal_workflow_instance', $exception->aggregateKind);
            self::assertSame((string) $instance->id, $exception->aggregateId);
            self::assertSame((int) $document->id, $exception->documentId);
            self::assertSame((int) $instance->lock_version, $exception->currentLockVersion);
        }

        try {
            $this->service->decide($step, $actor, new WorkflowDecisionInput(
                'approve', 'stale-step', (int) $instance->lock_version, 99,
            ));
            self::fail('Устаревшая версия шага принята');
        } catch (LegalArchiveLockConflict $exception) {
            self::assertSame('legal_workflow_step', $exception->aggregateKind);
            self::assertSame((string) $step->id, $exception->aggregateId);
            self::assertSame((int) $document->id, $exception->documentId);
            self::assertSame((int) $step->lock_version, $exception->currentLockVersion);
        }

        $this->audit->fail = true;
        try {
            $this->service->decide($step->refresh(), $actor, new WorkflowDecisionInput('approve', 'rollback', 1, 0));
            self::fail('Ошибка аудита не прервала действие');
        } catch (RuntimeException) {
        }
        self::assertSame('active', $step->refresh()->status);
        self::assertSame(0, LegalWorkflowDecision::query()->count());
    }

    public function test_reassign_decision_projection_and_audit_roll_back_as_one_transaction(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('reassign-rollback-submit'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        $this->audit->fail = true;
        try {
            $this->service->decide($step, $actor, new WorkflowDecisionInput(
                'reassign', 'reassign-rollback', 1, 0, reason: 'Проверка атомарности',
                reassignActorType: 'user', reassignActorReference: '9', dueAt: '2099-01-01T00:00:00+00:00',
            ));
            self::fail('Ошибка аудита не откатила переназначение');
        } catch (RuntimeException) {
        }
        $step->refresh();
        self::assertSame('role', $step->actor_type);
        self::assertSame('legal_reviewer', $step->actor_reference);
        self::assertSame(0, $step->assignment_revision);
        self::assertNull($step->last_reassign_decision_id);
        self::assertSame(0, LegalWorkflowDecision::query()->count());
    }

    public function test_action_resolver_returns_typed_labels_permissions_and_blockers(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $resolver = new LegalWorkflowActionResolver(new LegalWorkflowAuthorization, new LegalWorkflowActorResolver(
            roleLookup: static fn (User $user, string $role): bool => in_array($role, $user->workflowRoles, true),
        ));

        $before = $resolver->for($actor, $document);
        self::assertSame('not_started', $before->status);
        self::assertTrue($before->action('submit')->enabled);
        self::assertSame('legal_archive.workflow.submit', $before->action('submit')->permission);

        $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('submit-1'));
        $after = $resolver->for($actor, $document->refresh());
        self::assertSame('in_progress', $after->status);
        $targetStepId = $after->currentSteps[0]->id;
        self::assertTrue($after->action('approve', $targetStepId)->enabled);
        self::assertNotSame('', $after->action('approve', $targetStepId)->label);
        self::assertSame('legal_archive.workflow.approve', $after->action('approve', $targetStepId)->permission);
        $workflowSummary = $after->toArray()['workflow_summary'];
        self::assertArrayHasKey('available_action_details', $workflowSummary);
        $reject = collect($workflowSummary['available_action_details'])->firstWhere('action', 'reject');
        $reassign = collect($workflowSummary['available_action_details'])->firstWhere('action', 'reassign');
        self::assertSame(['comment'], $reject['input_schema']['required']);
        self::assertSame(['reason', 'target_actor_type', 'target_actor_id'], $reassign['input_schema']['required']);
        self::assertFalse($reassign['input_schema']['properties']['due_at']['required']);
    }

    public function test_bulk_action_summaries_are_safe_and_permission_checks_are_bounded(): void
    {
        $actor = $this->actor(8, []);
        $actor->grantedPermissions = ['legal_archive.view'];
        $documents = collect(range(1, 100))->map(static function (int $id): LegalArchiveDocument {
            $document = new LegalArchiveDocument;
            $document->forceFill([
                'id' => $id,
                'organization_id' => 15,
                'status' => 'draft',
                'approval_status' => 'not_submitted',
                'lock_version' => 0,
                'open_blocking_comments_count' => 0,
            ]);
            $document->setRelation('currentVersion', null);
            $document->setRelation('latestWorkflowInstance', null);

            return $document;
        });
        $external = new LegalArchiveDocument;
        $external->forceFill([
            'id' => 101,
            'organization_id' => 16,
            'status' => 'draft',
            'approval_status' => 'not_submitted',
            'lock_version' => 0,
            'open_blocking_comments_count' => 0,
        ]);
        $external->setRelation('currentVersion', null);
        $external->setRelation('latestWorkflowInstance', null);
        $documents->push($external);
        $resolver = new LegalWorkflowActionResolver(
            new LegalWorkflowAuthorization,
            new LegalWorkflowActorResolver(roleLookup: static fn (): bool => true),
        );

        $this->database->getConnection()->flushQueryLog();
        $this->database->getConnection()->enableQueryLog();
        $summaries = $resolver->forMany($actor, $documents);
        $queryCount = count($this->database->getConnection()->getQueryLog());
        $this->database->getConnection()->disableQueryLog();

        self::assertCount(101, $summaries);
        self::assertSame(7, $actor->permissionChecks);
        self::assertSame(0, $queryCount);
        self::assertSame('not_available', $summaries[1]->status);
        self::assertSame(['workflow_permission_denied'], $summaries[1]->problemFlags);
        self::assertSame('legal_archive.workflow.view', $summaries[1]->availableActionDetails[0]->permission);
        self::assertFalse($summaries[1]->availableActionDetails[0]->enabled);
        self::assertSame('not_available', $summaries[101]->status);
        self::assertSame(['workflow_permission_denied'], $summaries[101]->problemFlags);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_access_denied');
        $resolver->for($actor, $documents->first());
    }

    public function test_real_user_bulk_workflow_resolution_preloads_one_hundred_project_contexts_and_role_assignments(): void
    {
        $actor = new User;
        $actor->forceFill(['id' => 8, 'current_organization_id' => 15, 'name' => 'Reviewer']);
        Container::getInstance()->instance(PermissionResolver::class, new BatchWorkflowPermissionResolver);
        $system = AuthorizationContext::query()->create(['type' => AuthorizationContext::TYPE_SYSTEM]);
        $organization = AuthorizationContext::query()->create([
            'type' => AuthorizationContext::TYPE_ORGANIZATION,
            'resource_id' => 15,
            'parent_context_id' => $system->id,
        ]);
        $documents = collect();
        foreach (range(1, 100) as $id) {
            $project = AuthorizationContext::query()->create([
                'type' => AuthorizationContext::TYPE_PROJECT,
                'resource_id' => $id,
                'parent_context_id' => $organization->id,
            ]);
            UserRoleAssignment::query()->create([
                'user_id' => 8,
                'role_slug' => 'legal_reviewer',
                'role_type' => UserRoleAssignment::TYPE_SYSTEM,
                'context_id' => $project->id,
                'is_active' => true,
            ]);
            $step = new \App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
            $step->forceFill([
                'id' => $id,
                'organization_id' => 15,
                'actor_type' => 'role',
                'actor_reference' => 'legal_reviewer',
                'status' => 'active',
            ]);
            $instance = new LegalWorkflowInstance;
            $instance->setRelation('steps', collect([$step]));
            $document = new LegalArchiveDocument;
            $document->forceFill([
                'id' => $id,
                'organization_id' => 15,
                'primary_project_id' => $id,
                'status' => 'draft',
                'approval_status' => 'not_submitted',
                'open_blocking_comments_count' => 0,
            ]);
            $document->setRelation('latestWorkflowInstance', $instance);
            $document->setRelation('currentVersion', null);
            $documents->push($document);
        }

        $this->database->getConnection()->flushQueryLog();
        $this->database->getConnection()->enableQueryLog();
        $permissions = (new LegalWorkflowAuthorization)->forMany($actor, $documents, [LegalWorkflowPermissions::VIEW]);
        $assignments = (new LegalWorkflowActorResolver)->forMany($actor, $documents);
        $queryCount = count($this->database->getConnection()->getQueryLog());
        $this->database->getConnection()->disableQueryLog();

        self::assertSame(9, $queryCount);
        self::assertCount(100, $permissions);
        self::assertCount(100, $assignments);
        self::assertTrue($permissions[1][LegalWorkflowPermissions::VIEW]);
        self::assertTrue($permissions[100][LegalWorkflowPermissions::VIEW]);
        self::assertTrue($assignments[1]);
        self::assertTrue($assignments[100]);
    }

    public function test_bulk_workflow_authorization_loads_only_page_contexts_and_preloads_project_count_conditions(): void
    {
        $actor = new User;
        $actor->forceFill(['id' => 8, 'current_organization_id' => 15, 'name' => 'Reviewer']);
        $this->database->getConnection()->table('users')->insert([
            'id' => 8,
            'name' => 'Reviewer',
            'current_organization_id' => 15,
        ]);
        Container::getInstance()->instance(PermissionResolver::class, new BatchWorkflowPermissionResolver);
        $system = AuthorizationContext::query()->create(['type' => AuthorizationContext::TYPE_SYSTEM]);
        $organization = AuthorizationContext::query()->create([
            'type' => AuthorizationContext::TYPE_ORGANIZATION,
            'resource_id' => 15,
            'parent_context_id' => $system->id,
        ]);
        $documents = collect();
        foreach (range(1, 100) as $id) {
            $projectContext = AuthorizationContext::query()->create([
                'type' => AuthorizationContext::TYPE_PROJECT,
                'resource_id' => $id,
                'parent_context_id' => $organization->id,
            ]);
            $this->database->getConnection()->table('projects')->insert([
                'id' => $id,
                'status' => 'active',
            ]);
            if ($id < 100) {
                $assignment = UserRoleAssignment::query()->create([
                    'user_id' => 8,
                    'role_slug' => 'legal_reviewer',
                    'role_type' => UserRoleAssignment::TYPE_SYSTEM,
                    'context_id' => $projectContext->id,
                    'is_active' => true,
                ]);
                $assignment->conditions()->create([
                    'condition_type' => \App\Domain\Authorization\Enums\ConditionType::PROJECT_COUNT,
                    'condition_data' => ['max_projects' => 3],
                    'is_active' => true,
                ]);
            }
            $document = new LegalArchiveDocument;
            $document->forceFill([
                'id' => $id,
                'organization_id' => 15,
                'primary_project_id' => $id,
                'status' => 'draft',
                'approval_status' => 'not_submitted',
                'open_blocking_comments_count' => 0,
            ]);
            $documents->push($document);
        }
        foreach (range(1001, 3000) as $id) {
            AuthorizationContext::query()->create([
                'type' => AuthorizationContext::TYPE_PROJECT,
                'resource_id' => $id,
                'parent_context_id' => $organization->id,
            ]);
        }
        $this->database->getConnection()->table('project_user')->insert([
            ['project_id' => 1, 'user_id' => 8, 'is_active' => true],
            ['project_id' => 2, 'user_id' => 8, 'is_active' => true],
        ]);

        $connection = $this->database->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $permissions = (new LegalWorkflowAuthorization)->forMany($actor, $documents, [LegalWorkflowPermissions::VIEW]);
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        self::assertSame(6, count($queries));
        self::assertTrue($permissions[1][LegalWorkflowPermissions::VIEW]);
        self::assertTrue($permissions[99][LegalWorkflowPermissions::VIEW]);
        self::assertFalse($permissions[100][LegalWorkflowPermissions::VIEW]);
        $projectContextQueries = array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'authorization_contexts')
            && str_contains($query['query'], 'parent_context_id')
            && count($query['bindings']) === 102));
        self::assertCount(1, $projectContextQueries);
        self::assertSame(102, count($projectContextQueries[0]['bindings']));
    }

    public function test_parallel_action_contract_is_step_specific_and_overdue_does_not_block_sibling(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('parallel-contract'));
        $steps = $instance->steps()->where('status', 'active')->orderBy('step_key')->get();
        $this->database->getConnection()->table('legal_workflow_steps')->where('id', $steps[0]->id)->update([
            'due_at' => now()->subMinute(),
        ]);
        $resolver = new LegalWorkflowActionResolver(new LegalWorkflowAuthorization, new LegalWorkflowActorResolver(
            roleLookup: static fn (User $user, string $role): bool => in_array($role, $user->workflowRoles, true),
        ));

        $summary = $resolver->for($actor, $document->refresh());
        self::assertCount(2, $summary->currentSteps);
        self::assertFalse($summary->action('approve', (int) $steps[0]->id)->enabled);
        self::assertTrue($summary->action('approve', (int) $steps[1]->id)->enabled);
        self::assertSame((int) $instance->lock_version, $summary->action('approve', (int) $steps[1]->id)->expectedInstanceLockVersion);
        self::assertSame((int) $steps[1]->lock_version, $summary->action('approve', (int) $steps[1]->id)->expectedStepLockVersion);
        $payload = $summary->toArray();
        self::assertArrayHasKey('current_steps', $payload['workflow_summary']);
        self::assertArrayHasKey('available_action_details', $payload['workflow_summary']);
        self::assertArrayNotHasKey('available_action_details', $payload);
        self::assertCount(count(array_unique(array_column($payload['workflow_summary']['available_action_details'], 'key'))), $payload['workflow_summary']['available_action_details']);
    }

    public function test_exact_action_permission_mapping_has_no_combined_decide_permission(): void
    {
        self::assertSame([
            'submit' => 'legal_archive.workflow.submit',
            'approve' => 'legal_archive.workflow.approve',
            'reject' => 'legal_archive.workflow.reject',
            'return' => 'legal_archive.workflow.return',
            'reassign' => 'legal_archive.workflow.reassign',
            'cancel' => 'legal_archive.workflow.cancel',
        ], array_combine(
            ['submit', 'approve', 'reject', 'return', 'reassign', 'cancel'],
            array_map(LegalWorkflowPermissions::forAction(...), ['submit', 'approve', 'reject', 'return', 'reassign', 'cancel']),
        ));
    }

    public function test_action_permission_denial_is_exact_and_atomic(): void
    {
        [$document, $version] = $this->dossier();
        $submitter = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($submitter);
        $instance = $this->service->submit($document, (int) $version->id, $submitter, WorkflowOverride::none('exact-rbac'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        $restricted = $this->actor(8, ['legal_reviewer']);
        $restricted->grantedPermissions = [LegalWorkflowPermissions::APPROVE];

        try {
            $this->service->decide($step, $restricted, new WorkflowDecisionInput(
                'reject', 'exact-rbac-reject', 1, 0, reason: 'Нет полномочия отклонять',
            ));
            self::fail('Объединённое право позволило отклонить документ');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_access_denied', $exception->getMessage());
        }
        self::assertSame('active', $step->refresh()->status);
        self::assertSame(0, LegalWorkflowDecision::query()->count());
        $approved = $this->service->decide($step, $restricted, new WorkflowDecisionInput('approve', 'exact-rbac-approve', 1, 0));
        self::assertSame('approved', $approved->steps()->whereKey($step->id)->value('status'));
    }

    public function test_template_and_snapshot_are_tenant_scoped_and_immutable(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $foreign = clone $actor;
        $foreign->forceFill(['current_organization_id' => 16]);
        $foreignTemplate = $this->templates->createVersion(16, 'contract', 'Чужой маршрут', [
            ['key' => 'legal_review', 'label' => 'Юридическая проверка', 'sequence' => 10, 'parallel_group' => 'legal', 'required' => true, 'policy_key' => 'legal_review', 'actor_type' => 'user', 'actor_reference' => '8'],
        ], $foreign);

        try {
            $this->service->submit($document, (int) $version->id, $actor, new WorkflowOverride('foreign', (int) $foreignTemplate->id));
            self::fail('Чужой шаблон использован');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_template_not_found', $exception->getMessage());
        }

        $instance = $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('own'));
        $this->expectException(\App\Exceptions\ImmutableDataException::class);
        $instance->forceFill(['snapshot_hash' => str_repeat('b', 64)])->save();
    }

    public function test_template_definition_drift_is_rejected_on_resolve(): void
    {
        [$document] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $template = $this->templates->resolveForDocument($document);
        self::assertSame((int) $template->id, (int) $this->templates->snapshot($template, WorkflowOverride::none('identity'))->payload['template_identity']['template_id']);
        $this->database->getConnection()->table('legal_workflow_template_steps')
            ->where('template_id', $template->id)
            ->where('step_key', 'finance_a')
            ->update(['label' => 'Подменённый этап']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_template_integrity_failed');
        $this->templates->resolveForDocument($document);
    }

    public function test_assignment_validator_enforces_organization_and_project_scope(): void
    {
        [$document] = $this->dossier();
        $validator = new LegalWorkflowAssignmentValidator;
        $this->database->getConnection()->table('organization_user')->insert([
            'organization_id' => 15,
            'user_id' => 44,
            'is_active' => true,
            'project_access_mode' => 'assigned_projects',
        ]);
        $this->database->getConnection()->table('project_user')->insert([
            'project_id' => 8,
            'user_id' => 44,
            'is_active' => true,
        ]);
        self::assertFalse($validator->exists('user', '44', $document));
        $this->database->getConnection()->table('project_user')->insert([
            'project_id' => 7,
            'user_id' => 44,
            'is_active' => true,
        ]);
        self::assertTrue($validator->exists('user', '44', $document));
        self::assertFalse($validator->exists('party', 'foreign-party', $document));
    }

    public function test_version_hash_mismatch_and_decision_payload_mismatch_are_rejected(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $this->database->getConnection()->table('legal_archive_document_versions')->where('id', $version->id)->update([
            'content_hash' => 'INVALID',
        ]);
        try {
            $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('bad-hash'));
            self::fail('Невалидный hash принят');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_version_not_ready', $exception->getMessage());
        }
        $this->database->getConnection()->table('legal_archive_document_versions')->where('id', $version->id)->update([
            'content_hash' => str_repeat('a', 64),
        ]);
        $instance = $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('good'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        $this->service->decide($step, $actor, new WorkflowDecisionInput('approve', 'same', 1, 0));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_idempotency_conflict');
        $this->service->decide($step->refresh(), $actor, new WorkflowDecisionInput('approve', 'same', 1, 0, comment: 'Другой запрос'));
    }

    public function test_decision_and_recovery_reject_a_version_that_is_no_longer_current_or_ready(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('version-state-submit'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        $this->database->getConnection()->table('legal_archive_document_versions')->where('id', $version->id)->update([
            'is_current' => false,
            'processing_status' => 'failed',
        ]);

        try {
            $this->service->decide($step, $actor, new WorkflowDecisionInput('approve', 'version-state-decision', 1, 0));
            self::fail('Решение принято по неактуальной версии');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_version_changed', $exception->getMessage());
        }

        $recovery = new LegalWorkflowRecoveryService(new ImmutableAuditIntegrityService, $this->audit, $this->database->getConnection());
        $recovery->markRequired($instance, 'Проверка состояния версии');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_reconciliation_integrity_failed');
        $recovery->reconcile(15, (int) $instance->id);
    }

    public function test_cancel_expire_and_recovery_have_terminal_and_integrity_semantics(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('cancel-submit'));
        try {
            $this->service->cancel($instance, $actor, new WorkflowDecisionInput(
                'cancel', 'cancel-stale', 99, 0, reason: 'Проверка конфликта',
            ));
            self::fail('Устаревшая отмена принята');
        } catch (LegalArchiveLockConflict $exception) {
            self::assertSame('legal_workflow_instance', $exception->aggregateKind);
            self::assertSame((string) $instance->id, $exception->aggregateId);
            self::assertSame((int) $document->id, $exception->documentId);
            self::assertSame((int) $instance->lock_version, $exception->currentLockVersion);
        }
        $cancelled = $this->service->cancel($instance, $actor, new WorkflowDecisionInput(
            'cancel', 'cancel-1', 1, 0, reason: 'Документ отозван инициатором',
        ));
        self::assertSame('cancelled', $cancelled->status);
        self::assertSame(['cancelled'], $cancelled->steps->pluck('status')->unique()->values()->all());
        $document->refresh();
        self::assertSame('cancelled', $document->approval_status);
        self::assertSame('terminated', $document->lifecycle_status);

        $instance = $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('expire-submit'));
        $instance->forceFill(['due_at' => now()->subMinute()])->save();
        self::assertSame(1, $this->service->expireDue(15, now()));
        self::assertSame('expired', $instance->refresh()->status);
        $document->refresh();
        self::assertSame('expired', $document->approval_status);
        self::assertSame('expired', $document->lifecycle_status);

        $instance = $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('recover-submit'));
        $recovery = new LegalWorkflowRecoveryService(
            new ImmutableAuditIntegrityService,
            $this->audit,
            $this->database->getConnection(),
        );
        $recovery->markRequired($instance, 'Проверка очереди после сбоя');
        self::assertSame([$instance->id], $recovery->candidates(15)->pluck('id')->all());
        self::assertNull($recovery->reconcile(15, (int) $instance->id)->reconciliation_required_at);
    }

    public function test_all_parallel_and_sequential_approvals_complete_the_exact_workflow(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer', 'finance_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('complete-submit'));
        $active = $instance->steps()->where('status', 'active')->orderBy('step_key')->get();
        $instance = $this->service->decide($active[0], $actor, new WorkflowDecisionInput('approve', 'complete-1', 1, 0));
        $instance = $this->service->decide($active[1], $actor, new WorkflowDecisionInput('approve', 'complete-2', 2, 0));
        $finance = $instance->steps()->where('step_key', 'finance_a')->firstOrFail();
        self::assertSame(1, $finance->lock_version);
        $instance = $this->service->decide($finance, $actor, new WorkflowDecisionInput('approve', 'complete-3', 3, 1));

        self::assertSame('approved', $instance->status);
        $document->refresh();
        self::assertSame('approved', $document->approval_status);
        self::assertSame('approved', $document->lifecycle_status);
        self::assertSame(3, LegalWorkflowDecision::query()->where('instance_id', $instance->id)->count());
        self::assertSame([$version->content_hash], LegalWorkflowDecision::query()->where('instance_id', $instance->id)->pluck('document_content_hash')->unique()->values()->all());
    }

    public function test_return_requires_a_new_immutable_version_before_resubmission(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('return-submit'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        $this->service->decide($step, $actor, new WorkflowDecisionInput(
            'return', 'return-1', 1, 0, comment: 'Исправьте условия поставки',
        ));

        try {
            $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('return-repeat'));
            self::fail('Возвращённая версия повторно отправлена без новой версии');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_new_version_required', $exception->getMessage());
        }
        $resolver = new LegalWorkflowActionResolver(new LegalWorkflowAuthorization, new LegalWorkflowActorResolver(
            roleLookup: static fn (): bool => true,
        ));
        self::assertFalse($resolver->for($actor, $document->refresh())->action('submit')->enabled);

        $this->database->getConnection()->table('legal_archive_document_versions')->where('id', $version->id)->update(['is_current' => false]);
        $newVersion = LegalArchiveDocumentVersion::query()->create([
            'document_id' => $document->id,
            'organization_id' => 15,
            'version_number' => 2,
            'is_current' => true,
            'status' => 'draft',
            'processing_status' => 'ready',
            'content_hash' => str_repeat('b', 64),
        ]);
        $document->forceFill(['current_primary_version_id' => $newVersion->id])->save();
        self::assertTrue($resolver->for($actor, $document->refresh())->action('submit')->enabled);
    }

    public function test_unresolvable_assignment_is_rejected_before_instance_creation(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $service = new LegalDocumentWorkflowService(
            $this->templates,
            new LegalWorkflowAuthorization,
            new LegalWorkflowActorResolver(
                roleLookup: static fn (): bool => true,
            ),
            new LegalWorkflowAssignmentValidator(static fn (): bool => false),
            $this->audit,
            new ImmutableAuditIntegrityService,
            $this->database->getConnection(),
        );

        try {
            $service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('bad-assignment'));
            self::fail('Недоступный исполнитель принят');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_actor_target_not_available', $exception->getMessage());
        }
        self::assertSame(0, LegalWorkflowInstance::query()->count());
    }

    public function test_failed_reconciliation_keeps_observable_tenant_scoped_evidence(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('recovery-fail'));
        $recovery = new LegalWorkflowRecoveryService(new ImmutableAuditIntegrityService, $this->audit, $this->database->getConnection());
        $recovery->markRequired($instance, 'Контроль целостности');
        $this->database->getConnection()->table('legal_workflow_instances')->where('id', $instance->id)->update([
            'snapshot_hash' => str_repeat('b', 64),
        ]);

        try {
            $recovery->reconcile(15, (int) $instance->id);
            self::fail('Повреждённый снимок восстановлен');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_reconciliation_integrity_failed', $exception->getMessage());
        }
        $failed = $instance->refresh();
        self::assertNotNull($failed->reconciliation_required_at);
        self::assertSame('Контроль целостности', $failed->reconciliation_reason);
        self::assertSame(1, $failed->reconciliation_attempts);
        self::assertSame('legal_workflow_reconciliation_integrity_failed', $failed->reconciliation_last_error);
    }

    public function test_reject_return_reassign_and_cancel_require_the_exact_input_field(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('exact-input-submit'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();

        foreach (['reject', 'return'] as $action) {
            try {
                $this->service->decide($step->refresh(), $actor, new WorkflowDecisionInput(
                    $action,
                    "exact-input-{$action}",
                    1,
                    0,
                    reason: 'Причина не заменяет обязательный комментарий',
                ));
                self::fail("{$action} accepted without comment");
            } catch (DomainException $exception) {
                self::assertSame('legal_workflow_comment_required', $exception->getMessage());
            }
        }

        try {
            $this->service->decide($step->refresh(), $actor, new WorkflowDecisionInput(
                'reassign',
                'exact-input-reassign',
                1,
                0,
                comment: 'Комментарий не заменяет обязательную причину',
                reassignActorType: 'user',
                reassignActorReference: '9',
            ));
            self::fail('Reassign accepted without reason.');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_reason_required', $exception->getMessage());
        }

        try {
            $this->service->cancel($instance->refresh(), $actor, new WorkflowDecisionInput(
                'cancel',
                'exact-input-cancel',
                1,
                0,
                comment: 'Комментарий не заменяет обязательную причину',
            ));
            self::fail('Cancel accepted without reason.');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_reason_required', $exception->getMessage());
        }
    }

    public function test_instance_due_at_is_minimum_active_deadline_after_partial_approval_and_reassignment(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('due-submit'));
        $steps = $instance->steps()->where('status', 'active')->orderBy('step_key')->get();
        $earlier = now()->addHours(2)->startOfSecond();
        $later = now()->addHours(5)->startOfSecond();
        $this->database->getConnection()->table('legal_workflow_steps')->where('id', $steps[0]->id)->update(['due_at' => $earlier]);
        $this->database->getConnection()->table('legal_workflow_steps')->where('id', $steps[1]->id)->update(['due_at' => $later]);
        $this->database->getConnection()->table('legal_workflow_instances')->where('id', $instance->id)->update(['due_at' => $earlier]);

        $instance = $this->service->decide($steps[0]->refresh(), $actor, new WorkflowDecisionInput('approve', 'due-approve', 1, 0));
        self::assertSame($later->timestamp, $instance->due_at?->timestamp);

        $reassignedDue = now()->addHour()->startOfSecond();
        $instance = $this->service->decide($steps[1]->refresh(), $actor, new WorkflowDecisionInput(
            'reassign',
            'due-reassign',
            2,
            0,
            reason: 'Срок изменён ответственным',
            reassignActorType: 'user',
            reassignActorReference: '9',
            dueAt: $reassignedDue->toAtomString(),
        ));
        self::assertSame($reassignedDue->timestamp, $instance->due_at?->timestamp);
    }

    public function test_reassign_without_due_at_preserves_null_deadlines_everywhere(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->templates->createVersion(15, 'contract', 'Бессрочное согласование', [
            [
                'key' => 'legal_review',
                'label' => 'Юридическая проверка',
                'sequence' => 10,
                'parallel_group' => 'legal',
                'required' => true,
                'policy_key' => 'legal_review',
                'actor_type' => 'role',
                'actor_reference' => 'legal_reviewer',
            ],
        ], $actor);
        $instance = $this->service->submit(
            $document,
            (int) $version->id,
            $actor,
            WorkflowOverride::none('null-due-submit'),
        );
        $step = $instance->steps()->where('status', 'active')->firstOrFail();
        self::assertNull($step->due_in_hours);
        self::assertNull($step->deadline_at);
        self::assertNull($step->due_at);
        self::assertNull($instance->due_at);

        $updated = $this->service->decide($step->refresh(), $actor, new WorkflowDecisionInput(
            'reassign',
            'null-due-reassign',
            1,
            0,
            reason: 'Исполнитель изменён без срока',
            reassignActorType: 'user',
            reassignActorReference: '9',
        ));

        $decision = $updated->decisions()->where('idempotency_key', 'null-due-reassign')->firstOrFail();
        self::assertNull($decision->from_due_at);
        self::assertNull($decision->to_due_at);
        self::assertNull($decision->context['from_due_at'] ?? null);
        self::assertNull($decision->context['to_due_at'] ?? null);
        self::assertNull($updated->steps()->whereKey($step->id)->value('due_at'));
        self::assertNull($updated->due_at);
    }

    public function test_recovery_repairs_only_deterministic_projection_and_verifies_before_clearing_marker(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('projection-recovery'));
        $expectedDue = $instance->steps()->where('status', 'active')->min('due_at');
        $this->database->getConnection()->table('legal_workflow_instances')->where('id', $instance->id)->update([
            'due_at' => now()->addDays(30),
        ]);
        $this->database->getConnection()->table('legal_archive_documents')->where('id', $document->id)->update([
            'approval_status' => 'approved',
            'lifecycle_status' => 'approved',
        ]);
        $recovery = new LegalWorkflowRecoveryService(new ImmutableAuditIntegrityService, $this->audit, $this->database->getConnection(), $this->templates);
        $recovery->markRequired($instance, 'Восстановление производной проекции');

        $recovered = $recovery->reconcile(15, (int) $instance->id);

        self::assertSame((string) $expectedDue, (string) $recovered->due_at);
        $document->refresh();
        self::assertSame('pending', $document->approval_status);
        self::assertSame('under_review', $document->lifecycle_status);
        self::assertNull($recovered->reconciliation_required_at);
    }

    public function test_default_actor_and_action_resolution_never_create_authorization_contexts(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('read-only-auth-submit'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        Container::getInstance()->instance(AuthorizationService::class, new WorkflowAuthorizationServiceSpy);
        $resolver = new LegalWorkflowActorResolver;

        self::assertFalse($resolver->canAct($actor, $step, $document));
        self::assertSame(0, AuthorizationContext::query()->count());

        $actionResolver = new LegalWorkflowActionResolver(new LegalWorkflowAuthorization, $resolver);
        $actionResolver->for($actor, $document->refresh());
        self::assertSame(0, AuthorizationContext::query()->count());

        $system = AuthorizationContext::query()->create([
            'type' => AuthorizationContext::TYPE_SYSTEM,
            'resource_id' => null,
            'parent_context_id' => null,
        ]);
        $organization = AuthorizationContext::query()->create([
            'type' => AuthorizationContext::TYPE_ORGANIZATION,
            'resource_id' => 15,
            'parent_context_id' => $system->id,
        ]);
        $spy = new WorkflowAuthorizationServiceSpy;
        $spy->allowedContextIds = [(int) $organization->id];
        Container::getInstance()->instance(AuthorizationService::class, $spy);

        self::assertTrue((new LegalWorkflowActorResolver)->canAct($actor, $step, $document));
        self::assertSame(2, AuthorizationContext::query()->count());
    }

    public function test_open_blocking_comment_blocks_submit_and_approve_until_resolved(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $commentId = $this->database->getConnection()->table('legal_document_comments')->insertGetId([
            'organization_id' => 15,
            'document_id' => (int) $document->id,
            'document_version_id' => (int) $version->id,
            'author_user_id' => 8,
            'body' => 'Исправьте существенное замечание',
            'visibility' => 'internal',
            'is_blocking' => true,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resolver = new LegalWorkflowActionResolver(
            new LegalWorkflowAuthorization,
            new LegalWorkflowActorResolver(
                roleLookup: static fn (User $candidate, string $role): bool => in_array($role, $candidate->workflowRoles, true),
            ),
        );
        $submit = $resolver->for($actor, $document->refresh())->availableActionDetails[0];
        self::assertFalse($submit->enabled);
        self::assertContains('legal_archive.workflow.blockers.open_blocking_comments', $submit->blockers);

        try {
            $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('blocked-submit'));
            self::fail('Workflow submit accepted an open blocking comment.');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_open_blocking_comments', $exception->getMessage());
        }
        $this->database->getConnection()->table('legal_document_comments')->where('id', $commentId)->update([
            'status' => 'resolved',
            'resolved_by_user_id' => 8,
            'resolved_at' => now(),
        ]);
        $instance = $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('allowed-submit'));
        $step = $instance->steps()->where('status', 'active')->firstOrFail();
        $this->database->getConnection()->table('legal_document_comments')->where('id', $commentId)->update([
            'status' => 'open',
            'resolved_by_user_id' => null,
            'resolved_at' => null,
        ]);
        $approve = collect($resolver->for($actor, $document->refresh())->availableActionDetails)
            ->first(static fn ($detail): bool => $detail->action === 'approve');
        self::assertNotNull($approve);
        self::assertFalse($approve->enabled);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_open_blocking_comments');
        $this->service->decide($step, $actor, new WorkflowDecisionInput('approve', 'blocked-approve', 1, 0));
    }

    public function test_recovery_derives_step_statuses_and_reassignment_projection_from_evidence(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('derive-recovery'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        $instance = $this->service->decide($step, $actor, new WorkflowDecisionInput(
            'reassign',
            'derive-reassign',
            1,
            0,
            reason: 'Назначен замещающий сотрудник',
            reassignActorType: 'user',
            reassignActorReference: '9',
            dueAt: now()->addHours(3)->startOfSecond()->toAtomString(),
        ));
        $this->database->getConnection()->table('legal_workflow_steps')->where('id', $step->id)->update([
            'actor_type' => 'role',
            'actor_reference' => 'forged',
            'assignment_revision' => 0,
            'last_reassign_decision_id' => null,
            'status' => 'pending',
        ]);
        $this->database->getConnection()->table('legal_workflow_steps')
            ->where('instance_id', $instance->id)
            ->where('sequence', 20)
            ->update(['status' => 'active']);
        $recovery = new LegalWorkflowRecoveryService(new ImmutableAuditIntegrityService, $this->audit, $this->database->getConnection(), $this->templates);
        $recovery->markRequired($instance, 'Восстановление шагов');

        $recovered = $recovery->reconcile(15, (int) $instance->id);
        $reassigned = $recovered->steps()->whereKey($step->id)->firstOrFail();
        self::assertSame('active', $reassigned->status);
        self::assertSame('user', $reassigned->actor_type);
        self::assertSame('9', $reassigned->actor_reference);
        self::assertSame(1, $reassigned->assignment_revision);
        self::assertSame(['pending'], $recovered->steps()->where('sequence', 20)->pluck('status')->unique()->all());
    }

    public function test_recovery_fails_closed_when_decision_evidence_is_inconsistent(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('proof-recovery'));
        $step = $instance->steps()->where('step_key', 'legal_review')->firstOrFail();
        $this->service->decide($step, $actor, new WorkflowDecisionInput('approve', 'proof-approve', 1, 0));
        $this->database->getConnection()->table('legal_workflow_decisions')->where('idempotency_key', 'proof-approve')->update([
            'from_status' => 'pending',
        ]);
        $recovery = new LegalWorkflowRecoveryService(new ImmutableAuditIntegrityService, $this->audit, $this->database->getConnection(), $this->templates);
        $recovery->markRequired($instance, 'Проверка доказательств');

        try {
            $recovery->reconcile(15, (int) $instance->id);
            self::fail('Inconsistent decision evidence was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_reconciliation_decision_invalid', $exception->getMessage());
        }
        self::assertNotNull($instance->refresh()->reconciliation_required_at);
    }

    public function test_recovery_rolls_projection_and_marker_clear_back_when_audit_fails(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $instance = $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('audit-recovery'));
        $this->database->getConnection()->table('legal_archive_documents')->where('id', $document->id)->update([
            'approval_status' => 'approved',
            'lifecycle_status' => 'approved',
        ]);
        $recovery = new LegalWorkflowRecoveryService(new ImmutableAuditIntegrityService, $this->audit, $this->database->getConnection(), $this->templates);
        $recovery->markRequired($instance, 'Проверка транзакции');
        $this->audit->fail = true;

        try {
            $recovery->reconcile(15, (int) $instance->id);
            self::fail('Audit failure did not roll reconciliation back.');
        } catch (RuntimeException) {
        }
        $document->refresh();
        self::assertSame('approved', $document->approval_status);
        self::assertSame('approved', $document->lifecycle_status);
        self::assertNotNull($instance->refresh()->reconciliation_required_at);
    }

    private function createTemplate(WorkflowTestUser $actor): void
    {
        $this->templates->createVersion(15, 'contract', 'Согласование договора', [
            ['key' => 'legal_review', 'label' => 'Юридическая проверка', 'sequence' => 10, 'parallel_group' => 'legal', 'required' => true, 'policy_key' => 'legal_review', 'actor_type' => 'role', 'actor_reference' => 'legal_reviewer', 'due_in_hours' => 24],
            ['key' => 'security_review', 'label' => 'Проверка безопасности', 'sequence' => 10, 'parallel_group' => 'legal', 'required' => false, 'actor_type' => 'user', 'actor_reference' => '8', 'due_in_hours' => 24],
            ['key' => 'finance_a', 'label' => 'Финансовый контроль', 'sequence' => 20, 'parallel_group' => 'finance', 'required' => false, 'actor_type' => 'role', 'actor_reference' => 'finance_reviewer', 'due_in_hours' => 48],
        ], $actor);
    }

    /** @return array{LegalArchiveDocument, LegalArchiveDocumentVersion} */
    private function dossier(): array
    {
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => 15,
            'primary_project_id' => 7,
            'title' => 'Договор поставки',
            'type_profile_code' => 'contract.supply',
            'approval_status' => 'not_started',
            'lock_version' => 0,
        ]);
        $version = LegalArchiveDocumentVersion::query()->create([
            'document_id' => $document->id,
            'organization_id' => 15,
            'version_number' => 1,
            'is_current' => true,
            'status' => 'draft',
            'processing_status' => 'ready',
            'content_hash' => str_repeat('a', 64),
        ]);
        $document->forceFill(['current_primary_version_id' => $version->id])->save();

        return [$document->refresh(), $version];
    }

    private function actor(int $id, array $roles): WorkflowTestUser
    {
        $actor = new WorkflowTestUser;
        $actor->forceFill(['id' => $id, 'current_organization_id' => 15, 'name' => "Пользователь {$id}"]);
        $actor->workflowRoles = $roles;

        return $actor;
    }

    private function createSchema(): void
    {
        $schema = $this->database->schema();
        $schema->create('legal_archive_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('primary_project_id')->nullable();
            $table->string('title');
            $table->string('type_profile_code')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('lifecycle_status')->nullable();
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_active');
            $table->string('project_access_mode');
        });
        $schema->create('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('current_organization_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('status');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('authorization_contexts', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('parent_context_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $schema->create('user_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role_slug');
            $table->string('role_type');
            $table->unsignedBigInteger('context_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        $schema->create('role_conditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->string('condition_type');
            $table->json('condition_data')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        $schema->create('project_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_active');
        });
        $schema->create('legal_archive_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_file_id')->nullable();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedInteger('version_number');
            $table->boolean('is_current');
            $table->string('status');
            $table->string('processing_status');
            $table->string('content_hash', 64);
            $table->timestamps();
        });
        $schema->create('legal_archive_document_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('role');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_document_comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('author_user_id');
            $table->text('body');
            $table->string('visibility');
            $table->boolean('is_blocking');
            $table->string('status');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_workflow_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('code');
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('definition_hash', 64);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
            $table->unique(['organization_id', 'code', 'version']);
        });
        $schema->create('legal_workflow_template_heads', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->string('code');
            $table->unsignedBigInteger('template_id');
            $table->timestamps();
            $table->primary(['organization_id', 'code']);
        });
        $schema->create('legal_workflow_template_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('step_key');
            $table->string('label');
            $table->unsignedInteger('sequence');
            $table->string('parallel_group');
            $table->boolean('required');
            $table->string('policy_key')->nullable();
            $table->string('actor_type');
            $table->string('actor_reference');
            $table->unsignedInteger('due_in_hours')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_workflow_instances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->string('document_content_hash', 64);
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('template_version');
            $table->string('template_definition_hash', 64);
            $table->json('template_snapshot');
            $table->string('snapshot_hash', 64);
            $table->string('client_request_hash', 64);
            $table->string('request_hash', 64);
            $table->string('idempotency_key');
            $table->string('status');
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->timestamp('submitted_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('reconciliation_required_at')->nullable();
            $table->string('reconciliation_reason')->nullable();
            $table->unsignedInteger('reconciliation_attempts')->default(0);
            $table->text('reconciliation_last_error')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_id', 'idempotency_key']);
        });
        $schema->create('legal_workflow_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('step_key');
            $table->string('label');
            $table->unsignedInteger('sequence');
            $table->string('parallel_group');
            $table->boolean('required');
            $table->string('policy_key')->nullable();
            $table->string('actor_type');
            $table->string('actor_reference');
            $table->string('status');
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedInteger('assignment_revision')->default(0);
            $table->unsignedBigInteger('last_reassign_decision_id')->nullable();
            $table->unsignedInteger('due_in_hours')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['instance_id', 'step_key']);
        });
        $schema->create('legal_workflow_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('step_id')->nullable();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->string('document_content_hash', 64);
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action');
            $table->text('comment')->nullable();
            $table->text('reason')->nullable();
            $table->string('from_status');
            $table->string('to_status');
            $table->json('context')->nullable();
            $table->string('from_actor_type')->nullable();
            $table->string('from_actor_reference')->nullable();
            $table->timestamp('from_due_at')->nullable();
            $table->string('to_actor_type')->nullable();
            $table->string('to_actor_reference')->nullable();
            $table->timestamp('to_due_at')->nullable();
            $table->unsignedInteger('assignment_revision')->nullable();
            $table->unsignedBigInteger('previous_reassign_decision_id')->nullable();
            $table->string('request_hash', 64);
            $table->string('idempotency_key');
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['organization_id', 'instance_id', 'idempotency_key']);
        });
    }
}

final class WorkflowTestUser extends User
{
    /** @var list<string> */
    public array $workflowRoles = [];

    /** @var list<string>|null */
    public ?array $grantedPermissions = null;

    public int $permissionChecks = 0;

    public function hasPermission(string $permission, ?array $context = null): bool
    {
        $this->permissionChecks++;

        return $this->grantedPermissions === null || in_array($permission, $this->grantedPermissions, true);
    }
}

final class WorkflowAuthorizationServiceSpy extends AuthorizationService
{
    /** @var list<int> */
    public array $allowedContextIds = [];

    public function __construct() {}

    public function hasRole(User $user, string $roleSlug, ?int $contextId = null): bool
    {
        return $contextId !== null && in_array($contextId, $this->allowedContextIds, true);
    }
}

final class BatchWorkflowPermissionResolver extends PermissionResolver
{
    public function __construct() {}

    public function hasPermission(UserRoleAssignment $assignment, string $permission, ?array $context = null): bool
    {
        return $permission === LegalWorkflowPermissions::VIEW;
    }
}

final class RecordingWorkflowAudit implements LegalDocumentAudit
{
    /** @var list<string> */
    public array $events = [];

    public bool $fail = false;

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        if ($this->fail) {
            throw new RuntimeException('audit failed');
        }
        $this->events[] = $event;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void
    {
        if ($this->fail) {
            throw new RuntimeException('audit failed');
        }
        $this->events[] = $event;
    }

    public function recordContractForActorId(string $event, \App\Models\Contract $contract, ?int $actorId, array $context = []): void {}
}
