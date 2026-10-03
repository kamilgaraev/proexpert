<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AIAssistantMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_chat_preserves_rag_context_metadata(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $this->allowAccess();

        $ragContext = [
            'enabled' => true,
            'used' => true,
            'sources' => [
                [
                    'id' => 'project:77:summary',
                    'title' => 'Project risk memo',
                    'entity_type' => 'project',
                    'entity_id' => 77,
                    'project_id' => 77,
                    'score' => 0.91,
                ],
            ],
            'limits' => [
                'max_sources' => 6,
            ],
        ];

        Queue::fake();
        $this->mock(AIAssistantService::class, function (MockInterface $mock) use ($ragContext): void {
            $mock->shouldReceive('executeStartedRequest')
                ->once()
                ->with(
                    Mockery::on(static fn (AssistantRequest $request): bool => $request->payload['message'] === 'Какие есть риски по проекту?'
                        && $request->surface === 'mobile'),
                    Mockery::type(User::class),
                )
                ->andReturnUsing(static fn (AssistantRequest $request, User $actor): array => app(AssistantRequestLifecycle::class)->complete($request, $actor, [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Есть риск задержки поставки.',
                        'tokens_used' => 42,
                        'metadata' => [
                            'rag_context' => $ragContext,
                        ],
                        'created_at' => '2026-05-23T19:00:00+00:00',
                    ],
                    'tokens_used' => 42,
                    'usage' => [
                        'monthly_limit' => 5000,
                        'used' => 1,
                        'remaining' => 4999,
                        'percentage_used' => 0.1,
                        'tokens_used' => 42,
                        'cost_rub' => 0.01,
                    ],
                ]));
        });

        $payload = ['message' => 'Какие есть риски по проекту?', 'request_id' => (string) Str::uuid(), 'profile' => 'normal'];
        $quote = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/credits/quote', $payload)->assertOk();
        $payload['quote_id'] = $quote->json('data.quote_id');
        $this->postJson('/api/v1/mobile/ai-assistant/chat', $payload)->assertStatus(202);
        $request = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
        (new ExecuteAssistantChatJob($request->id))->handle(app(AssistantRequestLifecycle::class), app(AssistantDataAccessPolicy::class));
        Queue::assertPushed(ExecuteAssistantChatJob::class, 1);
        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/ai-assistant/requests/'.$payload['request_id']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.response.message.metadata.rag_context.used', true)
            ->assertJsonPath('data.response.message.metadata.rag_context.sources.0.title', 'Project risk memo')
            ->assertJsonPath('data.response.message.metadata.rag_context.sources.0.project_id', 77);
    }

    public function test_mobile_ai_action_preview_requires_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $action = $this->scheduleTaskAction();
        $this->allowAccess();
        $conversation = $this->conversation($context, $action);

        $this->mock(AssistantActionService::class, function (MockInterface $mock) use ($action, $conversation): void {
            $mock->shouldReceive('preview')
                ->once()
                ->with(Mockery::on(static fn (array $proposal): bool => $proposal['tool_name'] === $action['tool_name']
                    && $proposal['arguments'] === $action['arguments']), Mockery::type('int'), Mockery::type(User::class), $conversation)
                ->andReturn([
                    'title' => 'Создать задачу графика',
                    'description' => 'Создание задачи графика',
                    'requires_confirmation' => true,
                    'action_class' => 'confirm',
                    'action' => array_merge($action, [
                        'allowed' => false,
                        'reason_if_disabled' => 'Недостаточно прав для выполнения действия.',
                    ]),
                    'warnings' => ['Это действие недоступно по текущим правам пользователя.'],
                    'summary_items' => [
                        ['label' => 'Проект', 'value' => '77'],
                    ],
                    'navigation_target' => null,
                    'executable' => false,
                ]);
        });

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/preview', [
                'conversation_id' => $conversation->id,
                'action' => $action,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.executable', false)
            ->assertJsonPath('data.action.allowed', false)
            ->assertJsonPath('data.action.reason_if_disabled', 'Недостаточно прав для выполнения действия.');
    }

    public function test_mobile_ai_action_execute_requires_confirmed_preview(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $action = $this->scheduleTaskAction();
        $previewAction = array_merge($action, [
            'id' => (string) Str::uuid(),
            'preview_token' => str_repeat('a', 64),
            'allowed' => true,
            'reason_if_disabled' => null,
        ]);
        $this->allowAccess();
        $conversation = $this->conversation($context, $action);

        $this->mock(AssistantActionService::class, function (MockInterface $mock) use ($action, $previewAction, $conversation): void {
            $mock->shouldReceive('preview')
                ->once()
                ->with(Mockery::on(static fn (array $proposal): bool => $proposal['tool_name'] === $action['tool_name']
                    && $proposal['arguments'] === $action['arguments']), Mockery::type('int'), Mockery::type(User::class), $conversation)
                ->andReturn([
                    'title' => 'Создать задачу графика',
                    'description' => 'Создание задачи графика',
                    'requires_confirmation' => true,
                    'action_class' => 'confirm',
                    'action' => $previewAction,
                    'warnings' => [],
                    'summary_items' => [
                        ['label' => 'Проект', 'value' => '77'],
                    ],
                    'navigation_target' => null,
                    'executable' => true,
                ]);

            $mock->shouldReceive('execute')
                ->once()
                ->with(
                    Mockery::on(static function (array $payload) use ($previewAction): bool {
                        return $payload['confirmed'] === true
                            && $payload['id'] === $previewAction['id']
                            && $payload['preview_token'] === $previewAction['preview_token'];
                    }),
                    Mockery::type('int'),
                    Mockery::type(User::class),
                    $conversation,
                )
                ->andReturn([
                    'message' => 'Действие выполнено.',
                    'navigation_target' => null,
                    'action' => $previewAction,
                    'result' => ['status' => 'completed'],
                ]);
        });

        $previewResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/preview', [
                'conversation_id' => $conversation->id,
                'action' => $action,
            ]);

        $previewResponse->assertOk();
        $serverAction = [
            'id' => $previewResponse->json('data.action.id'),
            'preview_token' => $previewResponse->json('data.action.preview_token'),
        ];

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/execute', [
                'conversation_id' => $conversation->id,
                'action' => $serverAction + ['confirmed' => false],
            ])
            ->assertStatus(422);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/execute', [
                'conversation_id' => $conversation->id,
                'action' => array_replace($serverAction, ['confirmed' => true, 'preview_token' => 'invalid-preview-token']),
            ])
            ->assertStatus(422);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/execute', [
                'conversation_id' => $conversation->id,
                'action' => $serverAction + ['confirmed' => true],
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Действие выполнено.');
    }

    private function scheduleTaskAction(): array
    {
        return [
            'id' => 'create-schedule-task-77',
            'type' => 'act',
            'label' => 'Создать задачу графика',
            'allowed' => true,
            'requires_confirmation' => true,
            'action_class' => 'confirm',
            'tool_name' => 'create_schedule_task',
            'arguments' => [
                'project_id' => 77,
                'title' => 'Проверить готовность участка',
            ],
            'required_permissions' => ['schedule_tasks.create'],
        ];
    }

    private function allowAccess(): void
    {
        $this->mock(AIPermissionChecker::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canUseAssistant')->andReturn(true);
        });
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('canCurrent')->andReturn(true);
            $mock->shouldReceive('forCurrentChecks')->andReturnSelf();
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function conversation(AdminApiTestContext $context, array $action): Conversation
    {
        $conversation = Conversation::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'context' => [],
        ]);
        $this->mock(ConversationManager::class, function (MockInterface $mock) use ($conversation): void {
            $mock->shouldReceive('findAccessibleConversation')->andReturn($conversation);
            $mock->shouldReceive('touchActivity')->zeroOrMoreTimes();
        });
        $requestId = (string) Str::uuid();
        $conversation->messages()->create(['role' => 'user', 'content' => 'Создай задачу графика Проверить готовность участка', 'metadata' => [
            'request_id' => $requestId, 'actor_user_id' => $context->user->id,
            'request' => ['allow_actions' => true],
        ]]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Предлагаю действие', 'metadata' => [
            'request_id' => $requestId, 'actor_user_id' => $context->user->id, 'proposed_actions' => [$action],
        ]]);

        return $conversation;
    }
}
