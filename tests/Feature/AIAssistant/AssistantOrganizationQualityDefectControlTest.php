<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetOrganizationQualityDefectControlTool;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationQualityDefectControlReader;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOrganizationQualityDefectControlTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private Project $first;

    private Project $second;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-10-20T20:30:00+00:00'));
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
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_moscow_calendar_date_and_native_states_filter_before_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20T21:30:00+00:00'));
        $open = $this->defect($this->first, ['due_date' => '2026-10-20']);
        $critical = $this->defect($this->second, ['severity' => 'critical', 'due_date' => '2026-10-19']);
        $returned = $this->defect($this->first, ['status' => 'rejected', 'due_date' => '2026-10-18']);
        foreach (['draft', 'resolved', 'cancelled'] as $status) {
            $this->defect($this->first, ['title' => 'SECRET исключённый статус', 'status' => $status]);
        }
        foreach ([null, '2026-10-21', '2026-10-22'] as $date) {
            $this->defect($this->first, ['title' => 'SECRET срок не просрочен', 'severity' => 'critical', 'due_date' => $date]);
        }
        $this->defect($this->first, ['title' => 'SECRET удалённое замечание'])->delete();
        $foreign = Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id, 'is_archived' => false]));
        $this->defect($foreign, ['title' => 'SECRET другая организация']);
        $before = QualityDefect::withTrashed()->count();
        Queue::fake();
        $read = $this->read($this->fixture->owner);
        self::assertSame('2026-10-21', $read['data']['cutoff_date']);
        self::assertSame('Europe/Moscow', $read['data']['timezone']);
        self::assertSame(3, $read['data']['total_count']);
        self::assertSame(1, $read['data']['critical_count']);
        self::assertSame(3, $read['data']['shown_count']);
        self::assertFalse($read['data']['truncated']);
        self::assertEqualsCanonicalizing([$open->id, $critical->id, $returned->id], array_column($read['data']['rows'], 'id'));
        self::assertSame($critical->id, $read['data']['rows'][0]['id']);
        self::assertStringContainsString('Второй объект', $read['server_formatted_answer']);
        self::assertStringContainsString('Возвращен', $read['server_formatted_answer']);
        self::assertStringContainsString('Критический', $read['server_formatted_answer']);
        self::assertStringNotContainsString('SECRET', json_encode($read, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        DB::transaction(function () use ($read): void {
            self::assertSame($read['data'], $this->read($this->fixture->owner)['data']);
            self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
        });
        self::assertSame($before, QualityDefect::withTrashed()->count());
        Queue::assertNothingPushed();
    }

    public function test_totals_precede_the_example_limit_and_undated_critical_rows_are_excluded(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->defect($this->first);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->defect($this->second, ['severity' => 'critical']);
        }
        $this->defect($this->second, ['severity' => 'critical', 'due_date' => null, 'title' => 'SECRET без срока']);
        $read = $this->read($this->fixture->owner);
        self::assertSame(62, $read['data']['total_count']);
        self::assertSame(7, $read['data']['critical_count']);
        self::assertSame(50, $read['data']['shown_count']);
        self::assertTrue($read['data']['truncated']);
        self::assertCount(7, array_filter($read['data']['rows'], static fn (array $row): bool => $row['severity'] === 'critical'));
        self::assertStringContainsString('50 из 62', $read['server_formatted_answer']);
        self::assertStringNotContainsString('SECRET', $read['server_formatted_answer']);
    }

    public function test_assigned_project_scope_and_fresh_role_membership_checks_are_preserved(): void
    {
        $actor = $this->fixture->addMember(['ai-assistant' => ['ai_assistant.chat', 'ai_assistant.analytics.view'],
            'quality-control' => ['quality-control.view']], ['projects.view']);
        $actor->assignedProjects()->attach($this->first->id, ['is_active' => true, 'role' => 'member']);
        $own = $this->defect($this->first);
        $this->defect($this->second, ['title' => 'SECRET неназначенный проект', 'severity' => 'critical']);
        self::assertTrue(app(AIPermissionChecker::class)->canExposeTool($actor, GetOrganizationQualityDefectControlTool::NAME));
        $read = $this->read($actor);
        self::assertSame([$own->id], array_column($read['data']['rows'], 'id'));
        self::assertStringNotContainsString('SECRET', $read['server_formatted_answer']);
        $actor->assignedProjects()->updateExistingPivot($this->first->id, ['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($actor, $this->fixture->organization->id, $read['source_refs']));
        self::assertSame(0, $this->read($actor)['data']['total_count']);
        $actor->assignedProjects()->updateExistingPivot($this->first->id, ['is_active' => true]);
        $read = $this->read($actor);
        $actor->organizations()->updateExistingPivot($this->fixture->organization->id, ['is_active' => false]);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($actor, GetOrganizationQualityDefectControlTool::NAME));
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($actor, $this->fixture->organization->id, $read['source_refs']));
    }

    public function test_missing_quality_permission_denies_exposure_and_direct_execution(): void
    {
        $actor = $this->fixture->member;
        $tool = app(AIToolRegistry::class)->getTool(GetOrganizationQualityDefectControlTool::NAME);
        self::assertNotNull($tool);
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($actor, $tool->getName()));
        $this->expectException(AuthorizationException::class);
        $tool->execute(['scope' => 'organization'], $actor, $this->fixture->organization);
    }

    public function test_cutoff_changes_the_source_version_at_moscow_midnight_even_for_empty_data(): void
    {
        $read = $this->read($this->fixture->owner);
        self::assertSame(0, $read['data']['total_count']);
        self::assertSame('2026-10-20', $read['data']['cutoff_date']);
        Carbon::setTestNow(Carbon::parse('2026-10-20T21:30:00+00:00'));
        $current = $this->read($this->fixture->owner);
        self::assertSame('2026-10-21', $current['data']['cutoff_date']);
        self::assertSame($read['data']['as_of_date'], $current['data']['as_of_date']);
        self::assertNotSame($read['source_refs'][0]['source_version'], $current['source_refs'][0]['source_version']);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($this->fixture->owner, $this->fixture->organization->id, $read['source_refs']));
    }

    public static function organizationQuestions(): array
    {
        return [
            ['Покажи по всей организации незакрытые замечания по качеству, у которых срок устранения раньше сегодняшнего дня. Нужны ID, проект, название, статус, критичность и срок устранения. Отдельно отметь критические замечания. Черновики, устранённые и отменённые исключи. Не ограничивайся выбранным проектом.'],
            ['Дай список незакрытых просроченных замечаний качества по всем доступным проектам, кроме черновиков.'],
        ];
    }

    #[DataProvider('organizationQuestions')]
    public function test_full_ask_reads_other_projects_and_uses_the_verified_server_answer(string $question): void
    {
        $this->defect($this->first);
        $critical = $this->defect($this->second, ['severity' => 'critical', 'title' => 'Просрочено на втором объекте']);
        $this->defect($this->first, ['due_date' => null, 'title' => 'SECRET без срока']);
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        config(['ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true]);
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'quality-'.Str::uuid());
        $provider = $this->createMock(QualityControlResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $calls = 0;
        $provider->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            self::assertLessThanOrEqual(2, ++$calls);
            $output = [];
            if ($calls === 1) {
                self::assertSame(GetOrganizationQualityDefectControlTool::NAME, $options['tool_choice']['function']['name'] ?? null);
                $output[] = ['type' => 'function_call', 'id' => 'quality-control-read', 'call_id' => 'quality-control-read', 'status' => 'completed',
                    'name' => GetOrganizationQualityDefectControlTool::NAME, 'arguments' => '{"scope":"organization"}'];
            }

            return ['content' => $calls === 1 ? '' : 'Непроверенный текст модели.', 'output' => $output, 'function_calls' => $output,
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $question, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => $this->first->id]]]];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $actor, $payload)['quote_id'];
        $queue = Queue::fake();
        $response = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null])
            ->ask($question, $org->id, $actor, null, $payload, 'admin');
        $metadata = $response['message']['metadata'];
        self::assertFalse($metadata['needs_clarification']);
        self::assertSame('partial', $metadata['validation_status']);
        self::assertSame([], $metadata['artifacts']);
        self::assertCount(1, $metadata['source_refs']);
        self::assertSame(AssistantOrganizationQualityDefectControlReader::TYPE, $metadata['source_refs'][0]['entity_type']);
        self::assertNull($metadata['source_refs'][0]['project_id']);
        self::assertStringContainsString('/quality-control/defects/'.$critical->id, $response['message']['content']);
        self::assertStringContainsString('Второй объект', $response['message']['content']);
        self::assertStringContainsString('Всего: 2. Критических: 1.', $response['message']['content']);
        self::assertStringNotContainsString('Непроверенный текст', $response['message']['content']);
        self::assertStringNotContainsString('SECRET', $response['message']['content']);
        self::assertSame([], array_diff(array_keys($queue->pushedJobs()), [BroadcastEvent::class]));
    }

    private function read(User $actor): array
    {
        return app(AssistantOrganizationQualityDefectControlReader::class)->read($actor, $this->fixture->organization->id, ['scope' => 'organization']);
    }

    private function project(string $name): Project
    {
        return Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'name' => $name, 'is_archived' => false]));
    }

    private function defect(Project $project, array $overrides = []): QualityDefect
    {
        return Model::withoutEvents(fn () => QualityDefect::query()->create(array_replace([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'created_by' => (int) $project->organization_id === (int) $this->fixture->organization->id ? $this->fixture->owner->id : $this->fixture->foreignOwner->id,
            'defect_number' => 'QUALITY-'.Str::uuid(), 'title' => 'Просроченное замечание',
            'status' => 'open', 'severity' => 'minor', 'due_date' => '2026-10-17',
        ], $overrides)));
    }
}

interface QualityControlResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
