<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AIAssistantMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_ai_action_preview_requires_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $action = $this->scheduleTaskAction();
        $this->allowAccess();

        $this->mock(AssistantActionService::class, function (MockInterface $mock) use ($action): void {
            $mock->shouldReceive('preview')
                ->once()
                ->with($action, Mockery::type('int'), Mockery::type(User::class))
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

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/preview', [
                'action' => $action,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.executable', false)
            ->assertJsonPath('data.action.allowed', false)
            ->assertJsonPath('data.action.reason_if_disabled', 'Недостаточно прав для выполнения действия.')
            ->assertJsonPath('data.preview_token', static fn ($value): bool => is_string($value) && $value !== '');
    }

    public function test_mobile_ai_action_execute_requires_confirmed_preview(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $action = $this->scheduleTaskAction();
        $previewAction = array_merge($action, [
            'allowed' => true,
            'reason_if_disabled' => null,
        ]);
        $this->allowAccess();

        $this->mock(AssistantActionService::class, function (MockInterface $mock) use ($action, $previewAction): void {
            $mock->shouldReceive('preview')
                ->once()
                ->with($action, Mockery::type('int'), Mockery::type(User::class))
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
                            && $payload['tool_name'] === $previewAction['tool_name']
                            && $payload['arguments'] === $previewAction['arguments'];
                    }),
                    Mockery::type('int'),
                    Mockery::type(User::class),
                    null
                )
                ->andReturn([
                    'message' => 'Действие выполнено.',
                    'navigation_target' => null,
                    'action' => $previewAction,
                    'result' => ['status' => 'completed'],
                ]);
        });

        $previewResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/preview', [
                'action' => $action,
            ]);

        $previewResponse->assertOk();
        $previewToken = (string) $previewResponse->json('data.preview_token');
        $serverAction = $previewResponse->json('data.action');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/execute', [
                'confirmed' => false,
                'preview_token' => $previewToken,
                'action' => $serverAction,
            ])
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/execute', [
                'confirmed' => true,
                'preview_token' => 'invalid-preview-token',
                'action' => $serverAction,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('ai_assistant.action_preview_required'));

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/ai-assistant/actions/execute', [
                'confirmed' => true,
                'preview_token' => $previewToken,
                'action' => $serverAction,
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
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
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
}
