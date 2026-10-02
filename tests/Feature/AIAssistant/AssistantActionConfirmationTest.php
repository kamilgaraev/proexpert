<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Models\AIPendingAction;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\ConversationParticipant;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

final class AssistantActionConfirmationTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private Organization $organization;
    private User $actor;
    private Conversation $conversation;
    private AIToolInterface $tool;
    private AssistantActionService $service;
    private bool $allowed = true;
    private array $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->actor = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'is_owner' => true]);
        $this->conversation = Conversation::query()->create(['organization_id' => $this->organization->id, 'user_id' => $this->actor->id, 'context' => []]);
        $this->proposal = ['tool_name' => 'create_measurement_unit', 'arguments' => ['name' => 'Исходное'], 'allowed' => true];
        $requestId = (string) Str::uuid();
        $this->conversation->messages()->create(['role' => 'user', 'content' => 'Создай единицу измерения Исходное', 'metadata' => [
            'request_id' => $requestId, 'actor_user_id' => $this->actor->id,
            'request' => ['message' => 'Создай единицу измерения Исходное', 'allow_actions' => true, 'context' => [], 'profile' => 'normal'],
        ]]);
        $this->conversation->messages()->create(['role' => 'assistant', 'content' => 'Предлагаю действие', 'metadata' => [
            'request_id' => $requestId, 'actor_user_id' => $this->actor->id, 'proposed_actions' => [$this->proposal],
        ]]);
        $this->tool = new class implements AIToolInterface {
            public int $calls = 0;
            public function getName(): string { return 'create_measurement_unit'; }
            public function getDescription(): string { return 'Создаёт единицу измерения.'; }
            public function getParametersSchema(): array { return ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]; }
            public function execute(array $arguments, ?User $user, Organization $organization): array|string { $this->calls++; return ['message' => $arguments['name'], 'calls' => $this->calls]; }
        };
        $registry = Mockery::mock(AIToolRegistry::class);
        $registry->shouldReceive('getTool')->andReturnUsing(fn (string $name): ?AIToolInterface => $name === $this->tool->getName() ? $this->tool : null);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('isMutationTool')->andReturn(true);
        $permissions->shouldReceive('canUseAssistant')->andReturnUsing(fn (User $user, int $organizationId): bool =>
            $user->is_active
            && (int) $user->current_organization_id === $organizationId
            && $user->belongsToOrganization($organizationId)
        );
        $this->app->instance(AIPermissionChecker::class, $permissions);
        $permissions->shouldReceive('canExecuteTool')->andReturnUsing(fn (): bool => $this->allowed);
        $logging = Mockery::mock(LoggingService::class);
        $logging->shouldReceive('audit')->zeroOrMoreTimes();
        $this->service = new AssistantActionService($registry, $permissions, app(ConversationManager::class), $logging);
    }

    public function test_execute_uses_stored_arguments_and_replays_result(): void
    {
        $payload = $this->payload();
        $payload['arguments'] = ['name' => 'Подмена'];
        $first = $this->execute($payload);
        $replay = $this->execute($payload);
        $this->assertSame('Исходное', $first['message']);
        $this->assertSame($this->canonicalResult($first), $this->canonicalResult($replay));
        $this->assertSame(1, $this->tool->calls);
    }

    public function test_replay_rechecks_revoked_tool_permission(): void
    {
        $payload = $this->payload();
        $this->execute($payload);
        $this->allowed = false;
        $this->expectException(AuthorizationException::class);
        $this->execute($payload);
    }

    public function test_execute_rejects_expired_action(): void
    {
        $payload = $this->payload();
        AIPendingAction::query()->whereKey($payload['id'])->update(['expires_at' => now()->subSecond()]);
        $this->expectException(RuntimeException::class);
        $this->execute($payload);
    }

    public function test_execute_rejects_other_actor_and_organization(): void
    {
        $payload = $this->payload();
        $other = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $other->organizations()->attach($this->organization->id, ['is_active' => true]);
        ConversationParticipant::query()->create(['conversation_id' => $this->conversation->id, 'user_id' => $other->id, 'role' => 'editor', 'added_by_user_id' => $this->actor->id]);
        foreach ([[$this->organization->id, $other], [Organization::factory()->create()->id, $this->actor]] as [$organizationId, $actor]) {
            try {
                $this->service->execute($payload, $organizationId, $actor, $this->conversation);
                $this->fail('Ожидалось исключение чужого контекста.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(0, $this->tool->calls);
    }

    public function test_preview_rejects_missing_conversation(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->preview($this->proposal, $this->organization->id, $this->actor);
    }

    public function test_execute_rejects_actions_disabled_even_with_valid_confirmation(): void
    {
        $payload = $this->payload();
        $payload['allow_actions'] = false;
        $this->expectException(AuthorizationException::class);
        $this->execute($payload);
    }

    public function test_execute_rejects_different_conversation(): void
    {
        $payload = $this->payload();
        $other = Conversation::query()->create(['organization_id' => $this->organization->id, 'user_id' => $this->actor->id, 'context' => []]);
        $this->expectException(AuthorizationException::class);
        $this->service->execute($payload, $this->organization->id, $this->actor, $other);
    }

    public function test_preview_rejects_unproposed_arguments(): void
    {
        $this->proposal['arguments']['name'] = 'Подмена';
        $this->expectException(AuthorizationException::class);
        $this->payload();
    }

    public function test_preview_rejects_original_request_with_actions_disabled(): void
    {
        $origin = $this->conversation->messages()->where('role', 'user')->firstOrFail();
        $metadata = $origin->metadata;
        $metadata['request']['allow_actions'] = false;
        $origin->update(['metadata' => $metadata]);
        $this->proposal['allow_actions'] = true;
        $this->expectException(AuthorizationException::class);
        $this->payload();
    }

    public function test_execute_rejects_changed_request_payload(): void
    {
        $payload = $this->payload();
        $origin = $this->conversation->messages()->where('role', 'user')->firstOrFail();
        $metadata = $origin->metadata;
        $metadata['request']['context'] = ['project_id' => 987];
        $origin->update(['metadata' => $metadata]);
        $this->expectException(AuthorizationException::class);
        $this->execute($payload);
    }

    public function test_execute_rejects_changed_context_version(): void
    {
        $payload = $this->payload();
        $this->conversation->increment('context_version');
        $this->expectException(RuntimeException::class);
        $this->execute($payload);
    }

    public function test_preview_refresh_uses_one_pending_action_and_revokes_previous_token(): void
    {
        $first = $this->payload();
        $second = $this->payload();
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, AIPendingAction::query()->count());
        try {
            $this->execute($first);
            $this->fail('Ожидалось исключение заменённого токена.');
        } catch (AuthorizationException) {
        }
        $this->execute($second);
        $this->expectException(RuntimeException::class);
        $this->payload();
    }

    public function test_execute_rejects_viewer_after_editor_access_revoked(): void
    {
        $owner = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $this->conversation->update(['user_id' => $owner->id]);
        $participant = ConversationParticipant::query()->create(['conversation_id' => $this->conversation->id, 'user_id' => $this->actor->id, 'role' => 'editor', 'added_by_user_id' => $owner->id]);
        $payload = $this->payload();
        $this->execute($payload);
        $participant->update(['role' => 'viewer']);
        $this->expectException(AuthorizationException::class);
        $this->execute($payload);
    }

    private function canonicalResult(array $result): array
    {
        if (! array_is_list($result)) {
            ksort($result);
        }
        foreach ($result as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->canonicalResult($value);
            }
        }
        return $result;
    }

    private function payload(): array
    {
        $preview = $this->service->preview($this->proposal, $this->organization->id, $this->actor, $this->conversation);
        return ['id' => $preview['action']['id'], 'preview_token' => $preview['preview_token'], 'confirmed' => true];
    }

    private function execute(array $payload): array
    {
        return $this->service->execute($payload, $this->organization->id, $this->actor, $this->conversation);
    }
}
