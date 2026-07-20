<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowDecision;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowActorResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
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
            assignmentLookup: static fn (): bool => true,
        );
        $this->service = new LegalDocumentWorkflowService(
            $this->templates,
            $authorization,
            $actors,
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
        self::assertSame('in_review', $document->refresh()->approval_status);
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
                self::assertSame('legal_workflow_reason_required', $exception->getMessage());
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
        } catch (DomainException $exception) {
            self::assertSame('legal_workflow_stale_action', $exception->getMessage());
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

    public function test_action_resolver_returns_typed_labels_permissions_and_blockers(): void
    {
        [$document, $version] = $this->dossier();
        $actor = $this->actor(8, ['legal_reviewer']);
        $this->createTemplate($actor);
        $resolver = new LegalWorkflowActionResolver(new LegalWorkflowAuthorization, new LegalWorkflowActorResolver(
            roleLookup: static fn (User $user, string $role): bool => in_array($role, $user->workflowRoles, true),
            assignmentLookup: static fn (): bool => true,
        ));

        $before = $resolver->for($actor, $document);
        self::assertSame('not_started', $before->status);
        self::assertTrue($before->action('submit')->enabled);
        self::assertSame('legal_archive.workflow.submit', $before->action('submit')->permission);

        $this->service->submit($document, (int) $version->id, $actor, WorkflowOverride::none('submit-1'));
        $after = $resolver->for($actor, $document->refresh());
        self::assertSame('in_progress', $after->status);
        self::assertTrue($after->action('approve')->enabled);
        self::assertNotSame('', $after->action('approve')->label);
        self::assertArrayHasKey('available_action_details', $after->toArray());
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
        $cancelled = $this->service->cancel($instance, $actor, new WorkflowDecisionInput(
            'cancel', 'cancel-1', 1, 0, reason: 'Документ отозван инициатором',
        ));
        self::assertSame('cancelled', $cancelled->status);
        self::assertSame(['cancelled'], $cancelled->steps->pluck('status')->unique()->values()->all());

        $instance = $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('expire-submit'));
        $instance->forceFill(['due_at' => now()->subMinute()])->save();
        self::assertSame(1, $this->service->expireDue(15, now()));
        self::assertSame('expired', $instance->refresh()->status);

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
        self::assertSame('approved', $document->refresh()->approval_status);
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

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_new_version_required');
        $this->service->submit($document->refresh(), (int) $version->id, $actor, WorkflowOverride::none('return-repeat'));
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
                assignmentLookup: static fn (): bool => false,
            ),
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
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('legal_archive_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedInteger('version_number');
            $table->boolean('is_current');
            $table->string('status');
            $table->string('processing_status');
            $table->string('content_hash', 64);
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
            $table->json('template_snapshot');
            $table->string('snapshot_hash', 64);
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

    public function hasPermission(string $permission, ?array $context = null): bool
    {
        return true;
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
        $this->events[] = $event;
    }

    public function recordContractForActorId(string $event, \App\Models\Contract $contract, ?int $actorId, array $context = []): void {}
}
