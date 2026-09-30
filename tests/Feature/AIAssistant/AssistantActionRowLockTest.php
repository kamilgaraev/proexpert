<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Models\AIPendingAction;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class AssistantActionRowLockTest extends TestCase
{
    private ?string $connectionName = null;
    private ?array $originalConfiguration = null;
    private ?string $privateSchema = null;
    private string $contenderConnection = 'assistant_action_lock_contender';

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalConfiguration = config('database.connections.'.$this->connectionName);
        $configuration = IsolatedPostgresTestDatabase::configuration();
        $this->privateSchema = $configuration['schema'];
        $this->assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}$/D', $this->privateSchema);
        config()->set('database.connections.'.$this->connectionName, $configuration);
        config()->set('database.connections.'.$this->contenderConnection, $configuration);
        DB::purge($this->connectionName);
        DB::purge($this->contenderConnection);
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->boolean('is_active'); $table->unsignedBigInteger('current_organization_id'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('organization_id'); $table->unsignedBigInteger('user_id'); $table->boolean('is_active'); $table->boolean('is_owner'); $table->timestamps();
        });
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('organization_id'); $table->unsignedBigInteger('user_id'); $table->jsonb('context')->nullable(); $table->unsignedInteger('context_version')->default(1); $table->timestamps();
        });
        Schema::create('ai_conversation_participants', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('conversation_id'); $table->unsignedBigInteger('user_id'); $table->string('role'); $table->timestamps();
        });
        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('conversation_id'); $table->string('role'); $table->text('content'); $table->jsonb('metadata'); $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('qa_action_effects', function (Blueprint $table): void {
            $table->id(); $table->string('name');
        });
        (require base_path('app/BusinessModules/Features/AIAssistant/migrations/2026_09_29_000002_create_ai_pending_actions_table.php'))->up();
        (require base_path('app/BusinessModules/Features/AIAssistant/migrations/2026_09_29_000011_bind_ai_pending_actions_to_origin_requests.php'))->up();
    }

    protected function tearDown(): void
    {
        try {
            DB::purge($this->contenderConnection);
            config()->offsetUnset('database.connections.'.$this->contenderConnection);
            if ($this->connectionName !== null && $this->privateSchema !== null && preg_match('/^most_phpunit_[a-f0-9]{24}$/D', $this->privateSchema) === 1) {
                DB::connection($this->connectionName)->statement('DROP SCHEMA IF EXISTS "'.$this->privateSchema.'" CASCADE');
            }
        } finally {
            if ($this->connectionName !== null && $this->originalConfiguration !== null) {
                DB::purge($this->connectionName);
                config()->set('database.connections.'.$this->connectionName, $this->originalConfiguration);
                DB::connection($this->connectionName);
            }
            parent::tearDown();
        }
    }

    public function test_confirmation_holds_pending_row_lock_during_business_operation_and_replays_once(): void
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Блокировка подтверждения']);
        $actorId = DB::table('users')->insertGetId(['name' => 'Автор', 'is_active' => true, 'current_organization_id' => $organizationId]);
        DB::table('organization_user')->insert(['organization_id' => $organizationId, 'user_id' => $actorId, 'is_active' => true, 'is_owner' => true]);
        $actor = User::query()->findOrFail($actorId);
        $conversation = Conversation::query()->create(['organization_id' => $organizationId, 'user_id' => $actorId, 'context' => []]);
        $requestId = (string) Str::uuid();
        $proposal = ['tool_name' => 'create_measurement_unit', 'arguments' => ['name' => 'Упаковка'], 'allowed' => true];
        $conversation->messages()->create(['role' => 'user', 'content' => 'Создай единицу измерения Упаковка', 'metadata' => [
            'request_id' => $requestId, 'actor_user_id' => $actorId, 'request' => ['message' => 'Создай единицу измерения Упаковка', 'allow_actions' => true],
        ]]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Предлагаю подтверждение', 'metadata' => [
            'request_id' => $requestId, 'actor_user_id' => $actorId, 'proposed_actions' => [$proposal],
        ]]);
        $permissions = $this->createPartialMock(AIPermissionChecker::class, ['canUseAssistant', 'canExecuteTool']);
        $permissions->method('canUseAssistant')->willReturnCallback(static fn (User $user, int $org): bool => $user->is_active && (int) $user->current_organization_id === $org && $user->belongsToOrganization($org));
        $permissions->method('canExecuteTool')->willReturnCallback(static fn (User $user, string $name, array $arguments): bool => (int) $user->id === $actorId && $name === $proposal['tool_name'] && $arguments === $proposal['arguments']);
        $this->app->instance(AIPermissionChecker::class, $permissions);
        $calls = 0;
        $pendingId = '';
        $lockState = null;
        $contender = DB::connection($this->contenderConnection);
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('create_measurement_unit');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]);
        $tool->expects($this->once())->method('execute')->willReturnCallback(function (array $arguments, ?User $user, Organization $organization) use ($contender, &$pendingId, &$calls, &$lockState): array {
            $calls++;
            $this->assertSame(1, DB::connection()->transactionLevel());
            try {
                $contender->transaction(fn () => $contender->select('SELECT id FROM ai_pending_actions WHERE id = ? FOR UPDATE NOWAIT', [$pendingId]));
                $this->fail('Second PostgreSQL connection acquired the action row during business execution.');
            } catch (QueryException $exception) {
                $lockState = $exception->errorInfo[0] ?? null;
                $this->assertSame('55P03', $lockState);
            }
            DB::table('qa_action_effects')->insert(['name' => $arguments['name']]);
            return ['message' => 'Создано'];
        });
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $policy = new AssistantDataAccessPolicy($this->createMock(AuthorizationService::class), $this->createMock(UserProjectAccessService::class));
        $service = new AssistantActionService($registry, $permissions, new ConversationManager($policy), $this->createMock(LoggingService::class));
        $preview = $service->preview($proposal, $organizationId, $actor, $conversation);
        $pendingId = $preview['action']['id'];
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame($pendingId, $contender->table('ai_pending_actions')->where('id', $pendingId)->value('id'));
        $this->assertNotSame(DB::connection()->getPdo(), $contender->getPdo());
        $payload = ['id' => $pendingId, 'preview_token' => $preview['preview_token'], 'confirmed' => true];
        $first = $service->execute($payload, $organizationId, $actor, $conversation);
        $replay = $service->execute($payload, $organizationId, $actor, $conversation);
        $this->assertSame('55P03', $lockState);
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table('qa_action_effects')->count());
        $this->assertSame('Упаковка', DB::table('qa_action_effects')->value('name'));
        $this->assertSame($first['result'], $replay['result']);
        $this->assertSame($first['action']['id'], $replay['action']['id']);
        $this->assertEquals($first, $replay);
        $this->assertSame('executed', AIPendingAction::query()->findOrFail($pendingId)->status);
        $unlocked = $contender->transaction(fn () => $contender->select('SELECT id FROM ai_pending_actions WHERE id = ? FOR UPDATE NOWAIT', [$pendingId]));
        $this->assertSame($pendingId, $unlocked[0]->id);
    }
}
