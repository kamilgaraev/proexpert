<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Models\MeasurementUnit;
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

final class AssistantActionEntityBindingTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_execute_rejects_changed_entity_even_when_timestamp_is_unchanged(): void
    {
        [$service, $conversation, $actor, $unit, $proposal] = $this->fixture();
        $preview = $service->preview($proposal, (int) $actor->current_organization_id, $actor, $conversation);
        MeasurementUnit::query()->whereKey($unit->id)->update(['name' => 'Другие данные', 'updated_at' => $unit->updated_at]);
        $this->expectException(RuntimeException::class);
        $service->execute(['id' => $preview['action']['id'], 'preview_token' => $preview['preview_token'], 'confirmed' => true], (int) $actor->current_organization_id, $actor, $conversation);
    }

    public function test_preview_rejects_proposed_entity_from_foreign_organization(): void
    {
        [$service, $conversation, $actor, $unit, $proposal] = $this->fixture();
        $unit->update(['organization_id' => Organization::factory()->create()->id]);
        $this->expectException(AuthorizationException::class);
        $service->preview($proposal, (int) $actor->current_organization_id, $actor, $conversation);
    }

    public function test_preview_rejects_action_that_does_not_match_request_intent(): void
    {
        [$service, $conversation, $actor, $unit, $proposal] = $this->fixture();
        $conversation->messages()->where('role', 'user')->update(['content' => 'Создай единицу измерения']);
        $this->expectException(AuthorizationException::class);
        $service->preview($proposal, (int) $actor->current_organization_id, $actor, $conversation);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => true]);
        $unit = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => 'Метр', 'short_name' => 'м-тест', 'type' => 'material']);
        $conversation = Conversation::query()->create(['organization_id' => $organization->id, 'user_id' => $actor->id, 'context' => []]);
        $proposal = ['tool_name' => 'update_measurement_unit', 'arguments' => ['id' => $unit->id, 'name' => 'Метр новый'], 'allowed' => true];
        $requestId = (string) Str::uuid();
        $conversation->messages()->create(['role' => 'user', 'content' => 'Измени единицу измерения Метр', 'metadata' => ['request_id' => $requestId, 'actor_user_id' => $actor->id, 'request' => ['allow_actions' => true, 'message' => 'Измени единицу измерения Метр']]]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Предлагаю действие', 'metadata' => ['request_id' => $requestId, 'actor_user_id' => $actor->id, 'proposed_actions' => [$proposal]]]);
        $tool = new class implements AIToolInterface {
            public function getName(): string { return 'update_measurement_unit'; }
            public function getDescription(): string { return 'Изменяет единицу измерения.'; }
            public function getParametersSchema(): array { return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'required' => ['id', 'name']]; }
            public function execute(array $arguments, ?User $user, Organization $organization): array|string { throw new \LogicException('Изменившиеся данные не должны исполняться.'); }
        };
        $registry = Mockery::mock(AIToolRegistry::class);
        $registry->shouldReceive('getTool')->with($tool->getName())->andReturn($tool);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('isMutationTool')->andReturn(true);
        $permissions->shouldReceive('canUseAssistant')->andReturnUsing(fn (User $user, int $organizationId): bool =>
            $user->is_active
            && (int) $user->current_organization_id === $organizationId
            && $user->belongsToOrganization($organizationId)
        );
        $this->app->instance(AIPermissionChecker::class, $permissions);
        $permissions->shouldReceive('canExecuteTool')->andReturn(true);
        $service = new AssistantActionService($registry, $permissions, app(ConversationManager::class), Mockery::mock(LoggingService::class));
        return [$service, $conversation, $actor, $unit, $proposal];
    }
}
