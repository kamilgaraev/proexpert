<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Services\AssistantChatAttachmentService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\QueuedAssistantChatService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditReservation;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantEgressFixtureService;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantScopedEgressTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private Project $project;

    private ScheduleTask $task;

    private User $operator;

    private UserRoleAssignment $platformAssignment;

    private OrdinaryAssistantEnrollmentService $enrollments;

    private const ACCOUNT_CANARY = 'PRIVATE_ACCOUNT_NAME_NOT_APPROVED';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->owner->updateQuietly(['name' => self::ACCOUNT_CANARY]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id,
            'name' => 'Вымышленный объект', 'is_archived' => false]));
        $schedule = ProjectSchedule::withoutEvents(fn () => ProjectSchedule::query()->create(['organization_id' => $this->fixture->organization->id,
            'project_id' => $this->project->id, 'created_by_user_id' => $this->fixture->owner->id, 'name' => 'Учебный график', 'status' => 'active',
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-20']));
        $this->task = ScheduleTask::withoutEvents(fn () => ScheduleTask::query()->create(['organization_id' => $this->fixture->organization->id,
            'schedule_id' => $schedule->id, 'created_by_user_id' => $this->fixture->owner->id, 'assigned_user_id' => $this->fixture->owner->id,
            'name' => 'Учебная задача', 'status' => 'not_started', 'task_type' => 'task', 'progress_percent' => 0,
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-20', 'planned_duration_days' => 19]));
        $this->operator = User::factory()->create(['is_active' => true]);
        $this->platformAssignment = UserRoleAssignment::query()->create(['user_id' => $this->operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $this->operator->id, 'is_active' => true]);
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'scoped-egress-'.Str::uuid());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_owner_reviewed_projection_completes_existing_loop_through_actual_guarded_sdk(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        self::assertStringNotContainsString(self::ACCOUNT_CANARY, json_encode($manifest, JSON_THROW_ON_ERROR));
        $context = app(OrdinaryAssistantScopedContext::class);
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls): array {
            self::assertStringNotContainsString(self::ACCOUNT_CANARY, json_encode([$input, $options], JSON_THROW_ON_ERROR));
            self::assertSame(['get_project_attention'], array_column($options['tools'], 'name'));
            self::assertCount(3, array_slice($input, 0, 3));
            $calls++;
            if ($calls === 1) {
                return $this->nativeToolCall('get_project_attention', ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3]);
            }
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount(1, $outputs);
            $projection = json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Учебная задача', $projection['data']['groups'][0]['items'][0]['title']);
            self::assertSame('assigned', $projection['data']['groups'][0]['items'][0]['assignment_state']);
            self::assertArrayNotHasKey('server_formatted_answer', $projection);
            self::assertArrayNotHasKey('actor_id', $projection['source_refs'][0]);

            return $this->answer();
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, $context)
            ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(2, $calls);
        self::assertSame('completed', AssistantRequest::query()->where('request_id', $response['request_id'])->sole()->status);
        self::assertFalse($context->allowsExpansion('rag_full_content'));
        self::assertNotEmpty($response['message']['content']);
        self::assertStringNotContainsString(self::ACCOUNT_CANARY, json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function test_foreign_owner_cannot_assign_scope_and_revoked_platform_permission_is_fresh(): void
    {
        foreach ([$this->fixture->foreignOwner, $this->operator] as $index => $actor) {
            if ($index === 1) {
                $this->platformAssignment->update(['is_active' => false]);
            }
            try {
                $this->enrollments->assign($actor, $this->fixture->organization->id, $this->fixture->owner, $this->project->id, ['get_project_attention']);
                self::fail('Untrusted actor assigned a guarded cohort');
            } catch (AssistantEgressDenied) {
                self::assertSame(0, AssistantEgressCohort::query()->where('actor_id', $this->fixture->owner->id)->count());
            }
        }
    }

    public static function admittedChoices(): array
    {
        return ['quality' => ['get_project_attention', false], 'production' => ['get_project_production_schedule_summary', false],
            'unadvertised-required-read' => ['get_project_production_schedule_summary', true]];
    }

    #[DataProvider('admittedChoices')]
    public function test_luna_can_choose_any_approved_sql_tool_for_an_unranked_question_without_history_memory_or_embedding(string $chosen, bool $outsideRequiredRead): void
    {
        Model::withoutEvents(fn () => QualityDefect::query()->create(['organization_id' => $this->fixture->organization->id,
            'project_id' => $this->project->id, 'created_by' => $this->fixture->owner->id, 'assigned_to' => $this->fixture->owner->id,
            'defect_number' => 'SYNTHETIC-'.Str::uuid(), 'title' => 'Учебный дефект', 'status' => 'open', 'severity' => 'minor', 'due_date' => '2026-10-17']));
        $conversation = Conversation::query()->create(['organization_id' => $this->fixture->organization->id,
            'user_id' => $this->fixture->owner->id, 'title' => 'Учебный диалог', 'context' => ['unapproved_history' => self::ACCOUNT_CANARY]]);
        Message::query()->create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => self::ACCOUNT_CANARY, 'metadata' => []]);
        $sources = [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'quality', 'limit' => 3]],
            ['tool' => 'get_project_production_schedule_summary', 'arguments' => ['project_id' => $this->project->id]]];
        $query = $outsideRequiredRead ? 'Кто работал на текущем проекте за октябрь 2026 года?' : 'Сопоставь эти учебные наблюдения и объясни главное.';
        if ($outsideRequiredRead) {
            config(['ai-assistant.read_tool_discovery_enabled' => true]);
            $required = AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query,
                'context' => ['entity_refs' => [['type' => 'project', 'id' => $this->project->id]]]]]);
            self::assertSame('get_project_workforce_summary', $required['name'] ?? null);
        }
        [$cohort, $payload] = $this->enroll($sources, $query, $conversation->id);
        $memory = $this->createMock(AssistantMemoryService::class);
        $memory->expects(self::never())->method('forContext');
        $this->app->instance(AssistantMemoryService::class, $memory);
        $embeddings = $this->createMock(RagEmbeddingProviderInterface::class);
        $embeddings->expects(self::never())->method('embed');
        $retriever = new RagRetriever($embeddings, app(UserProjectAccessService::class), app(AssistantDataAccessPolicy::class));
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $chosen, $sources): array {
            self::assertEqualsCanonicalizing(array_column($sources, 'tool'), array_column($options['tools'], 'name'));
            self::assertSame('auto', $options['tool_choice']);
            self::assertStringNotContainsString(self::ACCOUNT_CANARY, json_encode([$input, $options], JSON_THROW_ON_ERROR));
            if (++$calls === 1) {
                return $this->nativeToolCall($chosen, $sources[$chosen === 'get_project_attention' ? 0 : 1]['arguments']);
            }
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount(1, $outputs);
            $result = json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR);
            if ($chosen === 'get_project_attention') {
                self::assertSame('Учебный дефект', $result['data']['groups'][0]['items'][0]['title']);
            } else {
                self::assertSame('Учебная задача', $result['data']['examples'][0]['name']);
            }

            return $this->answer();
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class), $retriever)->ask($payload['message'], $cohort->organization_id,
                $this->fixture->owner, $conversation->id, $payload);
        self::assertSame(2, $calls);
        $stored = Message::query()->findOrFail($response['message']['id']);
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        $published = json_encode([$response, $stored->content, $stored->metadata, $request->response], JSON_THROW_ON_ERROR);
        foreach ([self::ACCOUNT_CANARY, 'owner_name', 'server_formatted_answer', 'source_version', '_read_arguments', 'live_entity_reads'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $published);
        }
        self::assertSame('completed', $request->status);
    }

    public function test_selected_scope_does_not_fall_back_for_changed_input_or_revoked_manifest_or_cohort(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        foreach (['changed-input', 'revoked-manifest', 'revoked-cohort'] as $case) {
            $input = $payload;
            if ($case === 'changed-input') {
                $input['message'] .= ' Дополнительный неизвестный источник.';
            }
            if ($case === 'revoked-manifest') {
                AssistantEgressEnrollment::query()->whereKey($manifest['enrollment_id'])->update(['status' => 'revoked']);
            }
            if ($case === 'revoked-cohort') {
                $cohort->update(['status' => 'revoked']);
            }
            try {
                $context->enter($this->fixture->owner, $cohort->organization_id, $input['message'], $input, null);
                self::fail('Invalid selected scope admitted input');
            } catch (AssistantEgressDenied) {
                self::assertTrue($context->isGuarded());
                self::assertFalse($context->isLegacy());
                self::assertFalse($context->allowsExpansion('read_tool_discovery'));
            }
        }
    }

    public function test_unenrolled_actor_uses_staged_legacy_route_but_client_cannot_select_it_for_guarded_actor(): void
    {
        [$cohort, $payload] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($this->fixture->administrator, $cohort->organization_id, 'Учебный вопрос', [], null);
        self::assertTrue($context->isLegacy());
        $payload['synthetic'] = true;
        $payload['guarded'] = false;
        $payload['context'] = ['entity_refs' => [['type' => 'project', 'id' => $this->project->id + 1000]]];
        $this->expectException(AssistantEgressDenied::class);
        $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
    }

    public function test_changed_source_after_review_requires_a_new_exact_projection(): void
    {
        [$cohort, $payload] = $this->enroll();
        $this->task->updateQuietly(['name' => 'Изменённая учебная задача']);
        $provider = $this->responses(function (): array {
            self::fail('Stale source reached HTTP transport');
        });
        $this->expectException(AssistantEgressDenied::class);
        AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
            ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
    }

    public function test_selected_scope_rejects_media_before_sync_or_queued_attachment_preparation(): void
    {
        [$cohort, $payload] = $this->enroll();
        $payload['attachment_ids'] = [(string) Str::uuid()];
        $this->app->bind(AssistantChatAttachmentService::class, static function (): never {
            self::fail('Unapproved media reached preparation');
        });
        $provider = $this->responses(static function (): never {
            self::fail('Unapproved media reached model transport');
        });
        foreach (['sync', 'queued'] as $entry) {
            try {
                if ($entry === 'sync') {
                    AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
                        ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
                } else {
                    app(QueuedAssistantChatService::class)->submit($cohort->organization_id, $this->fixture->owner, null, $payload, 'admin');
                }
                self::fail('Selected scope accepted unreviewed media');
            } catch (AssistantEgressDenied) {
                self::assertSame(0, AssistantRequest::query()->where('user_id', $this->fixture->owner->id)->count());
            }
        }
    }

    public function test_revoked_queued_admission_fails_before_transport_and_releases_the_reserved_budget(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $started = app(QueuedAssistantChatService::class)->submit($cohort->organization_id, $this->fixture->owner, null, $payload, 'admin');
        $request = app(AssistantRequestLifecycle::class)->claimQueued($started['request']->id);
        self::assertNotNull($request);
        $this->enrollments->revoke($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id']);
        $provider = $this->responses(static function (): never {
            self::fail('Revoked queued admission reached transport');
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
                ->executeStartedRequest($request, $this->fixture->owner);
            self::fail('Queued admission revocation ignored');
        } catch (AssistantEgressDenied) {
            self::assertSame('failed', $request->fresh()->status);
            self::assertSame('assistant_egress_denied', $request->fresh()->error_code);
            self::assertSame(0, AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->count());
            self::assertSame('cancelled', AICreditReservation::query()->findOrFail($request->reservation_id)->status);
        }
    }

    public function test_expired_or_invalid_selected_cohort_retains_the_guarded_route(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $context = app(OrdinaryAssistantScopedContext::class);
        foreach (['expired-cohort', 'expired-manifest', 'missing-project', 'unknown-policy'] as $case) {
            $cohort->update(['expires_at' => now()->addHour(), 'project_id' => $this->project->id, 'policy_version' => OrdinaryAssistantSourceProjection::VERSION]);
            AssistantEgressEnrollment::query()->whereKey($manifest['enrollment_id'])->update(['expires_at' => now()->addHour()]);
            match ($case) {
                'expired-cohort' => $cohort->update(['expires_at' => now()->subMinute()]),
                'expired-manifest' => AssistantEgressEnrollment::query()->whereKey($manifest['enrollment_id'])->update(['expires_at' => now()->subMinute()]),
                'missing-project' => $cohort->update(['project_id' => null]),
                'unknown-policy' => $cohort->update(['policy_version' => 'unreviewed-policy']),
            };
            try {
                $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
                self::fail('Invalid selected cohort fell through');
            } catch (AssistantEgressDenied) {
                self::assertTrue($context->isGuarded());
                self::assertFalse($context->isLegacy());
            }
        }
    }

    public function test_enrollment_http_contract_requires_authorized_assignment_then_explicit_review(): void
    {
        $this->withoutMiddleware();
        $prefix = '/api/v1/admin/ai-assistant/egress';
        $target = ['organization_id' => $this->fixture->organization->id, 'actor_id' => $this->fixture->owner->id,
            'project_id' => $this->project->id, 'tools' => ['get_project_attention']];
        self::assertFalse(app(\App\Domain\Authorization\Services\AuthorizationService::class)->canCurrent($this->fixture->member,
            OrdinaryAssistantEnrollmentService::ORGANIZATION_PERMISSION, ['organization_id' => $this->fixture->organization->id]));
        $this->actingAs($this->fixture->member)->postJson($prefix.'/cohorts', $target + ['synthetic' => true])->assertForbidden();
        $assigned = $this->actingAs($this->operator)->postJson($prefix.'/cohorts', $target)->assertCreated()->assertJsonPath('success', true);
        $cohortId = $assigned->json('data.cohort_id');
        $input = ['message' => 'Учебный вопрос по объекту.', 'profile' => 'normal', 'allow_actions' => false];
        $quote = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $input + ['request_id' => (string) Str::uuid()]);
        $this->actingAs($this->fixture->owner);
        $preview = $this->postJson($prefix.'/enrollments/preview', ['cohort_id' => $cohortId, 'quote_id' => $quote['quote_id'], 'input' => $input,
            'sources' => [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3]]]])
            ->assertCreated()->assertJsonPath('success', true)->assertJsonPath('data.provider', 'timeweb')
            ->assertJsonPath('data.endpoint', 'https://fixture.invalid/v1/responses');
        $id = $preview->json('data.enrollment_id');
        $hash = $preview->json('data.manifest_hash');
        $this->postJson($prefix.'/enrollments/'.$id.'/confirm', ['manifest_hash' => $hash])->assertUnprocessable();
        $this->postJson($prefix.'/enrollments/'.$id.'/confirm', ['manifest_hash' => $hash, 'declaration' => OrdinaryAssistantEnrollmentService::DECLARATION])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.status', 'approved');
        self::assertSame(OrdinaryAssistantEnrollmentService::DECLARATION, AssistantEgressEnrollment::query()->findOrFail($id)->declaration_version);
        $this->deleteJson($prefix.'/enrollments/'.$id)->assertOk()->assertJsonPath('success', true);
        self::assertSame('revoked', AssistantEgressEnrollment::query()->findOrFail($id)->status);
        self::assertSame('active', AssistantEgressCohort::query()->findOrFail($cohortId)->status);
    }

    public function test_revocation_during_source_capture_cannot_be_overwritten_by_confirmation(): void
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention']);
        $input = ['request_id' => (string) Str::uuid(), 'message' => 'Учебный вопрос об объекте.', 'profile' => 'normal', 'allow_actions' => false];
        $input['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $input)['quote_id'];
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $input['quote_id'], $input,
            [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3]]]);
        $revoked = false;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $event) use (&$revoked, $cohort, $manifest): void {
            if (! $revoked && str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, '"projects"')) {
                $revoked = true;
                $this->enrollments->revoke($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id']);
            }
        });
        try {
            $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'],
                $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
            self::fail('Confirmation restored a revoked artifact');
        } catch (AssistantEgressDenied) {
            self::assertTrue($revoked);
            self::assertSame('revoked', AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->status);
        }
        $provider = $this->responses(static function (): never {
            self::fail('Revoked artifact reached wire transport');
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
                ->ask($input['message'], $cohort->organization_id, $this->fixture->owner, null, $input);
            self::fail('Revoked artifact admitted a model attempt');
        } catch (AssistantEgressDenied) {
            self::assertSame(0, AssistantRequest::query()->where('user_id', $this->fixture->owner->id)->count());
            self::assertSame(0, AICreditProviderUsage::query()->where('organization_id', $this->fixture->organization->id)->count());
        }
    }

    #[DataProvider('cohortReassignments')]
    public function test_late_preview_cannot_survive_same_second_cohort_reassignment(bool $revokeFirst, array $newTools): void
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention']);
        $input = ['request_id' => (string) Str::uuid(), 'message' => 'Учебный вопрос с поздним просмотром.', 'profile' => 'normal', 'allow_actions' => false];
        $input['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $input)['quote_id'];
        $reassigned = false;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $event) use (&$reassigned, $cohort, $revokeFirst, $newTools): void {
            if (! $reassigned && str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, '"projects"')) {
                $reassigned = true;
                if ($revokeFirst) {
                    $this->enrollments->revokeCohort($this->operator, $cohort->id);
                }
                $this->enrollments->assign($this->operator, $cohort->organization_id, $this->fixture->owner, $this->project->id, $newTools);
            }
        });
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $input['quote_id'], $input,
            [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3]]]);
        self::assertTrue($reassigned);
        $current = AssistantEgressCohort::query()->findOrFail($cohort->id);
        self::assertSame($cohort->updated_at->toISOString(), $current->updated_at->toISOString());
        self::assertNotSame($cohort->generation, $current->generation);
        self::assertSame($cohort->generation, $manifest['cohort_generation']);
        self::assertSame('pending_review', AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->status);
        try {
            $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'],
                $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
            self::fail('Old cohort generation became approved');
        } catch (AssistantEgressDenied) {
            self::assertNotSame('approved', AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->status);
        }
        $provider = $this->responses(static function (): never {
            self::fail('Old cohort generation reached wire transport');
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
                ->ask($input['message'], $cohort->organization_id, $this->fixture->owner, null, $input);
            self::fail('Old cohort generation admitted a model attempt');
        } catch (AssistantEgressDenied) {
            self::assertSame(0, AssistantRequest::query()->where('user_id', $this->fixture->owner->id)->count());
            self::assertSame(0, AICreditProviderUsage::query()->where('organization_id', $this->fixture->organization->id)->count());
        }
    }

    public static function cohortReassignments(): array
    {
        return ['reduced-tool-policy' => [false, ['get_project_production_schedule_summary']],
            'revoke-and-identical-policy' => [true, ['get_project_attention']]];
    }

    public function test_current_tool_policy_denies_a_previously_admitted_source_even_with_unchanged_generation(): void
    {
        [$cohort, $input] = $this->enroll();
        $scope = app(OrdinaryAssistantScopedContext::class);
        $scope->enter($this->fixture->owner, $cohort->organization_id, $input['message'], $input, null);
        self::assertSame(['get_project_attention'], $scope->allowedToolNames());
        $cohort->update(['allowed_tools' => ['get_project_production_schedule_summary']]);
        try {
            $scope->allowedToolNames();
            self::fail('Current cohort policy removal was ignored');
        } catch (AssistantEgressDenied) {
            self::assertSame(0, AICreditProviderUsage::query()->where('organization_id', $this->fixture->organization->id)->count());
        }
    }

    public static function sequentialChoices(): array
    {
        return [
            'none' => [[]],
            'attention-only' => [['get_project_attention']],
            'production-only' => [['get_project_production_schedule_summary']],
            'attention-then-production' => [['get_project_attention', 'get_project_production_schedule_summary']],
            'production-then-attention' => [['get_project_production_schedule_summary', 'get_project_attention']],
        ];
    }

    #[DataProvider('sequentialChoices')]
    public function test_guarded_model_selects_zero_one_or_two_reads_within_three_quoted_calls(array $sequence): void
    {
        config(['ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 3]]);
        $sources = $this->sequentialSources();
        [$cohort, $payload] = $this->enroll($sources, 'Сопоставь эти учебные наблюдения и объясни главное.', profile: 'short');
        $calls = 0;
        $initial = $previousOutputs = [];
        $final = 'Учебные наблюдения учтены; начните с согласования дальнейших шагов.';
        $provider = $this->responses(function (array $input, array $options) use (&$calls, &$initial, &$previousOutputs, $sources, $sequence, $final): array {
            self::assertSame('auto', $options['tool_choice']);
            self::assertEqualsCanonicalizing(array_column($sources, 'tool'), array_column($options['tools'], 'name'));
            self::assertSame(['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 3], $options['budget_limits']);
            self::assertStringNotContainsString(self::ACCOUNT_CANARY, json_encode([$input, $options], JSON_THROW_ON_ERROR));
            $initial = $initial ?: array_slice($input, 0, 3);
            self::assertSame($initial, array_slice($input, 0, 3));
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            $nativeCalls = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call'));
            self::assertCount($calls, $outputs);
            self::assertCount($calls, $nativeCalls);
            self::assertSame($previousOutputs, array_slice($outputs, 0, count($previousOutputs)));
            foreach ($outputs as $index => $output) {
                self::assertSame('call_multi_'.($index + 1), $output['call_id']);
                self::assertSame($sequence[$index], $nativeCalls[$index]['name']);
                $source = $sources[$sequence[$index] === 'get_project_attention' ? 0 : 1];
                self::assertSame($source['arguments'], json_decode($nativeCalls[$index]['arguments'], true, 512, JSON_THROW_ON_ERROR));
                $projected = json_decode($output['output'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($this->project->id, $projected['data']['project']['id']);
                self::assertSame([['entity_type' => 'project', 'entity_id' => $this->project->id, 'project_id' => $this->project->id]], $projected['source_refs']);
                self::assertArrayNotHasKey('server_formatted_answer', $projected);
                if ($source['tool'] === 'get_project_production_schedule_summary') {
                    self::assertSame('2026-10-02', $projected['data']['period_start']);
                    self::assertSame('2026-10-19', $projected['data']['period_end']);
                }
            }
            $previousOutputs = $outputs;
            $next = $sequence[$calls] ?? null;
            $calls++;
            if ($next !== null) {
                $source = $sources[$next === 'get_project_attention' ? 0 : 1];

                return $this->nativeToolCall($next, $source['arguments'], 'multi_'.$calls);
            }

            return array_replace($this->answer(), ['content' => $final]);
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(count($sequence) + 1, $calls);
        self::assertSame($final, $response['message']['content']);
        $stored = Message::query()->findOrFail($response['message']['id']);
        self::assertSame($final, $stored->content);
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        self::assertSame('completed', $request->status);
        self::assertSame(3, $request->max_calls);
        self::assertSame($calls, $request->calls_used);
        self::assertSame($calls, AICreditProviderUsage::query()->where('organization_id', $this->fixture->organization->id)->count());
        self::assertSame($sequence === [], ($response['message']['metadata']['source_refs'] ?? []) === []);
    }

    public function test_two_quoted_calls_never_hide_a_third_provider_call_after_two_selected_reads(): void
    {
        config(['ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 2]]);
        $sources = $this->sequentialSources();
        [$cohort, $payload] = $this->enroll($sources, 'Сопоставь эти учебные наблюдения и объясни главное.', profile: 'short');
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $sources): array {
            self::assertLessThan(2, $calls);
            self::assertSame(2, $options['budget_limits']['max_calls']);
            self::assertSame('auto', $options['tool_choice']);
            $outputs = array_values(array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount($calls, $outputs);
            $source = $sources[$calls++];

            return $this->nativeToolCall($source['tool'], $source['arguments'], 'budget_'.$calls);
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(2, $calls);
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        self::assertSame(2, $request->max_calls);
        self::assertSame(2, $request->calls_used);
        self::assertSame(2, AICreditProviderUsage::query()->where('organization_id', $this->fixture->organization->id)->count());
        self::assertSame(trans_message('ai_assistant_facts.live_proof_required'), $response['message']['content']);
        self::assertTrue($response['message']['metadata']['needs_clarification']);
    }

    private function sequentialSources(): array
    {
        return [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 2]],
            ['tool' => 'get_project_production_schedule_summary', 'arguments' => ['project_id' => $this->project->id,
                'period_start' => '2026-10-02', 'period_end' => '2026-10-19']]];
    }

    public static function clarificationReadCounts(): array
    {
        return ['before-reads' => [0, 'period'], 'after-one-read' => [1, 'focus'], 'after-two-reads' => [2, 'goal']];
    }

    #[DataProvider('clarificationReadCounts')]
    public function test_typed_clarification_survives_zero_one_or_two_approved_reads(int $reads, string $missing): void
    {
        config(['ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 3]]);
        $sources = $this->sequentialSources();
        [$cohort, $payload] = $this->enroll($sources, 'Сколько открытых задач на текущем проекте?', profile: 'short');
        $calls = 0;
        $provider = $this->responses(function (array $input, array $options) use (&$calls, $reads, $missing, $sources): array {
            self::assertSame('auto', $options['tool_choice']);
            self::assertStringContainsString('"kind":"clarification"', $input[0]['content']);
            self::assertCount($calls, array_filter($input, static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            if ($calls < $reads) {
                $source = $sources[$calls++];

                return $this->nativeToolCall($source['tool'], $source['arguments'], 'clarification_'.$calls);
            }
            $calls++;

            return array_replace($this->answer(), ['content' => json_encode(['kind' => 'clarification', 'missing' => $missing], JSON_THROW_ON_ERROR)]);
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame($reads + 1, $calls);
        self::assertSame(trans_message('ai_assistant.clarification_'.$missing), $response['message']['content']);
        $stored = Message::query()->findOrFail($response['message']['id']);
        self::assertSame($response['message']['content'], $stored->content);
        foreach ([$response['message']['metadata'], $stored->metadata] as $metadata) {
            self::assertTrue($metadata['needs_clarification']);
            self::assertSame('insufficient_data', $metadata['outcome']);
            self::assertSame('unverified', $metadata['validation_status']);
            self::assertSame([], $metadata['source_refs']);
        }
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        self::assertSame($calls, $request->calls_used);
        self::assertSame([], $request->response['message']['metadata']['source_refs']);
        self::assertTrue($request->response['message']['metadata']['needs_clarification']);
    }

    public static function invalidClarifications(): array
    {
        return ['unknown' => ['{"kind":"clarification","missing":"organization_id"}'],
            'wrong-kind' => ['{"kind":"answer","missing":"period"}'],
            'extra-field' => ['{"kind":"clarification","missing":"period","question":"Непроверенный вывод?"}'],
            'value' => ['{"kind":"clarification","missing":"period","value":"2026-10-01"}'],
            'malformed' => ['{"kind":"clarification","missing":"period"'],
            'financial-field' => ['{"kind":"clarification","missing":"period","question":"Бюджет 123456 рублей?"}'],
            'plain-financial-claim' => ['Бюджет текущего проекта составляет 123456 рублей.']];
    }

    #[DataProvider('invalidClarifications')]
    public function test_invalid_clarification_cannot_publish_a_question_or_financial_claim_after_a_read(string $text): void
    {
        [$cohort, $payload] = $this->enroll($this->sequentialSources(), 'Сколько открытых задач на текущем проекте?');
        $calls = 0;
        $provider = $this->responses(function () use (&$calls, $text): array {
            if (++$calls === 1) {
                $source = $this->sequentialSources()[0];

                return $this->nativeToolCall($source['tool'], $source['arguments'], 'invalid_clarification');
            }

            return array_replace($this->answer(), ['content' => $text]);
        });
        $response = AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
        self::assertSame(2, $calls);
        self::assertSame(trans_message('ai_assistant_facts.live_proof_required'), $response['message']['content']);
        self::assertSame([], $response['message']['metadata']['source_refs']);
        self::assertStringNotContainsString('123456', json_encode($response, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Непроверенный вывод', $response['message']['content']);
    }

    public static function staleClarifications(): array
    {
        return ['zero-reads' => [false, false], 'one-read' => [true, false], 'actor-revoked' => [false, true]];
    }

    #[DataProvider('staleClarifications')]
    public function test_typed_clarification_cannot_skip_fresh_publication_checks(bool $readFirst, bool $revokeActor): void
    {
        [$cohort, $payload] = $this->enroll($this->sequentialSources(), 'Сколько открытых задач на текущем проекте?');
        $calls = 0;
        $provider = $this->responses(function () use (&$calls, $readFirst, $revokeActor): array {
            if (++$calls === 1 && $readFirst) {
                $source = $this->sequentialSources()[0];

                return $this->nativeToolCall($source['tool'], $source['arguments'], 'stale_clarification');
            }
            if ($revokeActor) {
                $this->fixture->owner->updateQuietly(['is_active' => false]);
            } else {
                $this->task->updateQuietly(['name' => 'Изменённый источник перед публикацией']);
            }

            return array_replace($this->answer(), ['content' => '{"kind":"clarification","missing":"goal"}']);
        });
        try {
            AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
                app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
            self::fail('Clarification bypassed the fresh source check');
        } catch (AssistantEgressDenied) {
            self::assertSame($readFirst ? 2 : 1, $calls);
            self::assertSame(0, Message::query()->where('role', 'assistant')->whereIn('conversation_id',
                Conversation::query()->where('organization_id', $this->fixture->organization->id)->select('id'))->count());
        }
    }

    public function test_typed_clarification_does_not_authorize_actions_or_new_input(): void
    {
        [$cohort, $payload] = $this->enroll($this->sequentialSources(), 'Сколько открытых задач на текущем проекте?');
        $payload['allow_actions'] = true;
        $provider = $this->responses(static function (): never {
            self::fail('Action-enabled input reached the clarification model');
        });
        $this->expectException(AssistantEgressDenied::class);
        AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload);
    }

    private function enroll(?array $sources = null, ?string $query = null, ?int $conversationId = null, string $profile = 'normal'): array
    {
        $sources ??= [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3]]];
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, array_values(array_unique(array_column($sources, 'tool'))));
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $query ?? 'Покажи учебные просроченные задачи объекта.',
            'conversation_id' => $conversationId, 'profile' => $profile, 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            $sources);
        $stored = AssistantEgressEnrollment::query()->findOrFail($manifest['enrollment_id'])->sources[0];
        $current = app(OrdinaryAssistantSourceCatalog::class)->capture($this->fixture->owner, $cohort->organization_id,
            $this->project->id, $stored['tool'], $stored['arguments']);
        self::assertSame(OrdinaryAssistantSourceProjection::hash($stored), OrdinaryAssistantSourceProjection::hash($current['proof']));
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);

        return [$cohort, $payload, $manifest];
    }

    private function responses(callable $callback): ScopedEgressResponsesProvider
    {
        $provider = $this->createMock(ScopedEgressResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback($callback);

        return $provider;
    }

    private function nativeToolCall(string $name, array $arguments, string $id = 'scoped'): array
    {
        $call = ['type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => 'call_'.$id, 'status' => 'completed', 'name' => $name,
            'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)];

        return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100, 'output_tokens' => 20,
            'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }

    private function answer(): array
    {
        return ['content' => 'Учебная задача просрочена, ответственный назначен.', 'output' => [], 'function_calls' => [],
            'input_tokens' => 100, 'output_tokens' => 20, 'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
    }
}

interface ScopedEgressResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
