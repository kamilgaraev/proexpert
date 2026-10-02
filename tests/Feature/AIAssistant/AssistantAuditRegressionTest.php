<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\CreateScheduleTaskTool;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly\GetProjectSnapshotTool;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\AIUsageRecord;
use App\BusinessModules\Features\AIAssistant\Models\AIUsageStats;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseFactCollector;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseFactSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseWorkFactSource;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditWallet;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_proposal_preview_execute_and_multicall_usage_with_exact_reserved_balance(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        [$project, $schedule] = $this->schedule($fixture->organization, $fixture->owner);
        $parent = ScheduleTask::query()->create(['organization_id' => $fixture->organization->id, 'schedule_id' => $schedule->id,
            'created_by_user_id' => $fixture->owner->id, 'name' => 'Родитель своего графика', 'task_type' => 'summary',
            'planned_start_date' => '2026-10-10', 'planned_end_date' => '2026-10-11', 'status' => 'not_started', 'priority' => 'normal']);
        $arguments = ['project_id' => $project->id, 'schedule_id' => $schedule->id, 'name' => 'Монтаж',
            'parent_task_id' => $parent->id, 'planned_start_date' => '2026-10-02', 'planned_end_date' => '2026-10-03'];
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $responses = [
            ['content' => '', 'tool_calls' => [['id' => 'create-1', 'function' => ['name' => 'create_schedule_task', 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)]]],
                'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'input_tokens' => 100, 'output_tokens' => 10, 'tokens_used' => 110],
            ['content' => 'Подготовлено действие для подтверждения.', 'tool_calls' => [], 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna', 'input_tokens' => 200, 'output_tokens' => 20, 'tokens_used' => 220],
        ];
        $call = 0;
        $provider->expects($this->exactly(2))->method('chat')->willReturnCallback(function () use (&$call, $fixture, $responses): array {
            self::assertSame(0, app(AICreditService::class)->balance($fixture->organization)['available_minor']);
            return $responses[$call++];
        });
        $this->app->instance(LLMProviderInterface::class, $provider);
        $credits = app(AICreditService::class);
        config()->set('ai-assistant-credits.enforce', true);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Создай задачу Монтаж в графике работ', 'profile' => 'normal',
            'allow_actions' => true, 'context' => ['source_module' => 'schedules', 'entity_refs' => [['type' => 'project', 'id' => $project->id], ['type' => 'schedule', 'id' => $schedule->id]]]];
        $quote = $credits->quote($fixture->organization, $fixture->owner, $payload);
        $credits->grant($fixture->organization, (int) $quote['max_units_minor'], 'purchase', null, 'exact-audit-budget');
        AICreditWallet::query()->where('organization_id', $fixture->organization->id)->update(['balance_minor' => $quote['max_units_minor']]);
        AICreditLot::query()->where('organization_id', $fixture->organization->id)->update(['remaining_minor' => 0]);
        AICreditLot::query()->where('organization_id', $fixture->organization->id)->latest('id')->firstOrFail()->update(['remaining_minor' => $quote['max_units_minor']]);
        $result = app(AuditFlowAIAssistantService::class)->ask($payload['message'], $fixture->organization->id, $fixture->owner, null, $payload + ['quote_id' => $quote['quote_id']], 'admin');
        self::assertSame('completed', $result['status']);
        self::assertSame(330, $result['tokens_used']);
        self::assertSame(0, ScheduleTask::query()->where('name', 'Монтаж')->count());
        $proposal = collect($result['message']['metadata']['proposed_actions'])->firstWhere('tool_name', 'create_schedule_task');
        self::assertNotNull($proposal, json_encode($result['message']['metadata'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertTrue($proposal['allowed']);
        self::assertSame($payload['request_id'], $proposal['origin_request_id']);
        $conversation = Conversation::query()->findOrFail($result['conversation_id']);
        $actions = app(AssistantActionService::class);
        $preview = $actions->preview($proposal, $fixture->organization->id, $fixture->owner, $conversation);
        self::assertNotSame($proposal['id'], $preview['action']['id']);
        $execution = ['id' => $preview['action']['id'], 'preview_token' => $preview['preview_token'], 'confirmed' => true];
        $executed = $actions->execute($execution, $fixture->organization->id, $fixture->owner, $conversation);
        self::assertSame('success', $executed['result']['status']);
        self::assertSame(1, ScheduleTask::query()->where('name', 'Монтаж')->count());
        self::assertSame($parent->id, ScheduleTask::query()->where('name', 'Монтаж')->sole()->parent_task_id);
        $actions->execute($execution, $fixture->organization->id, $fixture->owner, $conversation);
        self::assertSame(1, ScheduleTask::query()->where('name', 'Монтаж')->count());
        $stats = AIUsageStats::query()->where('organization_id', $fixture->organization->id)->sole();
        $records = AIUsageRecord::query()->where('metadata->request_id', $payload['request_id'])->get();
        self::assertCount(2, $records);
        self::assertSame(330, (int) $stats->tokens_used);
        self::assertSame(1, (int) $stats->requests_count);
        self::assertEqualsWithDelta((float) $records->sum('total_cost_rub'), (float) $stats->cost_rub, 0.000001);
        self::assertSame(2, AICreditProviderUsage::query()->count());
        self::assertSame(0, $credits->balance($fixture->organization)['reserved_minor']);
    }

    #[DataProvider('invalidParents')]
    public function test_parent_scope_and_type_are_checked_even_when_outer_acl_is_permissive(string $scope): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        [$project, $schedule] = $this->schedule($fixture->organization, $fixture->owner);
        $parentOrganization = $scope === 'foreign' ? $fixture->foreignOrganization : $fixture->organization;
        $parentActor = $scope === 'foreign' ? $fixture->foreignOwner : $fixture->owner;
        [, $parentSchedule] = $scope === 'type' ? [$project, $schedule] : $this->schedule($parentOrganization, $parentActor);
        $parent = ScheduleTask::query()->create(['organization_id' => $parentOrganization->id, 'schedule_id' => $parentSchedule->id,
            'created_by_user_id' => $parentActor->id, 'name' => 'Родитель', 'task_type' => $scope === 'type' ? 'milestone' : 'summary',
            'planned_start_date' => '2026-10-02', 'planned_end_date' => '2026-10-02', 'status' => 'not_started', 'priority' => 'normal']);
        $before = $parent->fresh()->getAttributes();
        $arguments = ['project_id' => $project->id, 'schedule_id' => $schedule->id, 'parent_task_id' => $parent->id,
            'name' => 'Недопустимая задача', 'planned_start_date' => '2026-11-01', 'planned_end_date' => '2026-11-02'];
        self::assertFalse(app(AIPermissionChecker::class)->canExecuteTool($fixture->owner, 'create_schedule_task', $arguments));
        $permissions = $this->mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canExecuteTool')->andReturn(true);
        $result = app(CreateScheduleTaskTool::class)->execute($arguments, $fixture->owner, $fixture->organization);
        self::assertSame('error', $result['status']);
        self::assertSame(0, ScheduleTask::query()->where('name', 'Недопустимая задача')->count());
        self::assertSame($before, $parent->fresh()->getAttributes());
    }

    public static function invalidParents(): array { return [['foreign'], ['schedule'], ['type']]; }

    public function test_project_finance_uses_confirmed_work_fallback_and_pending_acts(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        [$project] = $this->schedule($fixture->organization, $fixture->owner);
        $unit = DB::table('measurement_units')->insertGetId(['organization_id' => $fixture->organization->id, 'name' => 'Метр тестовый', 'short_name' => 'м-аудит', 'type' => 'work']);
        $type = DB::table('work_types')->insertGetId(['organization_id' => $fixture->organization->id, 'name' => 'Монтаж', 'measurement_unit_id' => $unit, 'is_active' => true]);
        foreach (['confirmed', 'draft', 'pending', 'cancelled', 'rejected'] as $status) {
            DB::table('completed_works')->insert(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'work_type_id' => $type,
                'user_id' => $fixture->owner->id, 'quantity' => 2, 'price' => 50, 'total_amount' => $status === 'confirmed' ? null : 1000,
                'completion_date' => '2026-10-01', 'status' => $status]);
        }
        DB::table('completed_works')->insert(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'work_type_id' => $type,
            'user_id' => $fixture->owner->id, 'quantity' => 2, 'price' => 50, 'total_amount' => 300, 'completion_date' => '2026-10-01', 'status' => 'confirmed']);
        $contractor = DB::table('contractors')->insertGetId(['organization_id' => $fixture->organization->id, 'name' => 'Подрядчик']);
        $contract = DB::table('contracts')->insertGetId(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor, 'number' => 'AUDIT', 'date' => '2026-10-01', 'total_amount' => 1000, 'currency' => 'RUB']);
        foreach ([['draft', false, 120], ['pending_approval', false, 80], ['approved', true, 900], ['cancelled', false, 700]] as [$status, $approved, $amount]) {
            DB::table('contract_performance_acts')->insert(['contract_id' => $contract, 'project_id' => $project->id, 'act_date' => '2026-10-01', 'amount' => $amount, 'currency' => 'RUB', 'status' => $status, 'is_approved' => $approved]);
        }
        $context = ProjectPulseContext::fromValidated(['project_id' => $project->id, 'date' => '2026-10-01'], $fixture->organization->id, $fixture->owner->id);
        $finance = (new ProjectPulseFactCollector(new ProjectPulseFactSourceRegistry([])))->finance($context);
        self::assertSame(400.0, $finance['performed_amount']);
        self::assertSame(200.0, $finance['pending_acts_amount']);
        $facts = (new ProjectPulseWorkFactSource)->collect($context);
        self::assertCount(2, $facts);
        self::assertSame(400.0, (float) $facts->sum('amount'));
        DB::table('contracts')->insert(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor, 'number' => 'AUDIT-USD', 'date' => '2026-10-01', 'total_amount' => 1000, 'currency' => 'USD']);
        $snapshot = (new \ReflectionMethod(GetProjectSnapshotTool::class, 'contractSummary'))->invoke(new GetProjectSnapshotTool, $fixture->organization, $project->id, $fixture->owner);
        self::assertNull($snapshot['total_amount']);
        self::assertSame([['currency' => 'RUB', 'total_amount' => 1000.0], ['currency' => 'USD', 'total_amount' => 1000.0]], $snapshot['amounts_by_currency']);
    }

    private function schedule(Organization $organization, User $actor): array
    {
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active', 'is_archived' => false]);
        $schedule = ProjectSchedule::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'created_by_user_id' => $actor->id, 'name' => 'График аудита', 'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-12-01', 'status' => 'draft']);
        return [$project, $schedule];
    }
}

final class AuditFlowAIAssistantService extends AIAssistantService
{
    protected function buildRagContext(string $query, int $organizationId, User $user, array $taskPlan, array $requestPayload): array
    {
        return ['prompt' => '', 'metadata' => ['used' => false, 'sources' => []]];
    }

    protected function handleAgentFlow(string $query, int $organizationId, User $user, Conversation $conversation, array $taskPlan): ?array
    {
        return null;
    }
}
