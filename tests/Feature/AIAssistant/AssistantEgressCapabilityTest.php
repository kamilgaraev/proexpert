<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;
use Throwable;

final class AssistantEgressCapabilityTest extends TestCase
{
    private const PATH = '/api/v1/admin/ai-assistant/egress/capability';
    private const PRIVATE_VALUE = 'B5_PRIVATE_PROVIDER_AND_CALLER_CANARY';
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private OrdinaryAssistantEnrollmentService $enrollments;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 12:00:00');
        Queue::fake();
        Http::preventStrayRequests();
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true]);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->memberRole->update(['system_permissions' => ['projects.view']]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        AuthorizationContext::getProjectContext($this->project->id, $this->fixture->organization->id);
        $this->project->users()->syncWithoutDetaching([$this->fixture->member->id => ['role' => 'member', 'is_active' => true,
            'assigned_by_user_id' => $this->fixture->owner->id, 'assigned_at' => now()]]);
        $tools = $this->createMock(AIToolRegistry::class);
        $tools->expects(self::never())->method('getTool');
        $this->app->instance(AIToolRegistry::class, $tools);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects(self::never())->method('chat');
        $this->app->instance(LLMProviderInterface::class, $provider);
        $embedding = $this->createMock(RagEmbeddingProviderInterface::class);
        $embedding->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $embedding);
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->withoutMiddleware();
        $this->actingAs($this->fixture->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_metadata_is_self_scoped_closed_and_read_only_on_existing_authenticated_surfaces(): void
    {
        $this->cohort($this->fixture->foreignOwner);
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.mode', 'legacy')->assertJsonPath('data.cohort', null);
        $cohort = $this->cohort($this->fixture->owner);
        $assignedTools = [...OrdinaryAssistantSourceProjection::TOOLS, \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection::TOOL];
        $cohort->update(['policy_version' => OrdinaryAssistantSourceProjection::MIXED_V2_VERSION, 'allowed_tools' => $assignedTools]);
        $before = [$cohort->refresh()->getRawOriginal(), AssistantEgressEnrollment::query()->count()];
        $queries = [];
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = new Dispatcher($this->app);
        $events->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void { $queries[] = $query->sql; });
        $connection->setEventDispatcher($events);
        try {
            foreach ([self::PATH, '/api/v1/ai-assistant/egress/capability', '/api/v1/mobile/ai-assistant/egress/capability'] as $path) {
                $response = $this->getJson($path)->assertOk()->assertJsonPath('data.mode', 'guarded')
                    ->assertJsonPath('data.state', 'review_required')->assertJsonPath('data.can_review_sources', true)
                    ->assertJsonPath('data.cohort.cohort_id', $cohort->id);
                self::assertEqualsCanonicalizing(['mode', 'state', 'can_review_sources', 'cohort'], array_keys($response->json('data')));
                self::assertEqualsCanonicalizing(['cohort_id', 'project_id', 'policy_version', 'allowed_tools', 'expires_at'], array_keys($response->json('data.cohort')));
                self::assertSame($assignedTools, $response->json('data.cohort.allowed_tools'));
                self::assertSame(OrdinaryAssistantSourceProjection::MIXED_V2_VERSION, $response->json('data.cohort.policy_version'));
                self::assertSame((int) $this->project->id, $response->json('data.cohort.project_id'));
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
                self::assertStringNotContainsString(self::PRIVATE_VALUE, $response->getContent());
            }
        } finally {
            if ($original === null) { $connection->unsetEventDispatcher(); }
            else { $connection->setEventDispatcher($original); }
        }
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|truncate)\s/i', $sql);
            self::assertStringNotContainsString('ai_assistant_egress_enrollments', $sql);
            self::assertStringNotContainsString('ai_assistant_documents', $sql);
            self::assertStringNotContainsString('ai_rag_', $sql);
        }
        self::assertSame($before, [$cohort->refresh()->getRawOriginal(), AssistantEgressEnrollment::query()->count()]);
        $this->expectException(AssistantEgressDenied::class);
        app(OrdinaryAssistantScopedContext::class)->enter($this->fixture->owner, $cohort->organization_id, 'Учебный вопрос', ['message' => 'Учебный вопрос'], null);
    }

    public function test_selected_invalid_states_never_advertise_legacy_or_return_source_metadata(): void
    {
        $cohort = $this->cohort($this->fixture->owner);
        $original = ['status' => 'active', 'expires_at' => now()->addHour(), 'policy_version' => OrdinaryAssistantSourceProjection::VERSION,
            'generation' => $cohort->generation, 'allowed_tools' => ['get_project_attention']];
        foreach ([['status' => 'revoked'], ['expires_at' => now()->subMinute()], ['policy_version' => 'unknown/1'],
            ['allowed_tools' => ['unknown_tool']]] as $invalid) {
            $cohort->update($original);
            $cohort->update($invalid);
            $this->getJson(self::PATH)->assertOk()->assertExactJson(['success' => true, 'message' => null,
                'data' => ['mode' => 'guarded', 'state' => 'unavailable', 'can_review_sources' => false, 'cohort' => null]]);
        }
    }

    public function test_current_membership_permissions_and_project_acl_are_rechecked_for_each_discovery(): void
    {
        $member = $this->fixture->member;
        $this->cohort($member);
        $this->actingAs($member);
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.state', 'review_required')->assertJsonPath('data.can_review_sources', false);
        $this->project->users()->updateExistingPivot($member->id, ['is_active' => false]);
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.cohort', null);
        $this->project->users()->updateExistingPivot($member->id, ['is_active' => true]);
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.state', 'review_required');
        $this->fixture->memberAssignment->update(['is_active' => false]);
        $this->getJson(self::PATH)->assertForbidden();
        $this->fixture->memberAssignment->update(['is_active' => true]);
        $member->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => false]);
        $this->getJson(self::PATH)->assertForbidden();
        $member->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => true]);
        $member->updateQuietly(['is_active' => false]);
        $this->getJson(self::PATH)->assertForbidden();
        $this->actingAs($this->fixture->owner);
        $this->cohort($this->fixture->owner);
        $this->fixture->owner->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_owner' => false]);
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.can_review_sources', false);
    }

    public function test_filters_and_foreign_context_cannot_select_another_actor_or_grant_platform_authority(): void
    {
        $foreign = $this->cohort($this->fixture->foreignOwner);
        foreach (['actor_id' => $this->fixture->foreignOwner->id, 'organization_id' => $foreign->organization_id,
            'cohort_id' => $foreign->id, 'project_id' => $foreign->project_id, 'unknown' => 'value'] as $key => $value) {
            $this->getJson(self::PATH.'?'.http_build_query([$key => $value]))->assertForbidden();
        }
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.mode', 'legacy');
        try {
            $this->enrollments->capability($this->fixture->owner, $this->fixture->foreignOrganization->id);
            self::fail('A foreign organization was discoverable');
        } catch (AssistantEgressDenied) {}
        $this->postJson('/api/v1/admin/ai-assistant/egress/cohorts', ['organization_id' => $this->fixture->organization->id,
            'actor_id' => $this->fixture->owner->id, 'project_id' => $this->project->id, 'tools' => ['get_project_attention']])->assertForbidden();
        self::assertSame(1, AssistantEgressCohort::query()->count());
        self::assertSame(0, AssistantEgressEnrollment::query()->count());
    }

    public function test_unexpected_storage_failure_is_safe_at_service_and_actual_controller_without_legacy_fallback(): void
    {
        Exceptions::fake();
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = new Dispatcher($this->app);
        $faults = 0;
        $events->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$faults): void {
            if (str_contains($query->sql, 'ai_assistant_egress_cohorts')) {
                $faults++;
                throw new \RuntimeException(self::PRIVATE_VALUE, 0, new \RuntimeException(self::PRIVATE_VALUE));
            }
        });
        $connection->setEventDispatcher($events);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                $this->discoverWithPrivateArgument(self::PRIVATE_VALUE, false);
                self::fail('A database fault became a successful capability');
            } catch (\RuntimeException $failure) {
                $this->assertSafe($failure);
            }
            $response = $this->discoverWithPrivateArgument(self::PRIVATE_VALUE, true)->assertStatus(500)->assertJsonPath('message', trans_message('ai_assistant.request_failed'));
            self::assertStringNotContainsString(self::PRIVATE_VALUE, $response->getContent());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
            if ($original === null) { $connection->unsetEventDispatcher(); }
            else { $connection->setEventDispatcher($original); }
        }
        self::assertSame(2, $faults);
        Exceptions::assertReportedCount(1);
        $this->assertSafe(Exceptions::reported()[0]);
        self::assertSame(0, AssistantEgressCohort::query()->count());
        self::assertSame(0, AssistantEgressEnrollment::query()->count());
    }

    private function assertSafe(Throwable $failure): void
    {
        self::assertNull($failure->getPrevious());
        self::assertStringNotContainsString(self::PRIVATE_VALUE, (string) $failure);
        self::assertStringNotContainsString(self::PRIVATE_VALUE, $failure->getTraceAsString());
        self::assertStringNotContainsString(self::PRIVATE_VALUE, json_encode($failure->getTrace(), JSON_THROW_ON_ERROR));
        self::assertSame([], array_filter($failure->getTrace(), static fn (array $frame): bool => array_key_exists('args', $frame)));
    }

    private function discoverWithPrivateArgument(string $privateValue, bool $http): mixed
    {
        self::assertSame(self::PRIVATE_VALUE, $privateValue);
        return $http ? $this->getJson(self::PATH) : $this->enrollments->capability($this->fixture->owner, $this->fixture->organization->id);
    }

    private function cohort(User $actor): AssistantEgressCohort
    {
        return AssistantEgressCohort::query()->create(['id' => (string) Str::uuid(), 'generation' => (string) Str::uuid(),
            'organization_id' => $actor->current_organization_id, 'actor_id' => $actor->id, 'project_id' => $this->project->id,
            'assigned_by' => $this->fixture->owner->id, 'status' => 'active', 'policy_version' => OrdinaryAssistantSourceProjection::VERSION,
            'provider' => 'offline', 'model' => self::PRIVATE_VALUE, 'endpoint' => self::PRIVATE_VALUE,
            'allowed_tools' => ['get_project_attention'], 'expires_at' => now()->addHour()]);
    }
}
