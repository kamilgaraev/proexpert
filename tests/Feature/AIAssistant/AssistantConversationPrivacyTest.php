<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Console\Commands\PurgeAssistantRetentionCommand;
use App\BusinessModules\Features\AIAssistant\Http\Requests\StoreAssistantMemoryRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\UpdateAssistantMemoryRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\ConversationSummary;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRetentionService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ReportFile;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

final class AssistantConversationPrivacyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private ConversationManager $manager;

    private AssistantMemoryService $memories;

    private AssistantDataAccessPolicy $policy;

    private Organization $organization;

    private User $owner;

    private User $viewer;

    private array $denied = [];

    private bool $assistantEnabled = true;

    private bool $financeAllowed = true;

    private int $moduleReads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->owner = $this->member();
        $this->viewer = $this->member();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => $this->financeAllowed || (! str_starts_with($permission, 'finance.') && ! str_starts_with($permission, 'payments.')));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->andReturnUsing(fn (User $actor, int $organizationId): bool => $this->assistantEnabled);
        $this->app->instance(AIPermissionChecker::class, $permissions);
        $projects = Mockery::mock(UserProjectAccessService::class);
        $projects->shouldReceive('canAccessProject')->andReturnUsing(fn (User $actor, Project $project): bool => ! in_array($actor->id.':'.$project->id, $this->denied, true));
        $projects->shouldReceive('queryAccessibleProjects')->andReturnUsing(function (User $actor, int $organizationId) {
            $deniedIds = array_map(static fn (string $value): int => (int) explode(':', $value)[1], array_filter($this->denied, static fn (string $value): bool => str_starts_with($value, $actor->id.':')));

            return Project::query()->where('organization_id', $organizationId)->whereNotIn('id', $deniedIds);
        });
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(function () {
            $this->moduleReads++;

            return collect([['slug' => 'ai-assistant'], ['slug' => 'project-management'], ['slug' => 'payments']]);
        });
        $policy = new AssistantDataAccessPolicy($authorization, $projects, $modules);
        $this->policy = $policy;
        $this->manager = new ConversationManager($policy);
        $this->memories = new AssistantMemoryService($policy, $this->manager);
        $this->app->instance(ConversationManager::class, $this->manager);
    }

    public function test_private_conversations_require_explicit_sharing_and_current_membership(): void
    {
        $conversation = $this->conversation();
        $this->assertNull($this->manager->findAccessibleConversation($conversation->id, $this->viewer, $this->organization->id));
        $this->assertSame(0, $this->manager->queryVisibleConversations($this->viewer, $this->organization->id)->count());
        $this->share($conversation);
        $this->assertNotNull($this->manager->findAccessibleConversation($conversation->id, $this->viewer, $this->organization->id));
        $this->assertNull($this->manager->findAccessibleConversation($conversation->id, $this->viewer, $this->organization->id, true));
        DB::table('organization_user')->where('organization_id', $this->organization->id)->where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $this->assertNull($this->manager->findAccessibleConversation($conversation->id, $this->viewer, $this->organization->id));
    }

    public function test_list_previews_share_current_access_checks_only_within_one_response(): void
    {
        $this->app->instance(AssistantDataAccessPolicy::class, $this->policy);
        $this->app->instance(AIPermissionChecker::class, new AIPermissionChecker());
        for ($i = 0; $i < 30; $i++) {
            $conversation = $this->conversation();
            $this->manager->addMessage($conversation, 'user', 'Сообщение '.$i);
        }
        $page = $this->manager->queryVisibleConversations($this->owner, $this->organization->id)->get();
        $request = \Illuminate\Http\Request::create('/api/v1/admin/ai-assistant/conversations');
        $request->setUserResolver(fn () => $this->owner);
        $this->moduleReads = 0;
        $rows = \App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource::collection($page)->resolve($request);
        $this->assertCount(30, $rows);
        $this->assertSame(1, $this->moduleReads);
        $this->assertNotNull($rows[0]['last_message_preview']);

        DB::table('organization_user')->where('organization_id', $this->organization->id)->where('user_id', $this->owner->id)->update(['is_active' => false]);
        $nextRows = \App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource::collection($page)->resolve($request);
        $this->assertSame(array_fill(0, 30, null), array_column($nextRows, 'last_message_preview'));
    }

    public function test_list_previews_recheck_sources_and_sharing_between_responses(): void
    {
        $this->app->instance(AssistantDataAccessPolicy::class, $this->policy);
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $conversation = $this->conversation();
        $this->share($conversation);
        $this->manager->addMessage($conversation, 'assistant', 'Доступный ответ', metadata: ['validation_status' => 'verified', 'source_refs' => [$this->ref($project)]]);
        $page = $this->manager->queryVisibleConversations($this->viewer, $this->organization->id)->get();
        $request = \Illuminate\Http\Request::create('/api/v1/admin/ai-assistant/conversations');
        $request->setUserResolver(fn () => $this->viewer);
        $rows = \App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource::collection($page)->resolve($request);
        $this->assertSame('Доступный ответ', $rows[0]['last_message_preview']);

        $this->denied[] = $this->viewer->id.':'.$project->id;
        $nextRows = \App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource::collection($page)->resolve($request);
        $this->assertNull($nextRows[0]['last_message_preview']);
        $this->manager->updateParticipants($conversation, $this->owner, $this->organization->id, []);
        $this->assertSame(0, $this->manager->queryVisibleConversations($this->viewer, $this->organization->id)->count());
    }

    public function test_context_update_merges_fresh_context_and_increments_version_without_activity_touch(): void
    {
        $conversation = $this->conversation();
        $activity = now()->subDay()->startOfSecond();
        $conversation->forceFill(['last_activity_at' => $activity])->save();
        DB::table('ai_conversations')->where('id', $conversation->id)->update(['context' => json_encode([
            'unrelated' => 'fresh',
            'selected_estimate' => ['estimate_id' => 42, 'position_filter' => ['бетон'], 'position_numbers' => [4]],
        ], JSON_THROW_ON_ERROR)]);
        $version = (int) $conversation->context_version;

        $this->manager->updateContext($conversation, $this->owner, (int) $this->organization->id, ['last_task_type' => 'financial']);
        $this->assertSame(['estimate_id' => 42, 'position_filter' => ['бетон'], 'position_numbers' => [4]], $conversation->refresh()->context['selected_estimate']);
        $this->manager->updateContext($conversation, $this->owner, (int) $this->organization->id, ['selected_estimate' => ['estimate_id' => 42, 'position_filter' => [], 'position_numbers' => []]]);

        $saved = $conversation->refresh();
        $this->assertSame(['unrelated' => 'fresh', 'selected_estimate' => ['estimate_id' => 42, 'position_filter' => [], 'position_numbers' => []], 'last_task_type' => 'financial'], $saved->context);
        $this->assertSame($version + 2, (int) $saved->context_version);
        $this->assertTrue($saved->last_activity_at->equalTo($activity));
    }

    public function test_context_update_requires_current_organization_and_editor_access(): void
    {
        $conversation = $this->conversation();
        $this->share($conversation);
        foreach ([
            fn () => $this->manager->updateContext($conversation, $this->viewer, (int) $this->organization->id, ['selected_estimate' => ['estimate_id' => 99]]),
            fn () => $this->manager->updateContext($conversation, $this->owner, (int) $this->organization->id + 1, ['selected_estimate' => ['estimate_id' => 99]]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Context updates must require current-organization editor access.');
            } catch (RuntimeException) {
            }
        }

        $this->manager->updateParticipants($conversation, $this->owner, (int) $this->organization->id, [['user_id' => $this->viewer->id, 'role' => 'editor']]);
        $this->manager->updateParticipants($conversation, $this->owner, (int) $this->organization->id, []);
        try {
            $this->manager->updateContext($conversation, $this->viewer, (int) $this->organization->id, ['selected_estimate' => ['estimate_id' => 99]]);
            $this->fail('Revoked editors must not update context.');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $conversation->refresh()->context);

        $otherOrganization = Organization::factory()->create();
        $this->owner->organizations()->attach($otherOrganization->id, ['is_active' => true]);
        DB::table('users')->where('id', $this->owner->id)->update(['current_organization_id' => $otherOrganization->id]);
        try {
            $this->manager->updateContext($conversation, $this->owner, (int) $this->organization->id, ['selected_estimate' => ['estimate_id' => 99]]);
            $this->fail('A stale actor model must not authorize an update after its current organization changed.');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $conversation->refresh()->context);
    }

    public function test_only_owner_shares_and_viewer_cannot_edit_or_keep_revoked_access(): void
    {
        $conversation = $this->conversation();
        $this->share($conversation);
        try {
            $this->manager->updateParticipants($conversation, $this->viewer, $this->organization->id, []);
            $this->fail('Viewer must not change participants.');
        } catch (RuntimeException) {
        }
        $this->manager->updateParticipants($conversation, $this->owner, $this->organization->id, [['user_id' => $this->viewer->id, 'role' => 'editor']]);
        $this->assertNotNull($this->manager->findAccessibleConversation($conversation->id, $this->viewer, $this->organization->id, true));
        $this->manager->updateParticipants($conversation, $this->owner, $this->organization->id, []);
        $this->assertNull($this->manager->findAccessibleConversation($conversation->id, $this->viewer, $this->organization->id));
        $this->assertCount(0, $this->manager->getHistory($conversation, actor: $this->viewer));
    }

    public function test_sharing_rejects_foreign_and_inactive_members_atomically(): void
    {
        $conversation = $this->conversation();
        $foreign = User::factory()->create();
        $this->share($conversation);
        foreach ([$foreign, $this->member(false)] as $invalid) {
            try {
                $this->manager->updateParticipants($conversation, $this->owner, $this->organization->id, [['user_id' => $invalid->id, 'role' => 'viewer']]);
                $this->fail('Nonmember must be rejected.');
            } catch (RuntimeException) {
            }
            $this->assertNotNull($this->manager->findAccessibleConversation($conversation->id, $this->viewer, $this->organization->id));
        }
    }

    public function test_history_suppresses_entire_answer_after_source_access_is_revoked(): void
    {
        $conversation = $this->conversation();
        $this->share($conversation);
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $this->manager->addMessage($conversation, 'user', 'Покажи бюджет');
        $answer = $this->manager->addMessage($conversation, 'assistant', 'Секретный бюджет и публичная часть', metadata: ['validation_status' => 'verified', 'source_refs' => [$this->ref($project)]]);
        $this->assertCount(2, $this->manager->getHistoryPage($conversation, $this->viewer)->items());
        $this->denied[] = $this->viewer->id.':'.$project->id;
        $visible = $this->manager->getHistoryPage($conversation, $this->viewer)->items();
        $this->assertCount(1, $visible);
        $this->assertSame('user', $visible[0]->role);
        $this->assertFalse($this->manager->canReadMessage($answer, $conversation, $this->viewer));
    }

    public function test_legacy_answers_never_supply_facts_and_are_hidden_from_shared_viewers(): void
    {
        $conversation = $this->conversation();
        $this->share($conversation);
        $this->manager->addMessage($conversation, 'user', 'Продолжи');
        $this->manager->addMessage($conversation, 'assistant', 'Старое непроверенное число');
        $this->assertCount(1, $this->manager->getHistory($conversation, actor: $this->viewer));
        $context = $this->manager->getMessagesForContext($conversation, actor: $this->owner);
        $this->assertSame([['role' => 'user', 'content' => 'Продолжи']], $context);
    }

    public function test_history_pagination_uses_id_when_timestamps_are_identical(): void
    {
        $conversation = $this->conversation();
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->manager->addMessage($conversation, 'user', 'Сообщение '.$i)->id;
        }
        $conversation->messages()->update(['created_at' => '2026-09-29 10:00:00']);
        $first = $this->manager->getHistoryPage($conversation, $this->owner, 2, 1);
        $second = $this->manager->getHistoryPage($conversation, $this->owner, 2, 2);
        $third = $this->manager->getHistoryPage($conversation, $this->owner, 2, 3);
        $this->assertSame([$ids[3], $ids[4]], $first->getCollection()->pluck('id')->all());
        $this->assertSame([$ids[1], $ids[2]], $second->getCollection()->pluck('id')->all());
        $this->assertSame([$ids[0]], $third->getCollection()->pluck('id')->all());
        $this->assertSame(5, $first->total());
    }

    public function test_memory_requires_confirmation_and_is_user_and_organization_private(): void
    {
        foreach ([false, null, 'true'] as $confirmed) {
            try {
                $this->memories->create($this->owner, $this->organization->id, ['content' => 'Краткий ответ', 'confirmed' => $confirmed]);
                $this->fail('Explicit boolean confirmation required.');
            } catch (RuntimeException) {
            }
        }
        $memory = $this->memories->create($this->owner, $this->organization->id, ['content' => 'Краткий ответ', 'confirmed' => true]);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $memory->id);
        $this->assertCount(1, $this->memories->list($this->owner, $this->organization->id));
        $this->assertCount(0, $this->memories->list($this->viewer, $this->organization->id));
        try {
            $this->memories->update($this->owner, $this->organization->id, $memory, ['content' => 'Новая память', 'confirmed' => false]);
            $this->fail('Edit must also be confirmed.');
        } catch (RuntimeException) {
        }
        $this->assertSame('Краткий ответ', $memory->refresh()->payload['content']);
        $this->expectException(RuntimeException::class);
        $this->memories->delete($this->viewer, $this->organization->id, $memory);
    }

    public function test_memory_rechecks_source_acl_freshness_and_origin_conversation(): void
    {
        $conversation = $this->conversation();
        $this->share($conversation);
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $memory = $this->memories->create($this->viewer, $this->organization->id, ['content' => 'Выбран проект', 'confirmed' => true, 'conversation_id' => $conversation->id, 'source_refs' => [$this->ref($project)]]);
        $this->assertCount(1, $this->memories->forContext($this->viewer, $this->organization->id));
        $this->travel(6)->minutes();
        $this->assertCount(0, $this->memories->forContext($this->viewer, $this->organization->id));
        $this->assertCount(1, $this->memories->list($this->viewer, $this->organization->id));
        $this->travelBack();
        $this->denied[] = $this->viewer->id.':'.$project->id;
        $this->assertCount(0, $this->memories->list($this->viewer, $this->organization->id));
        $this->denied = [];
        $this->manager->updateParticipants($conversation, $this->owner, $this->organization->id, []);
        $this->assertCount(0, $this->memories->list($this->viewer, $this->organization->id));
        $this->assertDatabaseHas('ai_memories', ['id' => $memory->id]);
    }

    public function test_summary_accumulates_verified_selection_and_decisions_and_rechecks_access(): void
    {
        $conversation = $this->conversation();
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $ref = $this->ref($project);
        $this->manager->saveSummary($conversation, $this->owner, ['validation_status' => 'verified', 'summary' => 'Выбран проект', 'selected_entities' => [$ref], 'user_decisions' => ['Подробный ответ']], [$ref], 1);
        $this->manager->addMessage($conversation, 'user', 'Продолжи');
        $saved = $this->manager->saveSummary($conversation, $this->owner, ['validation_status' => 'verified', 'summary' => 'Обсуждён график', 'user_decisions' => ['Сравнить сроки']], [$ref], $conversation->context_version);
        $this->assertStringContainsString('Выбран проект', $saved->summary);
        $this->assertStringContainsString('Обсуждён график', $saved->summary);
        $this->assertSame(['Подробный ответ', 'Сравнить сроки'], $saved->user_decisions);
        $this->assertCount(1, $saved->selected_entities);
        $this->travel(6)->minutes();
        $stale = $this->manager->getSummary($conversation, $this->owner);
        $this->assertNull($stale->summary);
        $promptPayload = json_encode(['conversation_summary' => $stale], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Выбран проект', $promptPayload);
        $this->assertStringNotContainsString('Подробный ответ', $promptPayload);
        $this->assertStringNotContainsString('Сравнить сроки', $promptPayload);
        $this->assertSame([], $stale->summary_segments);
        $this->assertSame([], $stale->user_decisions);
        $this->assertSame([], $stale->source_refs);
        $this->assertCount(1, $stale->selected_entities);
        $this->assertSame($project->id, $stale->selected_entities[0]['id']);
        $this->denied[] = $this->owner->id.':'.$project->id;
        $this->assertNull($this->manager->getSummary($conversation, $this->owner));
    }

    public function test_memory_snapshot_is_not_reused_after_entity_changes(): void
    {
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $this->memories->create($this->owner, $this->organization->id, ['content' => 'Бюджет проекта', 'confirmed' => true, 'source_refs' => [$this->ref($project)]]);
        $this->assertCount(1, $this->memories->forContext($this->owner, $this->organization->id));
        $this->travel(2)->seconds();
        $project->update(['name' => 'Обновлённый проект']);
        $this->assertCount(0, $this->memories->forContext($this->owner, $this->organization->id));
        $this->assertCount(1, $this->memories->list($this->owner, $this->organization->id));
    }

    public function test_summary_keeps_recent_bounded_text_and_removes_obsolete_provenance(): void
    {
        $conversation = $this->conversation();
        $old = Project::factory()->create(['organization_id' => $this->organization->id]);
        $recent = Project::factory()->create(['organization_id' => $this->organization->id]);
        $decisions = array_map(static fn (int $id): array => ['origin_request_id' => 'request-'.$id, 'content' => 'Решение '.$id], range(1, 110));
        $this->manager->saveSummary($conversation, $this->owner, ['validation_status' => 'verified', 'summary' => str_repeat('а', 16000), 'user_decisions' => $decisions], [$this->ref($old)], 1);
        $text = str_repeat('б', 17000).'Последнее решение';
        $saved = $this->manager->saveSummary($conversation, $this->owner, ['validation_status' => 'verified', 'summary' => $text, 'user_decisions' => [['origin_request_id' => 'latest', 'content' => 'Последнее решение']]], [$this->ref($recent)], 1);
        $this->assertLessThanOrEqual(16000, mb_strlen($saved->summary));
        $this->assertStringEndsWith('Последнее решение', $saved->summary);
        $this->assertStringNotContainsString('а', mb_substr($saved->summary, 0, 100));
        $this->assertCount(100, $saved->user_decisions);
        $this->assertSame('latest', $saved->user_decisions[99]['origin_request_id']);
        $this->assertSame([$recent->id], array_column($saved->source_refs, 'id'));
        $this->assertCount(1, $saved->summary_segments);
    }

    public function test_pending_answer_is_hidden_from_owner_history_and_context_until_published(): void
    {
        $conversation = $this->conversation();
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $metadata = ['validation_status' => 'verified', 'request_state' => 'pending', 'source_refs' => [$this->ref($project)]];
        $message = $this->manager->addMessage($conversation, 'assistant', 'Ещё не опубликовано', metadata: $metadata);
        $this->assertFalse($this->manager->canReadMessage($message, $conversation, $this->owner));
        $this->assertCount(0, $this->manager->getHistory($conversation, actor: $this->owner));
        $this->assertSame([], $this->manager->getMessagesForContext($conversation, actor: $this->owner));
        $metadata['request_state'] = 'completed';
        $message->update(['metadata' => $metadata]);
        $this->assertTrue($this->manager->canReadMessage($message->refresh(), $conversation, $this->owner));
        $this->assertCount(1, $this->manager->getHistory($conversation, actor: $this->owner));
        $this->assertSame([['role' => 'assistant', 'content' => 'Ещё не опубликовано']], $this->manager->getMessagesForContext($conversation, actor: $this->owner));
    }

    public function test_summary_bounds_selected_entities_and_source_references(): void
    {
        $conversation = $this->conversation();
        $projects = Project::factory()->count(105)->create(['organization_id' => $this->organization->id]);
        $refs = $projects->map(fn (Project $project): array => $this->ref($project))->all();
        $saved = $this->manager->saveSummary($conversation, $this->owner, ['validation_status' => 'verified', 'selected_entities' => $refs], $refs, 1);
        $this->assertCount(100, $saved->selected_entities);
        $this->assertCount(100, $saved->source_refs);
        $this->assertSame(array_slice($projects->pluck('id')->all(), -100), array_column($saved->selected_entities, 'id'));
        $this->assertSame(array_column($saved->selected_entities, 'id'), array_column($saved->source_refs, 'id'));
    }

    public function test_summary_does_not_promote_legacy_unverified_text_into_cumulative_proof(): void
    {
        $conversation = $this->conversation();
        ConversationSummary::create(['conversation_id' => $conversation->id, 'summary' => 'Старое число без подтверждённого происхождения', 'source_refs' => []]);
        $this->assertNull($this->manager->getSummary($conversation, $this->owner)->summary);
        $saved = $this->manager->saveSummary($conversation, $this->owner, ['validation_status' => 'verified', 'summary' => 'Текущее решение пользователя'], [], 1);
        $this->assertSame('Текущее решение пользователя', $saved->summary);
        $this->assertCount(1, $saved->summary_segments);
    }

    public function test_actual_context_use_extends_only_returned_memory_while_views_do_not(): void
    {
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $used = $this->memories->create($this->owner, $this->organization->id, ['content' => 'Кратко', 'confirmed' => true]);
        $stale = $this->memories->create($this->owner, $this->organization->id, ['content' => 'Старый снимок', 'confirmed' => true, 'source_refs' => [$this->ref($project)]]);
        $usedUntil = $used->expires_at->toISOString();
        $staleUntil = $stale->expires_at->toISOString();
        $usedAt = $used->last_used_at->toISOString();
        $this->travel(30)->days();
        $this->memories->list($this->owner, $this->organization->id);
        $this->assertSame($usedUntil, $used->refresh()->expires_at->toISOString());
        $this->assertSame($usedAt, $used->last_used_at->toISOString());
        $context = $this->memories->forContext($this->owner, $this->organization->id);
        $this->assertSame(['Кратко'], array_column($context, 'content'));
        $used->refresh();
        $this->assertGreaterThan($usedUntil, $used->expires_at->toISOString());
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, $used->expires_at->timestamp, 1);
        $this->assertEqualsWithDelta(now()->timestamp, $used->last_used_at->timestamp, 1);
        $this->assertSame($staleUntil, $stale->refresh()->expires_at->toISOString());
    }

    public function test_disabled_assistant_access_blocks_history_sharing_and_memory_services(): void
    {
        $conversation = $this->conversation();
        $this->share($conversation);
        $message = $this->manager->addMessage($conversation, 'user', 'Личное сообщение');
        $memory = $this->memories->create($this->owner, $this->organization->id, ['content' => 'Личная память', 'confirmed' => true]);
        $this->assistantEnabled = false;
        $this->assertNull($this->manager->findAccessibleConversation($conversation->id, $this->owner, $this->organization->id));
        $this->assertSame(0, $this->manager->queryVisibleConversations($this->owner, $this->organization->id)->count());
        $this->assertCount(0, $this->manager->getHistory($conversation, actor: $this->owner));
        $this->assertCount(0, $this->manager->getParticipants($conversation, $this->owner));
        $this->assertFalse($this->manager->canReadMessage($message, $conversation, $this->owner));
        foreach ([
            fn () => $this->manager->getHistoryPage($conversation, $this->owner),
            fn () => $this->manager->updateParticipants($conversation, $this->owner, $this->organization->id, []),
            fn () => $this->memories->list($this->owner, $this->organization->id),
            fn () => $this->memories->create($this->owner, $this->organization->id, ['content' => 'Запрещённая память', 'confirmed' => true]),
            fn () => $this->memories->update($this->owner, $this->organization->id, $memory, ['content' => 'Запрещённая правка', 'confirmed' => true]),
            fn () => $this->memories->delete($this->owner, $this->organization->id, $memory),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Disabled assistant access must reject direct service operations.');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame('Личная память', $memory->refresh()->payload['content']);
        $this->assertDatabaseHas('ai_memories', ['id' => $memory->id]);
    }

    public function test_summary_rejects_forged_selected_entity_despite_accessible_source(): void
    {
        $conversation = $this->conversation();
        $accessible = Project::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Project::factory()->create();
        $restricted = Project::factory()->create(['organization_id' => $this->organization->id]);
        $this->denied[] = $this->owner->id.':'.$restricted->id;
        foreach ([$foreign, $restricted] as $entity) {
            try {
                $this->manager->saveSummary($conversation, $this->owner, ['validation_status' => 'verified', 'summary' => 'Подмена', 'selected_entities' => [$this->ref($entity)]], [$this->ref($accessible)], 1);
                $this->fail('Every selected entity must pass current ACL.');
            } catch (RuntimeException) {
            }
        }
        $this->assertDatabaseMissing('ai_conversation_summaries', ['conversation_id' => $conversation->id]);
    }

    public function test_memory_operations_reject_other_current_organization(): void
    {
        $memory = $this->memories->create($this->owner, $this->organization->id, ['content' => 'Память первой организации', 'confirmed' => true]);
        $otherOrganization = Organization::factory()->create();
        $this->owner->organizations()->attach($otherOrganization->id, ['is_active' => true]);
        $this->owner->current_organization_id = $otherOrganization->id;
        $this->assertCount(0, $this->memories->list($this->owner, $otherOrganization->id));
        $this->expectException(RuntimeException::class);
        $this->memories->update($this->owner, $otherOrganization->id, $memory, ['content' => 'Подмена организации', 'confirmed' => true]);
    }

    public function test_public_memory_rejects_forged_server_markers_even_with_project_access(): void
    {
        $this->financeAllowed = false;
        $project = Project::factory()->create(['organization_id' => $this->organization->id]);
        $this->assertTrue($this->policy->canReadEntity($this->owner, $this->organization->id, 'project', $project->id));
        $this->assertFalse($this->policy->canReadReference($this->owner, $this->organization->id, $this->ref($project)));
        $memory = $this->memories->create($this->owner, $this->organization->id, ['content' => 'Личная настройка', 'confirmed' => true]);
        $markers = ['content_scope' => 'structured', 'checked_fields' => ['name'], 'required_permissions' => ['projects.view'], 'required_domains' => ['projects']];
        $forgeries = [$markers];
        foreach ($markers as $key => $value) {
            $forgeries[] = [$key => $value];
        }
        foreach ($forgeries as $forgery) {
            $data = ['content' => 'Поддельные финансовые факты', 'confirmed' => true, 'source_refs' => [$this->ref($project) + $forgery]];
            foreach ([new StoreAssistantMemoryRequest, new UpdateAssistantMemoryRequest] as $request) {
                $validator = Validator::make($data, $request->rules());
                $this->assertTrue($validator->fails());
                $this->assertArrayHasKey('source_refs.0', $validator->errors()->messages());
            }
            foreach ([
                fn () => $this->memories->create($this->owner, $this->organization->id, $data),
                fn () => $this->memories->update($this->owner, $this->organization->id, $memory, $data),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Public memory must never accept server provenance markers.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('assistant_memory_source_access_denied', $exception->getMessage());
                }
            }
        }
        $this->assertSame('Личная настройка', $memory->refresh()->payload['content']);
        $this->assertSame(1, $memory->version);
        $this->assertSame(1, DB::table('ai_memories')->where('user_id', $this->owner->id)->count());
    }

    public function test_reading_does_not_extend_retention_and_purge_removes_related_memory_and_summary(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memories->create($this->owner, $this->organization->id, ['content' => 'Память', 'confirmed' => true, 'conversation_id' => $conversation->id]);
        ConversationSummary::create(['conversation_id' => $conversation->id, 'summary' => 'Итог', 'source_refs' => []]);
        $lastActivity = now()->subDays(91)->startOfSecond();
        $conversation->forceFill(['last_activity_at' => $lastActivity])->save();
        $expiresAt = $memory->expires_at->toISOString();
        $this->manager->findAccessibleConversation($conversation->id, $this->owner, $this->organization->id);
        $this->manager->getHistoryPage($conversation, $this->owner);
        $this->manager->getSummary($conversation, $this->owner);
        $this->memories->list($this->owner, $this->organization->id);
        $this->assertTrue($conversation->refresh()->last_activity_at->equalTo($lastActivity));
        $this->assertSame($expiresAt, $memory->refresh()->expires_at->toISOString());
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('delete');
        $result = (new AssistantRetentionService($files))->purge();
        $this->assertSame(1, $result['conversations']);
        $this->assertDatabaseMissing('ai_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_memories', ['id' => $memory->id]);
        $this->assertDatabaseMissing('ai_conversation_summaries', ['conversation_id' => $conversation->id]);
    }

    public function test_retention_deletes_registered_generated_files_and_preserves_active_chats(): void
    {
        $old = $this->conversation();
        $active = $this->conversation();
        $path = 'org-'.$this->organization->id.'/personal-files/user-'.$this->owner->id.'/assistant-report.pdf';
        $report = ReportFile::create(['organization_id' => $this->organization->id, 'user_id' => $this->owner->id, 'type' => 'reports', 'filename' => 'report.pdf', 'name' => 'report', 'path' => $path, 'size' => 10]);
        $this->manager->addMessage($old, 'assistant', 'Файл', metadata: ['artifacts' => [['storage_path' => $path, 'report_file_id' => (string) $report->id]]]);
        $old->forceFill(['last_activity_at' => now()->subDays(91)])->save();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('delete')->once()->with($path, Mockery::type(Organization::class))->andReturn(true);
        (new AssistantRetentionService($files))->purge();
        $this->assertDatabaseMissing('report_files', ['id' => $report->id]);
        $this->assertDatabaseHas('ai_conversations', ['id' => $active->id]);
    }

    public function test_retention_command_previews_actual_activity_and_does_not_delete_by_default(): void
    {
        $this->travelTo(now()->startOfSecond());
        $old = $this->conversation();
        $active = $this->conversation();
        $this->manager->addMessage($old, 'user', 'Старое сообщение');
        $memory = $this->memories->create($this->owner, $this->organization->id, ['content' => 'Память чата', 'confirmed' => true, 'conversation_id' => $old->id]);
        ConversationSummary::create(['conversation_id' => $old->id, 'summary' => 'Итог', 'source_refs' => []]);
        $path = 'org-'.$this->organization->id.'/personal-files/user-'.$this->owner->id.'/preview-report.pdf';
        $report = ReportFile::create(['organization_id' => $this->organization->id, 'user_id' => $this->owner->id, 'type' => 'reports', 'filename' => 'report.pdf', 'name' => 'report', 'path' => $path, 'size' => 10]);
        $this->manager->addMessage($old, 'assistant', 'Файл', metadata: ['artifacts' => [['storage_path' => $path, 'report_file_id' => (string) $report->id]]]);
        $old->forceFill(['last_activity_at' => now()->subDays(91), 'updated_at' => now()])->save();
        $active->forceFill(['last_activity_at' => now(), 'created_at' => now()->subDays(120)])->save();
        $this->manager->getHistoryPage($old, $this->owner);
        $this->memories->list($this->owner, $this->organization->id);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('delete');
        $retention = new AssistantRetentionService($files);
        $this->app->instance(AssistantRetentionService::class, $retention);
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new PurgeAssistantRetentionCommand);
        $preview = $retention->preview();
        $this->assertSame('preview', $preview['mode']);
        $this->assertSame(now()->subDays(90)->toISOString(), $preview['cutoff']);
        $this->assertSame(1, $preview['conversations']);
        $this->assertSame(1, $preview['memories']);
        $this->assertSame(1, $preview['summaries']);
        $this->assertSame(2, $preview['messages']);
        $this->assertSame(1, $preview['reports']);
        $this->assertSame(1, $preview['files']);
        $this->artisan('ai-assistant:purge-retention')->expectsOutput(json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->assertExitCode(0);
        $this->assertDatabaseHas('ai_conversations', ['id' => $old->id]);
        $this->assertDatabaseHas('ai_conversations', ['id' => $active->id]);
        $this->assertDatabaseHas('ai_memories', ['id' => $memory->id]);
        $this->assertDatabaseHas('report_files', ['id' => $report->id]);
    }

    public function test_retention_command_executes_only_explicitly_and_is_idempotent(): void
    {
        $old = $this->conversation();
        $old->forceFill(['last_activity_at' => now()->subDays(91)])->save();
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('delete');
        $retention = new AssistantRetentionService($files);
        $this->app->instance(AssistantRetentionService::class, $retention);
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new PurgeAssistantRetentionCommand);
        $this->artisan('ai-assistant:purge-retention', ['--execute' => true])->assertExitCode(0);
        $this->assertDatabaseMissing('ai_conversations', ['id' => $old->id]);
        $again = $retention->purge();
        $this->assertSame('execute', $again['mode']);
        foreach (['conversations', 'messages', 'summaries', 'memories', 'documents', 'reports', 'files'] as $key) {
            $this->assertSame(0, $again[$key]);
        }
    }

    private function member(bool $active = true): User
    {
        $user = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $user->organizations()->attach($this->organization->id, ['is_active' => $active]);

        return $user;
    }

    private function conversation(): Conversation
    {
        return $this->manager->createConversation($this->organization->id, $this->owner);
    }

    private function share(Conversation $conversation): void
    {
        $this->manager->updateParticipants($conversation, $this->owner, $this->organization->id, [['user_id' => $this->viewer->id, 'role' => 'viewer']]);
    }

    private function ref(Project $project): array
    {
        return ['type' => 'project', 'id' => $project->id, 'organization_id' => $this->organization->id, 'fetched_at' => now()->toISOString()];
    }
}
