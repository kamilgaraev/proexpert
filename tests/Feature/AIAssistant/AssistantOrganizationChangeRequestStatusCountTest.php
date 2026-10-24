<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetOrganizationChangeRequestStatusCountsTool;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationChangeRequestStatusCountReader;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeRequestStatusCountReadService;
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

final class AssistantOrganizationChangeRequestStatusCountTest extends TestCase
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

    public function test_all_current_statuses_include_drafts_and_exclude_deleted_foreign_and_inaccessible_records(): void
    {
        $draft = $this->change($this->first);
        $this->change($this->second);
        foreach (['submitted', 'impact_assessment', 'internal_review', 'customer_review', 'approved', 'implemented', 'closed', 'rejected', 'cancelled'] as $status) {
            $this->change($this->second, ['status' => $status]);
        }
        $this->change($this->first, ['status' => 'SECRET_deleted'])->delete();
        $this->change($this->first, ['organization_id' => $this->fixture->foreignOrganization->id, 'status' => 'SECRET_foreign']);
        $foreign = Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id]));
        $this->change($foreign, ['organization_id' => $this->fixture->organization->id, 'status' => 'SECRET_project']);
        $before = ChangeRequest::withTrashed()->count();
        Queue::fake();
        $read = $this->read($this->fixture->owner);
        self::assertSame(11, $read['data']['total_count']);
        self::assertSame(10, $read['data']['group_count']);
        $counts = array_column($read['data']['rows'], 'count', 'status');
        self::assertSame(2, $counts['draft']);
        self::assertSame(1, $counts['closed']);
        self::assertSame(11, array_sum($counts));
        self::assertStringContainsString('| Черновик | 2 |', $read['server_formatted_answer']);
        self::assertStringContainsString('| У заказчика | 1 |', $read['server_formatted_answer']);
        self::assertStringNotContainsString('SECRET', json_encode($read, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertNull($read['source_refs'][0]['project_id']);
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        $draft->updateQuietly(['status' => 'approved']);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        self::assertSame($before, ChangeRequest::withTrashed()->count());
        Queue::assertNothingPushed();
    }

    public function test_zero_is_explicit_and_total_count_precedes_the_group_limit(): void
    {
        $empty = $this->read($this->fixture->owner);
        self::assertSame(0, $empty['data']['total_count']);
        self::assertSame([], $empty['data']['rows']);
        self::assertStringContainsString('Доступных запросов на изменения нет', $empty['server_formatted_answer']);
        foreach (range(1, 103) as $index) {
            $this->change($this->first, ['status' => 'legacy_'.$index]);
        }
        $this->change($this->second, ['status' => 'legacy_1']);
        $read = $this->read($this->fixture->owner);
        self::assertSame(104, $read['data']['total_count']);
        self::assertSame(103, $read['data']['group_count']);
        self::assertCount(100, $read['data']['rows']);
        self::assertTrue($read['data']['truncated']);
        self::assertStringContainsString('100 из 103', $read['server_formatted_answer']);
        self::assertStringContainsString('legacy\\_1', $read['server_formatted_answer']);
    }

    public function test_assigned_projects_and_fresh_membership_apply_to_reader_and_public_service(): void
    {
        $actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat', 'ai_assistant.analytics.view'],
            'change-management' => ['change-management.view']], ['projects.view']);
        $actor->assignedProjects()->attach($this->first->id, ['is_active' => true, 'role' => 'member']);
        $this->change($this->first);
        $this->change($this->second, ['status' => 'approved']);
        self::assertTrue(app(AIPermissionChecker::class)->canExposeTool($actor, GetOrganizationChangeRequestStatusCountsTool::NAME));
        $read = $this->read($actor);
        self::assertSame([['status' => 'draft', 'count' => 1]], $read['data']['rows']);
        $public = app(ChangeRequestStatusCountReadService::class);
        self::assertSame(1, (int) $public->query($actor, $this->fixture->organization->id, ChangeRequest::query())->toBase()->first()->total_count);
        $actor->assignedProjects()->updateExistingPivot($this->first->id, ['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($actor, $this->fixture->organization->id, $read['source_refs']));
        self::assertSame(0, $this->read($actor)['data']['total_count']);
        $actor->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => false]);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($actor, GetOrganizationChangeRequestStatusCountsTool::NAME));
        $this->expectException(AuthorizationException::class);
        $public->query($actor, $this->fixture->organization->id, ChangeRequest::query())->get();
    }

    public function test_missing_permission_denies_exposure_and_actual_tool_execution(): void
    {
        $tool = app(AIToolRegistry::class)->getTool(GetOrganizationChangeRequestStatusCountsTool::NAME);
        self::assertInstanceOf(GetOrganizationChangeRequestStatusCountsTool::class, $tool);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($this->fixture->member, $tool->getName()));
        $this->expectException(AuthorizationException::class);
        $tool->execute(['scope' => 'organization'], $this->fixture->member, $this->fixture->organization);
    }

    public function test_foreign_actor_is_rejected_by_public_service_without_an_assistant_wrapper(): void
    {
        $this->expectException(AuthorizationException::class);
        app(ChangeRequestStatusCountReadService::class)->query($this->fixture->foreignOwner, $this->fixture->organization->id, ChangeRequest::query())->get();
    }

    public function test_role_revocation_invalidates_sources_and_denies_the_public_service(): void
    {
        $this->change($this->first);
        $read = $this->read($this->fixture->owner);
        $this->fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($this->fixture->owner, GetOrganizationChangeRequestStatusCountsTool::NAME));
        $this->expectException(AuthorizationException::class);
        app(ChangeRequestStatusCountReadService::class)->query($this->fixture->owner, $this->fixture->organization->id, ChangeRequest::query())->get();
    }

    public function test_organization_tool_rejects_an_injected_ui_project_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(GetOrganizationChangeRequestStatusCountsTool::class)->execute(['scope' => 'organization', 'project_id' => $this->first->id],
            $this->fixture->owner, $this->fixture->organization);
    }

    public function test_exact_question_uses_an_organization_source_and_verified_server_answer(): void
    {
        $this->change($this->first);
        $this->change($this->second);
        $this->change($this->second, ['status' => 'approved']);
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        config(['ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true]);
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'change-count-'.Str::uuid());
        $provider = $this->createMock(ChangeRequestCountsResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $calls = 0;
        $provider->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            self::assertLessThanOrEqual(2, ++$calls);
            $output = [];
            if ($calls === 1) {
                self::assertSame(GetOrganizationChangeRequestStatusCountsTool::NAME, $options['tool_choice']['function']['name'] ?? null);
                $output[] = ['type' => 'function_call', 'id' => 'change-status-count', 'call_id' => 'change-status-count', 'status' => 'completed',
                    'name' => GetOrganizationChangeRequestStatusCountsTool::NAME, 'arguments' => '{"scope":"organization"}'];
            }

            return ['content' => $calls === 1 ? '' : 'Непроверенный текст модели.', 'output' => $output, 'function_calls' => $output,
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $question = 'Посчитай запросы на изменения по всей организации по текущим статусам, включая черновики. Если запросов нет, скажи об этом прямо. Не ограничивайся выбранным проектом.';
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
        self::assertSame(AssistantOrganizationChangeRequestStatusCountReader::TYPE, $metadata['source_refs'][0]['entity_type']);
        self::assertNull($metadata['source_refs'][0]['project_id']);
        self::assertStringContainsString('Всего доступных запросов на изменения: 3', $response['message']['content']);
        self::assertStringContainsString('| Черновик | 2 |', $response['message']['content']);
        self::assertStringNotContainsString('Непроверенный текст', $response['message']['content']);
        self::assertSame([], array_diff(array_keys($queue->pushedJobs()), [BroadcastEvent::class]));
    }

    private function read(User $actor): array
    {
        return app(AssistantOrganizationChangeRequestStatusCountReader::class)->read($actor, $this->fixture->organization->id, ['scope' => 'organization']);
    }

    private function project(string $name): Project
    {
        return Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => $name, 'is_archived' => false]));
    }

    private function change(Project $project, array $overrides = []): ChangeRequest
    {
        return Model::withoutEvents(fn () => ChangeRequest::query()->create(array_replace([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'change_number' => 'CH-'.Str::uuid(), 'title' => 'Запрос на изменение', 'reason' => 'Уточнение',
            'description' => 'Работы', 'initiator_type' => 'contractor', 'status' => 'draft', 'reporting_currency' => 'RUB',
            'created_by_user_id' => $this->fixture->owner->id,
        ], $overrides)));
    }
}

interface ChangeRequestCountsResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
