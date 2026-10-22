<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetOrganizationHandoverControlTool;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationHandoverControlReader;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceControlReadService;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOrganizationHandoverControlTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private Project $first;

    private Project $second;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Queue::fake();
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->first = $this->project('Первый объект');
        $this->second = $this->project('Второй объект');
        $this->actingAs($this->fixture->owner);
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::ADMIN);
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_native_statuses_dates_and_open_finding_counts_are_scoped_before_the_limit(): void
    {
        $first = $this->scope($this->first, ['planned_acceptance_date' => '2026-11-02']);
        $second = $this->scope($this->second, ['status' => 'ready_for_reinspection']);
        foreach (['accepted', 'handed_over'] as $status) {
            $this->scope($this->first, ['title' => 'SECRET завершённая зона', 'status' => $status]);
        }
        $this->scope($this->first, ['title' => 'SECRET удалённая зона'])->delete();
        $this->scope($this->first, ['organization_id' => $this->fixture->foreignOrganization->id, 'title' => 'SECRET другая организация']);
        $foreign = Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id]));
        $this->scope($foreign, ['organization_id' => $this->fixture->organization->id, 'title' => 'SECRET чужой проект']);
        $open = $this->finding($first);
        $this->finding($first, ['status' => 'resolved']);
        $this->finding($first)->delete();
        $this->finding($first, ['organization_id' => $this->fixture->foreignOrganization->id]);
        $this->finding($first, ['project_id' => $this->second->id]);
        $before = [AcceptanceScope::withTrashed()->count(), AcceptanceFinding::withTrashed()->count()];
        Queue::fake();
        $read = $this->read($this->fixture->owner);
        self::assertSame(2, $read['data']['total_count']);
        self::assertSame([$first->id, $second->id], array_column($read['data']['rows'], 'id'));
        self::assertSame([1, 0], array_column($read['data']['rows'], 'open_findings_count'));
        self::assertSame(['2026-11-02', null], array_column($read['data']['rows'], 'planned_acceptance_date'));
        self::assertStringContainsString('Второй объект', $read['server_formatted_answer']);
        self::assertStringContainsString('Готова к повторной проверке', $read['server_formatted_answer']);
        self::assertStringContainsString('Не указана', $read['server_formatted_answer']);
        self::assertStringNotContainsString('SECRET', json_encode($read, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertNull($read['source_refs'][0]['project_id']);
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $open->updateQuietly(['status' => 'resolved']);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        self::assertSame($before, [AcceptanceScope::withTrashed()->count(), AcceptanceFinding::withTrashed()->count()]);
        Queue::assertNothingPushed();
    }

    public function test_full_count_precedes_the_fifty_row_cap_and_empty_data_is_explicit(): void
    {
        self::assertSame(0, $this->read($this->fixture->owner)['data']['total_count']);
        foreach (range(1, 53) as $index) {
            $this->scope($this->first, ['title' => 'Зона '.$index]);
        }
        $read = $this->read($this->fixture->owner);
        self::assertSame(53, $read['data']['total_count']);
        self::assertCount(50, $read['data']['rows']);
        self::assertTrue($read['data']['truncated']);
        self::assertStringContainsString('50 из 53', $read['server_formatted_answer']);
    }

    public function test_assigned_projects_and_fresh_membership_are_enforced_by_reader_and_public_service(): void
    {
        $actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat', 'ai_assistant.analytics.view'],
            'handover-acceptance' => ['handover-acceptance.view']], ['projects.view']);
        $actor->assignedProjects()->attach($this->first->id, ['is_active' => true, 'role' => 'member']);
        $first = $this->scope($this->first);
        $this->scope($this->second, ['title' => 'SECRET неназначенный проект']);
        self::assertTrue(app(AIPermissionChecker::class)->canExposeTool($actor, GetOrganizationHandoverControlTool::NAME));
        $read = $this->read($actor);
        self::assertSame([$first->id], array_column($read['data']['rows'], 'id'));
        $public = app(HandoverAcceptanceControlReadService::class);
        self::assertSame([$first->id], $public->query($actor, $this->fixture->organization->id, AcceptanceScope::query())->pluck('acceptance_scopes.id')->all());
        $actor->assignedProjects()->updateExistingPivot($this->first->id, ['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($actor, $this->fixture->organization->id, $read['source_refs']));
        self::assertSame(0, $this->read($actor)['data']['total_count']);
        $actor->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => false]);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($actor, GetOrganizationHandoverControlTool::NAME));
        $this->expectException(AuthorizationException::class);
        $public->query($actor, $this->fixture->organization->id, AcceptanceScope::query())->get();
    }

    public function test_missing_permission_denies_exposure_and_actual_tool_execution(): void
    {
        $tool = app(AIToolRegistry::class)->getTool(GetOrganizationHandoverControlTool::NAME);
        self::assertInstanceOf(GetOrganizationHandoverControlTool::class, $tool);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($this->fixture->member, $tool->getName()));
        $this->expectException(AuthorizationException::class);
        $tool->execute(['scope' => 'organization'], $this->fixture->member, $this->fixture->organization);
    }

    public function test_foreign_actor_is_rejected_by_public_service_without_an_assistant_wrapper(): void
    {
        $this->expectException(AuthorizationException::class);
        app(HandoverAcceptanceControlReadService::class)->query($this->fixture->foreignOwner, $this->fixture->organization->id, AcceptanceScope::query())->get();
    }

    public function test_role_revocation_invalidates_sources_and_denies_the_public_domain_service(): void
    {
        $this->scope($this->first);
        $read = $this->read($this->fixture->owner);
        $this->fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($this->fixture->owner, GetOrganizationHandoverControlTool::NAME));
        $this->expectException(AuthorizationException::class);
        app(HandoverAcceptanceControlReadService::class)->query($this->fixture->owner, $this->fixture->organization->id, AcceptanceScope::query())->get();
    }

    public function test_organization_tool_rejects_an_injected_ui_project_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(GetOrganizationHandoverControlTool::class)->execute(['scope' => 'organization', 'project_id' => $this->first->id],
            $this->fixture->owner, $this->fixture->organization);
    }

    public function test_exact_question_uses_an_organization_source_and_verified_server_answer(): void
    {
        $this->scope($this->first);
        $this->scope($this->second, ['title' => 'Зона второго проекта']);
        $this->scope($this->first, ['title' => 'SECRET принятая зона', 'status' => 'accepted']);
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        config(['ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true]);
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'handover-'.Str::uuid());
        $provider = $this->createMock(HandoverControlResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $calls = 0;
        $provider->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            self::assertLessThanOrEqual(2, ++$calls);
            $output = [];
            if ($calls === 1) {
                self::assertSame(GetOrganizationHandoverControlTool::NAME, $options['tool_choice']['function']['name'] ?? null);
                $output[] = ['type' => 'function_call', 'id' => 'handover-control-read', 'call_id' => 'handover-control-read', 'status' => 'completed',
                    'name' => GetOrganizationHandoverControlTool::NAME, 'arguments' => '{"scope":"organization"}'];
            }

            return ['content' => $calls === 1 ? '' : 'Непроверенный текст модели.', 'output' => $output, 'function_calls' => $output,
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $question = 'Какие приёмки по всей организации ещё не приняты и не переданы? По каждой укажи проект, название, текущий статус, плановую дату и число открытых замечаний. Закрытые замечания не считай. Не ограничивайся выбранным проектом.';
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $question, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => $this->first->id]]]];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $actor, $payload)['quote_id'];
        $queue = Queue::fake();
        $response = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null])
            ->ask($question, $org->id, $actor, null, $payload, 'admin');
        $metadata = $response['message']['metadata'];
        self::assertFalse($metadata['needs_clarification'], json_encode(['content' => $response['message']['content'],
            'source_refs' => $metadata['source_refs'], 'tool_calls' => $metadata['tool_calls'] ?? null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertSame('partial', $metadata['validation_status']);
        self::assertSame([], $metadata['artifacts']);
        self::assertCount(1, $metadata['source_refs']);
        self::assertSame(AssistantOrganizationHandoverControlReader::TYPE, $metadata['source_refs'][0]['entity_type']);
        self::assertNull($metadata['source_refs'][0]['project_id']);
        self::assertStringContainsString('Второй объект', $response['message']['content']);
        self::assertStringContainsString('ещё не приняты и не переданы: 2', $response['message']['content']);
        self::assertStringNotContainsString('Непроверенный текст', $response['message']['content']);
        self::assertStringNotContainsString('SECRET', $response['message']['content']);
        self::assertSame([], array_diff(array_keys($queue->pushedJobs()), [BroadcastEvent::class]));
    }

    private function read(User $actor): array
    {
        return app(AssistantOrganizationHandoverControlReader::class)->read($actor, $this->fixture->organization->id, ['scope' => 'organization']);
    }

    private function project(string $name): Project
    {
        return Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => $name, 'is_archived' => false]));
    }

    private function scope(Project $project, array $overrides = []): AcceptanceScope
    {
        return Model::withoutEvents(fn () => AcceptanceScope::query()->create(array_replace([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'title' => 'Зона приёмки', 'status' => 'in_progress', 'planned_acceptance_date' => null,
            'created_by_user_id' => $this->fixture->owner->id,
        ], $overrides)));
    }

    private function finding(AcceptanceScope $scope, array $overrides = []): AcceptanceFinding
    {
        return Model::withoutEvents(fn () => AcceptanceFinding::query()->create(array_replace([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
            'acceptance_scope_id' => $scope->id, 'title' => 'Замечание', 'status' => 'open', 'severity' => 'minor',
            'created_by_user_id' => $this->fixture->owner->id,
        ], $overrides)));
    }
}

interface HandoverControlResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
