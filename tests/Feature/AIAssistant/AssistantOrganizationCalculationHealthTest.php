<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetOrganizationCalculationHealthTool;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationCalculationHealthReader;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartHealthService;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartPayloadProjector;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOrganizationCalculationHealthTest extends TestCase
{
    private const QUERY = 'Почему отчёт о здоровье портфеля по всей нашей организации не строится? Проверь, запускалась ли подготовка расчётных данных в бюджетировании и были ли успешные расчёты. Ничего не пересчитывай. Объясни, что руководителю сделать дальше.';

    private AssistantRealAuthorizationFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 10:00:00');
        config(['cache.default' => 'array']);
        Queue::fake();
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_native_calculation_health_is_registered_as_an_authorized_organization_read(): void
    {
        $tool = app(AIToolRegistry::class)->getTool('get_organization_calculation_health');
        self::assertNotNull($tool, 'Native calculation health must be available through the real tool registry.');
        self::assertTrue(app(AIPermissionChecker::class)->canExposeTool($this->fixture->owner, $tool->getName()));
    }

    public function test_empty_native_health_is_actionable_and_does_not_claim_all_time_history(): void
    {
        $read = $this->read($this->fixture->owner);
        self::assertSame('unavailable', $read['data']['state']);
        self::assertSame(0, array_sum($read['data']['counts']));
        self::assertNull($read['data']['last_attempt_at']);
        self::assertNull($read['data']['last_success_at']);
        self::assertStringContainsString('В проверенной истории попыток подготовки не найдено', $read['server_formatted_answer']);
        self::assertStringContainsString('Финансовый центр', $read['server_formatted_answer']);
        self::assertStringContainsString('сама по себе не подтверждает готовность', $read['server_formatted_answer']);
        self::assertStringContainsString('не запускает подготовку', $read['server_formatted_answer']);
        self::assertStringNotContainsString('никогда', $read['server_formatted_answer']);
        Queue::assertNothingPushed();
    }

    public function test_actual_health_matches_native_source_without_opaque_scope_payload_or_writes(): void
    {
        $own = $this->fixture->organization->id;
        $this->snapshot($own, 'own-success');
        $this->createRun($own, 'own-success', 'succeeded');
        $this->createRun($own, 'own-queued', 'queued');
        $this->createRun($own, 'own-failed', 'failed');
        $this->snapshot($this->fixture->foreignOrganization->id, 'foreign');
        $before = [EpmDataMartSnapshot::query()->count(), EpmDataMartRecalculationRun::query()->count()];
        $legacy = app(EpmDataMartHealthService::class)->buildPayload(
            EpmDataMartSnapshot::query()->where('organization_id', $own)->whereNull('superseded_at')->orderByDesc('generated_at')->orderByDesc('id')->limit(1000)->get()->all(),
            EpmDataMartRecalculationRun::query()->where('organization_id', $own)->orderByDesc('id')->limit(1000)->get()->all(),
        );
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'epm_data_mart_snapshots') || str_contains($query->sql, 'epm_data_mart_recalculation_runs')) {
                $queries[] = $query->sql;
            }
        });
        $native = app(EpmDataMartHealthService::class)->status($own);
        self::assertSame($legacy, $native);
        self::assertCount(1, $queries);
        self::assertStringContainsString('union all', $queries[0]);
        self::assertStringContainsString('epm_data_mart_snapshots', $queries[0]);
        self::assertStringContainsString('epm_data_mart_recalculation_runs', $queries[0]);
        $read = $this->read($this->fixture->owner);
        self::assertSame($native['counts'], $read['data']['counts']);
        self::assertSame($native['status'], $read['data']['state']);
        self::assertSame($native['last_success_at'], $read['data']['last_success_at']);
        self::assertSame($native['last_attempt_at'], $read['data']['last_attempt_at']);
        self::assertSame(1, $read['data']['counts']['succeeded']);
        self::assertSame(1, $read['data']['counts']['queued']);
        self::assertSame(1, $read['data']['counts']['failed']);
        self::assertSame($before, [EpmDataMartSnapshot::query()->count(), EpmDataMartRecalculationRun::query()->count()]);
        $json = json_encode($read, JSON_THROW_ON_ERROR);
        foreach (['SECRET-PAYLOAD', 'SECRET-ERROR', 'SECRET-SCOPE', 'scope_hash', 'failed_scopes', 'source_hash'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $own, $read['source_refs']));
        $this->createRun($own, 'new-running', 'running');
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $own, $read['source_refs']));
        Queue::assertNothingPushed();
    }

    public function test_history_limit_counts_latest_scopes_and_is_explained_as_bounded(): void
    {
        config(['budgeting.epm_data_mart.health_history_limit' => 1]);
        for ($i = 0; $i < 51; $i++) {
            $this->snapshot($this->fixture->organization->id, 'scope-'.$i, ['generated_at' => now()->subSeconds($i)]);
        }
        $read = $this->read($this->fixture->owner);
        self::assertSame(50, $read['data']['history_limit']);
        self::assertSame(50, $read['data']['counts']['succeeded']);
        self::assertStringContainsString('до 50 последних записей', $read['server_formatted_answer']);
        self::assertStringContainsString('не общее количество запусков', $read['server_formatted_answer']);
    }

    public function test_atomic_native_read_preserves_snapshot_and_run_filters(): void
    {
        $org = $this->fixture->organization->id;
        $this->snapshot($org, 'rub', ['currency' => 'RUB', 'as_of_date' => '2026-10-18']);
        $this->snapshot($org, 'usd', ['currency' => 'USD', 'as_of_date' => '2026-10-18']);
        $this->snapshot($org, 'old', ['currency' => 'RUB', 'as_of_date' => '2026-10-17']);
        $this->snapshot($org, 'superseded', ['currency' => 'RUB', 'as_of_date' => '2026-10-18', 'superseded_at' => now()]);
        $this->createRun($org, 'rub-run', 'queued', ['filters' => ['currency' => 'RUB', 'as_of_date' => '2026-10-18']]);
        $this->createRun($org, 'usd-run', 'failed', ['filters' => ['currency' => 'USD', 'as_of_date' => '2026-10-18']]);
        foreach (['RUB', 'USD'] as $currency) {
            $expected = app(EpmDataMartHealthService::class)->buildPayload(
                EpmDataMartSnapshot::query()->where('organization_id', $org)->whereNull('superseded_at')->where('currency', $currency)->whereDate('as_of_date', '2026-10-18')->orderByDesc('generated_at')->orderByDesc('id')->get()->all(),
                EpmDataMartRecalculationRun::query()->where('organization_id', $org)->where('filters->currency', $currency)->where('filters->as_of_date', '2026-10-18')->orderByDesc('id')->get()->all(),
            );
            self::assertSame($expected, app(EpmDataMartHealthService::class)->status($org, ['currency' => $currency, 'as_of_date' => '2026-10-18']));
        }
    }

    public function test_real_tool_policy_requires_cfo_view_and_all_project_access_without_plan_fact_view(): void
    {
        $checker = app(AIPermissionChecker::class);
        $registry = app(AIToolRegistry::class);
        $name = GetOrganizationCalculationHealthTool::NAME;
        self::assertTrue($checker->hasExplicitToolPolicy($name));
        self::assertTrue($checker->canExposeTool($this->fixture->owner, $name));
        self::assertTrue($checker->canExecuteTool($this->fixture->owner, $name, ['scope' => 'organization']));
        self::assertInstanceOf(GetOrganizationCalculationHealthTool::class, $registry->getTool($name));
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'], 'budgeting' => ['budgeting.cfo.view']]]);
        self::assertFalse($checker->canExposeTool($this->fixture->member, $name));
        self::assertFalse($checker->canExecuteTool($this->fixture->member, $name, ['scope' => 'organization']));
        $this->fixture->member->organizations()->updateExistingPivot($this->fixture->organization->id, ['project_access_mode' => 'all_projects']);
        self::assertTrue($checker->canExposeTool($this->fixture->member, $name));
        self::assertTrue($checker->canExecuteTool($this->fixture->member, $name, ['scope' => 'organization']));
        $read = $registry->getTool($name)->execute(['scope' => 'organization'], $this->fixture->member, $this->fixture->organization);
        self::assertFalse($read['data']['can_prepare']);
        self::assertStringContainsString('финансовый администратор', $read['server_formatted_answer']);
        $this->fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat']]]);
        self::assertFalse($checker->canExposeTool($this->fixture->member, $name));
        self::assertFalse($checker->canExecuteTool($this->fixture->member, $name, ['scope' => 'organization']));
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->member, $this->fixture->organization->id, $read['source_refs']));
        $this->expectException(AuthorizationException::class);
        $this->read($this->fixture->member);
    }

    public function test_status_intent_does_not_capture_recalculation_project_scope_or_file_generation(): void
    {
        $query = self::QUERY;
        self::assertSame(GetOrganizationCalculationHealthTool::NAME, AssistantOperationalReaderRegistry::intent($query)['name']);
        self::assertSame(['name' => GetOrganizationCalculationHealthTool::NAME, 'arguments' => ['scope' => 'organization']], AssistantRequiredProjectRead::fromPlan([
            'request' => ['message' => $query, 'context' => ['entity_refs' => [['entity_type' => 'project', 'entity_id' => 104]]]],
        ]));
        foreach (['Запусти подготовку управленческих данных всей организации.',
            'Пересчитывай управленческие данные всей организации.',
            'Обновляй управленческие данные всей организации.',
            'Проверь расчётные данные проекта «Северный дом».',
            'Проверь подготовку управленческих данных всей организации и проекта «Северный дом».',
            'Проверь подготовку управленческих данных по всем проектам, кроме проекта «Северный дом».',
            'Проверь подготовку управленческих данных всей организации и сделай Excel.',
            'Проверь подготовку управленческих данных и покажи расходы по договорам.',
            'Проверь подготовку управленческих данных и расходы организации.',
            'Проверь подготовку управленческих данных и размер прибыли.'] as $mixed) {
            self::assertFalse(AssistantOrganizationCalculationHealthReader::matches($mixed), $mixed);
            self::assertNotSame(GetOrganizationCalculationHealthTool::NAME, AssistantOperationalReaderRegistry::intent($mixed)['name'] ?? null, $mixed);
        }
        self::assertTrue(AssistantOrganizationCalculationHealthReader::matches('Проверь подготовку управленческих данных по всем проектам организации.'));
    }

    public function test_original_question_completes_with_real_health_source_despite_background_ui_project(): void
    {
        config(['ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true]);
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        $this->actingAs($actor);
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'health-'.Str::uuid());
        $ui = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]));
        $before = [EpmDataMartSnapshot::query()->count(), EpmDataMartRecalculationRun::query()->count()];
        $calls = 0;
        $provider = $this->createMock(CalculationHealthResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->expects(self::atLeastOnce())->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            self::assertLessThanOrEqual(2, ++$calls);
            self::assertContains(GetOrganizationCalculationHealthTool::NAME, array_column($options['tools'] ?? [], 'name'));
            $output = [];
            if ($calls === 1) {
                self::assertSame(['type' => 'function', 'function' => ['name' => GetOrganizationCalculationHealthTool::NAME]], $options['tool_choice'] ?? null);
                $output[] = ['type' => 'function_call', 'id' => 'health-read', 'call_id' => 'health-read', 'status' => 'completed',
                    'name' => GetOrganizationCalculationHealthTool::NAME, 'arguments' => '{"scope":"organization"}'];
            }

            return ['content' => $calls === 1 ? '' : 'Непроверенный текст модели.', 'output' => $output, 'function_calls' => $output,
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $payload = ['request_id' => (string) Str::uuid(), 'message' => self::QUERY, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => $ui->id]]]];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $actor, $payload)['quote_id'];
        $response = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null])->ask(self::QUERY, $org->id, $actor, null, $payload);
        $request = AssistantRequest::query()->where('request_id', $response['request_id'])->sole();
        self::assertSame('completed', $request->status);
        $metadata = $response['message']['metadata'];
        self::assertFalse($metadata['needs_clarification']);
        self::assertSame('partial', $metadata['validation_status']);
        self::assertSame([], $metadata['artifacts']);
        self::assertCount(1, $metadata['source_refs']);
        self::assertSame(AssistantOrganizationCalculationHealthReader::TYPE, $metadata['source_refs'][0]['entity_type']);
        self::assertNull($metadata['source_refs'][0]['project_id']);
        self::assertSame($org->id, $metadata['source_refs'][0]['organization_id']);
        self::assertStringContainsString('В проверенной истории попыток подготовки не найдено', $response['message']['content']);
        self::assertStringNotContainsString('Непроверенный текст модели', $response['message']['content']);
        self::assertSame($before, [EpmDataMartSnapshot::query()->count(), EpmDataMartRecalculationRun::query()->count()]);
        Queue::assertNothingPushed();
    }

    public function test_health_source_remains_readable_inside_request_completion_transaction(): void
    {
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        $read = $this->read($actor);
        DB::transaction(function () use ($actor, $org, $read): void {
            self::assertSame($read['data'], $this->read($actor)['data']);
            self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($actor, $org->id, $read['source_refs']));
        });
    }

    public function test_references_are_actor_scoped_and_membership_revocation_denies_fresh_reads(): void
    {
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        $read = $this->read($actor);
        $guard = app(AssistantSourceReferenceGuard::class);
        self::assertTrue($guard->fresh($actor, $org->id, $read['source_refs']));
        self::assertFalse($guard->fresh($this->fixture->foreignOwner, $org->id, $read['source_refs']));
        $actor->organizations()->updateExistingPivot($org->id, ['is_active' => false]);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($actor, GetOrganizationCalculationHealthTool::NAME));
        self::assertFalse(app(AIPermissionChecker::class)->canExecuteTool($actor, GetOrganizationCalculationHealthTool::NAME, ['scope' => 'organization']));
        self::assertFalse($guard->fresh($actor, $org->id, $read['source_refs']));
        $this->expectException(AuthorizationException::class);
        $this->read($actor);
    }

    public function test_module_revocation_denies_exposure_execution_and_existing_source(): void
    {
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        $read = $this->read($actor);
        \App\Models\Module::query()->where('slug', 'budgeting')->update(['is_active' => false]);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($actor, GetOrganizationCalculationHealthTool::NAME));
        self::assertFalse(app(AIPermissionChecker::class)->canExecuteTool($actor, GetOrganizationCalculationHealthTool::NAME, ['scope' => 'organization']));
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($actor, $org->id, $read['source_refs']));
        $this->expectException(AuthorizationException::class);
        $this->read($actor);
    }

    private function read(User $actor): array
    {
        return app(AssistantOrganizationCalculationHealthReader::class)->read($actor, $this->fixture->organization->id, ['scope' => 'organization']);
    }

    private function snapshot(int $organizationId, string $scope, array $overrides = []): void
    {
        Model::withoutEvents(fn () => EpmDataMartSnapshot::query()->create(array_replace(['uuid' => (string) Str::uuid(), 'organization_id' => $organizationId,
            'report_scope' => 'cfo_command_center', 'scope_hash' => hash('sha256', 'SECRET-SCOPE-'.$scope), 'status' => 'succeeded',
            'formula_version' => EpmDataMartPayloadProjector::FORMULA_VERSION, 'source_hash' => hash('sha256', 'fixture'), 'filters' => [],
            'payload' => ['private' => 'SECRET-PAYLOAD'], 'freshness' => [], 'source_refs' => [], 'generated_at' => now()], $overrides)));
    }

    private function createRun(int $organizationId, string $scope, string $status, array $overrides = []): void
    {
        Model::withoutEvents(fn () => EpmDataMartRecalculationRun::query()->create(array_replace(['uuid' => (string) Str::uuid(), 'organization_id' => $organizationId,
            'report_scope' => 'cfo_command_center', 'scope_hash' => hash('sha256', 'SECRET-SCOPE-'.$scope), 'status' => $status,
            'formula_version' => EpmDataMartPayloadProjector::FORMULA_VERSION, 'filters' => [], 'queued_at' => now(),
            'error_summary' => ['message' => 'SECRET-ERROR']], $overrides)));
    }
}

interface CalculationHealthResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
