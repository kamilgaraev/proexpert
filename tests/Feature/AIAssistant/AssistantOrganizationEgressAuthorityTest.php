<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\Project;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AssistantEgressFixtureService;
use Tests\Support\AdminApiTestContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOrganizationEgressAuthorityTest extends TestCase
{
    private const BASE = '/api/v1/admin/ai-assistant/egress';
    private const PRIVATE_VALUE = 'B6_PRIVATE_DEPENDENCY_CALLER_VALUE';

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private OrdinaryAssistantEnrollmentService $enrollments;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 14:00:00');
        Queue::fake();
        Http::preventStrayRequests();
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true,
            'ai-assistant-credits.enforce' => true, 'ai-assistant.llm.provider' => 'timeweb',
            'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id,
            'name' => 'Учебный проект допуска', 'is_archived' => false]));
        AuthorizationContext::getProjectContext($this->project->id, $this->fixture->organization->id);
        $this->project->users()->syncWithoutDetaching([$this->fixture->member->id => ['role' => 'member', 'is_active' => true,
            'assigned_by_user_id' => $this->fixture->owner->id, 'assigned_at' => now()]]);
        $embedding = $this->createMock(RagEmbeddingProviderInterface::class);
        $embedding->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $embedding);
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->withoutMiddleware();
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'org-egress-'.Str::uuid());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) { DB::beginTransaction(); }
        parent::tearDown();
    }

    public function test_empty_capability_get_passes_the_real_admin_interface_stack_without_accepting_client_filters(): void
    {
        $this->withMiddleware();
        self::assertFalse($this->app->bound('middleware.disable'));
        $owner = $this->fixture->owner;
        $organizationId = (int) $this->fixture->organization->id;
        self::assertTrue(app(AIPermissionChecker::class)->canUseAssistant($owner, $organizationId));
        self::assertTrue(app(AuthorizationService::class)->canAccessInterface($owner, 'admin', AuthorizationContext::getOrganizationContext($organizationId)));
        $session = (string) Str::uuid();
        UserAuthSession::query()->create(['user_id' => $owner->id, 'organization_id' => $organizationId, 'session_uuid' => $session,
            'device_fingerprint' => hash('sha256', $session), 'device_name' => 'Organization egress isolated fixture',
            'ip_address' => '127.0.0.1', 'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $token = app(WebAuthTokenService::class)->issue($owner, 'admin', $session, $organizationId, false);
        $headers = (new AdminApiTestContext($this->fixture->organization, $owner, $token->accessToken))->authHeaders();
        $this->get(self::BASE.'/capability', $headers)->assertOk()->assertJsonPath('data.mode', 'legacy');
        $this->getJson(self::BASE.'/capability', $headers)->assertOk()->assertJsonPath('data.mode', 'legacy');
        foreach (['{}', ' { } '] as $body) {
            $this->call('GET', self::BASE.'/capability', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']),
                $body)->assertOk()->assertJsonPath('data.mode', 'legacy');
        }
        foreach (['actor_id=1', 'organization_id=1', 'current_interface=admin', 'current_interface=lk'] as $query) {
            $this->get(self::BASE.'/capability?'.$query, $headers)->assertForbidden();
        }
        foreach ([['actor_id' => 1], ['organization_id' => 1], ['current_interface' => 'admin']] as $body) {
            $this->json('GET', self::BASE.'/capability', $body, $headers)->assertForbidden();
            $this->call('GET', self::BASE.'/capability', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/x-www-form-urlencoded']),
                http_build_query($body))->assertForbidden();
        }
        foreach (['null', 'true', '1', '""', '{', str_repeat(' ', 257).'[]'] as $body) {
            $this->call('GET', self::BASE.'/capability', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']),
                $body)->assertForbidden();
        }
        self::assertSame(0, AssistantEgressCohort::query()->count());
        self::assertSame(0, AssistantEgressEnrollment::query()->count());
    }

    public function test_owner_can_assign_review_confirm_and_revoke_own_scope_without_a_system_assignment(): void
    {
        self::assertSame(0, UserRoleAssignment::query()->where('user_id', $this->fixture->owner->id)
            ->whereHas('context', static fn ($query) => $query->where('type', 'system'))->count());
        $cohort = $this->assign($this->fixture->owner);
        [, $preview] = $this->preview($this->fixture->owner, $cohort);
        $this->confirm($this->fixture->owner, $preview)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson(self::BASE.'/capability')->assertOk()->assertJsonPath('data.can_review_sources', true);
        $firstGeneration = $cohort->generation;
        $this->assign($this->fixture->owner);
        self::assertNotSame($firstGeneration, $cohort->refresh()->generation);
        self::assertSame('revoked', AssistantEgressEnrollment::query()->findOrFail($preview['enrollment_id'])->status);
        [, $preview] = $this->preview($this->fixture->owner, $cohort);
        $this->confirm($this->fixture->owner, $preview)->assertOk();
        $this->deleteJson(self::BASE.'/enrollments/'.$preview['enrollment_id'])->assertOk();
        self::assertSame('revoked', AssistantEgressEnrollment::query()->findOrFail($preview['enrollment_id'])->status);
        $this->deleteJson(self::BASE.'/cohorts/'.$cohort->id)->assertOk();
        self::assertSame('revoked', $cohort->refresh()->status);
    }

    public function test_selected_custom_permission_supports_the_whole_workflow_without_owner_or_settings_permission(): void
    {
        $this->delegate(true);
        $manager = $this->fixture->member;
        self::assertFalse(app(AuthorizationService::class)->canCurrent($manager, 'ai_assistant.settings.manage', ['organization_id' => $this->fixture->organization->id]));
        self::assertFalse($manager->organizations()->where('organizations.id', $this->fixture->organization->id)->wherePivot('is_owner', true)->exists());
        $cohort = $this->assign($manager, $manager);
        [$payload, $preview] = $this->preview($manager, $cohort);
        $this->actingAs($this->fixture->owner);
        $this->confirm($this->fixture->owner, $preview)->assertForbidden();
        self::assertSame('pending_review', AssistantEgressEnrollment::query()->findOrFail($preview['enrollment_id'])->status);
        $this->confirm($manager, $preview)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson(self::BASE.'/capability')->assertOk()->assertJsonPath('data.can_review_sources', true);
        self::assertSame((int) $manager->id, (int) AssistantEgressEnrollment::query()->findOrFail($preview['enrollment_id'])->reviewed_by);
        $context = app(OrdinaryAssistantScopedContext::class);
        $context->enter($manager, $cohort->organization_id, $payload['message'], $payload, null);
        $source = $this->sources()[0];
        $native = app(AIToolRegistry::class)->getTool($source['tool'])->execute($source['arguments'], $manager, $this->fixture->organization);
        $local = $context->projectToolResult($source['tool'], $source['arguments'], $native);
        $wire = $context->providerToolResult($local);
        self::assertSame($preview['sources'][0]['projection_hash'], OrdinaryAssistantSourceProjection::hash($wire));
        self::assertSame([['entity_type' => 'project', 'entity_id' => $this->project->id, 'project_id' => $this->project->id]], $wire['source_refs']);
        $reordered = array_reverse($local, true);
        $reordered['source_refs'] = array_map(static fn (array $reference): array => array_reverse($reference, true), $local['source_refs']);
        self::assertSame($local['source_refs'], $context->validateResults([$reordered + ['_tool_name' => $source['tool']]]));
        $context->assertPublication($local['source_refs']);
        foreach (['actor', 'version', 'filters', 'permissions', 'type'] as $tamper) {
            $forged = $local;
            match ($tamper) {
                'actor' => $forged['source_refs'][0]['actor_id'] = $this->fixture->owner->id,
                'version' => $forged['source_refs'][0]['source_version'] = 'unregistered-version',
                'filters' => $forged['source_refs'][0]['attention_filters']['focus'] = 'quality',
                'permissions' => $forged['source_refs'][0]['required_permissions'] = ['projects.view'],
                'type' => $forged['source_refs'][0]['entity_type'] = 'project',
            };
            self::assertSame($wire, $context->providerToolResult($forged));
            $this->denied(fn () => $context->validateResults([$forged + ['_tool_name' => $source['tool']]]), 'source_stale');
            $this->denied(fn () => $context->assertPublication($forged['source_refs']), 'source_unknown');
        }
        $access = app(AssistantDataAccessPolicy::class);
        self::assertFalse($access->withCurrentChecks($manager, $cohort->organization_id,
            fn (): bool => $access->canReadReference($manager, $cohort->organization_id, ['entity_type' => 'project',
                'entity_id' => $this->project->id, 'project_id' => $this->project->id, 'organization_id' => $cohort->organization_id]), true));
        $context->clear();
        $context->enter($manager, $cohort->organization_id, $payload['message'], $payload, null);
        $this->denied(fn () => $context->assertPublication($local['source_refs']), 'source_unknown');
        $this->denied(fn () => $context->validateResults([$local + ['_tool_name' => $source['tool']]]), 'source_stale');
        $calls = 0;
        $provider = $this->createMock(OrganizationAuthorityResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            $output = $calls === 1 ? [['type' => 'function_call', 'id' => 'fc_b6', 'call_id' => 'call_b6', 'status' => 'completed',
                'name' => 'get_project_attention', 'arguments' => json_encode($this->sources()[0]['arguments'], JSON_THROW_ON_ERROR)]] : [];
            return ['content' => $calls === 1 ? '' : 'В выбранном графике задач, требующих внимания, нет.',
                'output' => $output, 'function_calls' => $output, 'input_tokens' => 100, 'output_tokens' => 20,
                'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
        });
        $response = AssistantEgressFixtureService::forScope($provider, $manager, $cohort->organization_id, app(OrdinaryAssistantScopedContext::class))
            ->ask($payload['message'], $cohort->organization_id, $manager, null, $payload);
        self::assertSame(2, $calls);
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        self::assertSame('completed', $request->status);
        $lifecycle = app(AssistantRequestLifecycle::class);
        self::assertSame('completed', $lifecycle->status($request->request_id, $manager, $cohort->organization_id)['status']);
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat', OrdinaryAssistantEnrollmentService::ORGANIZATION_PERMISSION],
            'project-management' => ['projects.view']]]);
        try {
            $lifecycle->status($request->request_id, $manager, $cohort->organization_id);
            self::fail('Stored SQL reference ignored a current schedule permission revocation');
        } catch (AuthorizationException) {
            self::assertSame('completed', $request->refresh()->status);
        }
        $this->deleteJson(self::BASE.'/cohorts/'.$cohort->id)->assertOk();
        self::assertSame('revoked', $cohort->refresh()->status);
    }

    public function test_organization_authority_cannot_cross_org_actor_or_project_boundaries_and_platform_stays_global(): void
    {
        $before = AssistantEgressCohort::query()->count();
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'is_archived' => false]));
        foreach ([
            [$this->fixture->foreignOwner, $this->fixture->organization->id, $this->fixture->owner, $this->project->id],
            [$this->fixture->owner, $this->fixture->foreignOrganization->id, $this->fixture->foreignOwner, $foreignProject->id],
            [$this->fixture->owner, $this->fixture->organization->id, $this->fixture->foreignOwner, $this->project->id],
            [$this->fixture->owner, $this->fixture->organization->id, $this->fixture->owner, $foreignProject->id],
            [$this->fixture->member, $this->fixture->organization->id, $this->fixture->owner, $this->project->id],
        ] as [$operator, $organizationId, $actor, $projectId]) {
            $this->denied(fn () => $this->enrollments->assign($operator, $organizationId, $actor, $projectId, ['get_project_attention']));
        }
        self::assertSame($before, AssistantEgressCohort::query()->count());
        $this->actingAs($this->fixture->owner)->postJson(self::BASE.'/cohorts', $this->assignmentBody($this->fixture->foreignOwner))->assertNotFound();
        self::assertSame($before, AssistantEgressCohort::query()->count());
        $cohort = $this->assign($this->fixture->owner);
        $generation = $cohort->generation;
        $this->actingAs($this->fixture->foreignOwner)->deleteJson(self::BASE.'/cohorts/'.$cohort->id)->assertForbidden();
        self::assertSame('active', $cohort->refresh()->status);
        self::assertSame($generation, $cohort->generation);
        $operator = User::factory()->create(['is_active' => true, 'current_organization_id' => null]);
        $assignment = UserRoleAssignment::query()->create(['user_id' => $operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $operator->id, 'is_active' => true]);
        $global = $this->enrollments->assign($operator, $this->fixture->organization->id, $this->fixture->owner, $this->project->id, ['get_project_attention']);
        self::assertSame((int) $operator->id, (int) $global->assigned_by);
        $this->denied(fn () => $this->enrollments->assertOrganizationManager((int) $operator->id, $this->fixture->organization->id));
        $assignment->update(['is_active' => false]);
        $this->denied(fn () => $this->enrollments->revokeCohort($operator, $global->id));
        self::assertSame('active', $global->refresh()->status);
    }

    public function test_delegated_management_does_not_grant_literal_source_or_project_read_access(): void
    {
        $this->delegate(true);
        $manager = $this->fixture->member;
        $cohort = $this->assign($manager);
        [$payload] = $this->quotedInput($this->fixture->owner);
        $source = $this->sources();
        app(OrdinaryAssistantSourceCatalog::class)->captureForReview($this->fixture->owner, (int) $manager->id,
            $cohort->organization_id, $this->project->id, $source[0]['tool'], $source[0]['arguments']);
        $this->project->users()->updateExistingPivot($manager->id, ['is_active' => false]);
        $before = AssistantEgressEnrollment::query()->count();
        $this->actingAs($manager)->postJson(self::BASE.'/enrollments/preview', ['cohort_id' => $cohort->id,
            'quote_id' => $payload['quote_id'], 'input' => $this->previewInput($payload), 'sources' => $source])->assertForbidden();
        self::assertSame($before, AssistantEgressEnrollment::query()->count());
        self::assertSame('active', $cohort->refresh()->status);
    }

    public function test_revocation_is_fresh_at_confirm_before_a_provider_send_and_at_publication(): void
    {
        foreach (['confirm', 'first_wire', 'publication', 'membership'] as $stage) {
            $this->delegate(true);
            $manager = $this->fixture->member;
            $cohort = $this->assign($manager);
            [$payload, $preview] = $this->preview($manager, $cohort);
            if ($stage === 'confirm') {
                $this->delegate(false);
                $this->confirm($manager, $preview)->assertForbidden();
                self::assertSame('pending_review', AssistantEgressEnrollment::query()->findOrFail($preview['enrollment_id'])->status);
                continue;
            }
            $this->confirm($manager, $preview)->assertOk();
            $context = app(OrdinaryAssistantScopedContext::class);
            $context->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
            $context->assertPublication([]);
            if ($stage === 'membership') {
                $this->fixture->organization->users()->updateExistingPivot($manager->id, ['is_active' => false]);
            } else {
                $this->delegate(false);
            }
            if ($stage === 'publication' || $stage === 'membership') {
                $this->denied(fn () => $context->assertPublication([]));
            } else {
                $provider = $this->createMock(OrganizationAuthorityResponsesProvider::class);
                $provider->method('getModel')->willReturn('openai/gpt-6-luna');
                $provider->method('isAvailable')->willReturn(true);
                $provider->method('countTokens')->willReturn(100);
                $provider->expects(self::never())->method('responses');
                $provider->expects(self::never())->method('chat');
                $this->denied(fn () => AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id, $context)
                    ->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload));
            }
            $this->fixture->organization->users()->updateExistingPivot($manager->id, ['is_active' => true]);
        }
    }

    public function test_unexpected_assignment_failure_is_reported_safely_and_expected_http_statuses_are_preserved(): void
    {
        $this->postJson(self::BASE.'/cohorts', [])->assertUnprocessable();
        $this->deleteJson(self::BASE.'/cohorts/'.Str::uuid())->assertNotFound();
        $before = AssistantEgressCohort::query()->count();
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $prior = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ([false, true] as $httpFailure) {
                $events = new Dispatcher($this->app);
                $faults = 0;
                $events->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$faults, $httpFailure): void {
                    if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'ai_assistant_egress_cohorts')) {
                        $faults++;
                        $previous = new RuntimeException(self::PRIVATE_VALUE);
                        if ($httpFailure) { throw new HttpException(500, self::PRIVATE_VALUE, $previous); }
                        throw new RuntimeException(self::PRIVATE_VALUE, 0, $previous);
                    }
                });
                Exceptions::fake();
                $connection->setEventDispatcher($events);
                $response = $this->assignmentWithPrivateArgument(self::PRIVATE_VALUE)->assertStatus(500);
                self::assertSame(1, $faults);
                self::assertStringNotContainsString(self::PRIVATE_VALUE, $response->getContent());
                Exceptions::assertReportedCount(1);
                $reported = Exceptions::reported()[0];
                self::assertNull($reported->getPrevious());
                self::assertStringNotContainsString(self::PRIVATE_VALUE, (string) $reported);
                self::assertStringNotContainsString(self::PRIVATE_VALUE, $reported->getTraceAsString());
                self::assertStringNotContainsString(self::PRIVATE_VALUE, json_encode($reported->getTrace(), JSON_THROW_ON_ERROR));
                foreach ($reported->getTrace() as $frame) { self::assertArrayNotHasKey('args', $frame); }
            }
        } finally {
            if ($original === null) { $connection->unsetEventDispatcher(); }
            else { $connection->setEventDispatcher($original); }
            ini_set('zend.exception_ignore_args', $prior === false ? '1' : $prior);
        }
        self::assertSame($before, AssistantEgressCohort::query()->count());
    }

    public function test_manager_revocation_after_the_first_sdk_response_blocks_followup_and_retains_actual_usage(): void
    {
        $this->delegate(true);
        $manager = $this->fixture->member;
        $cohort = $this->assign($manager);
        [$payload, $preview] = $this->preview($manager, $cohort);
        $this->confirm($manager, $preview)->assertOk();
        $publisher = clone app(OrdinaryAssistantScopedContext::class);
        $publisher->enter($this->fixture->owner, $cohort->organization_id, $payload['message'], $payload, null);
        $publisher->assertPublication([]);
        $calls = 0;
        $provider = $this->createMock(OrganizationAuthorityResponsesProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->method('responses')->willReturnCallback(function () use (&$calls): array {
            self::assertSame(1, ++$calls);
            $this->delegate(false);
            $call = ['type' => 'function_call', 'id' => 'fc_b6_revoked', 'call_id' => 'call_b6_revoked', 'status' => 'completed',
                'name' => 'get_project_attention', 'arguments' => json_encode($this->sources()[0]['arguments'], JSON_THROW_ON_ERROR)];
            return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'input_tokens' => 100, 'output_tokens' => 20,
                'tokens_used' => 120, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'response_status' => 'completed'];
        });
        $this->denied(fn () => AssistantEgressFixtureService::forScope($provider, $this->fixture->owner, $cohort->organization_id,
            app(OrdinaryAssistantScopedContext::class))->ask($payload['message'], $cohort->organization_id, $this->fixture->owner, null, $payload));
        self::assertSame(1, $calls);
        $request = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
        self::assertSame('failed', $request->status);
        self::assertSame('assistant_egress_denied', $request->error_code);
        self::assertSame(0, Message::query()->where('conversation_id', $request->conversation_id)->where('role', 'assistant')->count());
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->sole();
        self::assertSame(100, $usage->metadata['input_tokens']);
        self::assertSame(20, $usage->metadata['output_tokens']);
        $this->denied(fn () => $publisher->assertPublication([]));
    }

    private function assignmentWithPrivateArgument(string $privateValue): TestResponse
    {
        self::assertSame(self::PRIVATE_VALUE, $privateValue);
        return $this->postJson(self::BASE.'/cohorts', $this->assignmentBody($this->fixture->owner));
    }

    private function assignmentBody(User $actor): array
    {
        return ['organization_id' => $this->fixture->organization->id, 'actor_id' => $actor->id,
            'project_id' => $this->project->id, 'tools' => ['get_project_attention']];
    }

    private function assign(User $operator, ?User $actor = null): AssistantEgressCohort
    {
        $response = $this->actingAs($operator)->postJson(self::BASE.'/cohorts', $this->assignmentBody($actor ?? $this->fixture->owner))->assertCreated();
        return AssistantEgressCohort::query()->findOrFail($response->json('data.cohort_id'));
    }

    private function quotedInput(User $actor): array
    {
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Покажи учебные сведения по графику проекта.',
            'conversation_id' => null, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $actor, $payload)['quote_id'];
        return [$payload];
    }

    private function preview(User $reviewer, AssistantEgressCohort $cohort): array
    {
        [$payload] = $this->quotedInput(User::query()->findOrFail($cohort->actor_id));
        $response = $this->actingAs($reviewer)->postJson(self::BASE.'/enrollments/preview', ['cohort_id' => $cohort->id,
            'quote_id' => $payload['quote_id'], 'input' => $this->previewInput($payload), 'sources' => $this->sources()])->assertCreated();
        return [$payload, $response->json('data')];
    }

    private function previewInput(array $payload): array
    {
        return array_intersect_key($payload, array_flip(['message', 'conversation_id', 'profile', 'allow_actions']));
    }

    private function confirm(User $reviewer, array $preview): TestResponse
    {
        return $this->actingAs($reviewer)->postJson(self::BASE.'/enrollments/'.$preview['enrollment_id'].'/confirm',
            ['manifest_hash' => $preview['manifest_hash'], 'declaration' => OrdinaryAssistantEnrollmentService::DECLARATION]);
    }

    private function sources(): array
    {
        return [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'schedule', 'limit' => 3]]];
    }

    private function delegate(bool $enabled): void
    {
        $this->fixture->memberRole->update(['system_permissions' => [], 'module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat', ...($enabled ? [OrdinaryAssistantEnrollmentService::ORGANIZATION_PERMISSION] : [])],
            'project-management' => ['projects.view'],
            'schedule-management' => ['schedule.view'],
        ]]);
    }

    private function denied(callable $operation, string $reason = 'access_denied'): AssistantEgressDenied
    {
        try { $operation(); }
        catch (AssistantEgressDenied $denied) { self::assertSame($reason, $denied->reason); return $denied; }
        self::fail('Organization authority boundary accepted an unauthorized operation');
    }
}

interface OrganizationAuthorityResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
