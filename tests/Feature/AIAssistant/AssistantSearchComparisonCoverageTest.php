<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantSearchComparisonCoverageTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    private User $actor;

    private Project $project;

    private Project $secondProject;

    private ?AIAssistantService $service = null;

    private array $nextSearches = [];

    private bool $emitSearches = false;

    private bool $oneSearchPerTurn = false;

    private int $emittedSearches = 0;

    private array $observedResults = [];

    private ?\Closure $beforeFinal = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant-credits.profiles.normal.max_calls' => 3]);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->actor = Model::withoutEvents(fn () => $this->fixture->addMember([
            'ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'contract-management' => ['contracts.view', 'contracts.performance_acts.view'],
        ]));
        $this->project = Model::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false,
        ]));
        $this->actor->assignedProjects()->attach($this->project->id, ['is_active' => true, 'role' => 'member']);
        $this->secondProject = Model::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false,
        ]));
        $this->actor->assignedProjects()->attach($this->secondProject->id, ['is_active' => true, 'role' => 'member']);
        $this->actingAs($this->actor);
        $this->act($this->project);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'comparison-'.Str::uuid());
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

    public function test_no_searches_cannot_publish_a_completed_organization_comparison(): void
    {
        $response = $this->ask([]);
        $metadata = $response['message']['metadata'];
        self::assertTrue($metadata['needs_clarification'], json_encode($metadata, JSON_UNESCAPED_UNICODE));
        self::assertSame([], $metadata['source_refs']);
        self::assertSame('partial', $metadata['validation_status']);
        self::assertTrue($metadata['degraded_mode']);
        self::assertSame([], $this->observedResults);
        self::assertStringContainsString(trans_message('ai_assistant_facts.comparison_scope_incomplete'), $response['message']['content']);
    }

    public function test_non_comparison_without_searches_does_not_require_scope_clarification(): void
    {
        $response = $this->ask([], 'Какие у тебя возможности?');
        self::assertStringNotContainsString(trans_message('ai_assistant_facts.comparison_scope_incomplete'), $response['message']['content']);
        self::assertSame([], $this->observedResults);
    }

    public function test_project_only_search_cannot_publish_a_completed_organization_comparison(): void
    {
        $response = $this->ask([$this->arguments($this->project->id)]);
        $metadata = $response['message']['metadata'];
        self::assertTrue($metadata['needs_clarification'], json_encode($metadata, JSON_UNESCAPED_UNICODE));
        self::assertSame([], $metadata['source_refs']);
        self::assertSame('partial', $metadata['validation_status']);
        self::assertSame([1], array_column(array_column($this->observedResults, 'result_window'), 'total'));
    }

    public static function incompleteCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['duplicate_project', 'organization_only',
            'missing_second_project', 'different_queries', 'different_fields', 'failed_target']);
    }

    #[DataProvider('incompleteCases')]
    public function test_incomplete_or_unrelated_searches_do_not_publish_a_comparison(string $case): void
    {
        $organization = $this->arguments(null);
        $project = $this->arguments($this->project->id);
        $question = null;
        $searches = match ($case) {
            'duplicate_project' => [$project, $project],
            'organization_only' => [$organization],
            'different_queries' => [array_replace($organization, ['query' => 'missing']), $project],
            'different_fields' => [$organization, array_replace($project, ['fields' => ['id']])],
            'failed_target' => [array_replace($project, ['query' => 'target', 'cursor' => 'invalid']), $organization, $project],
            default => [$organization, $project],
        };
        if ($case === 'missing_second_project') {
            $question = 'Сравни все акты организации с проектами '.$this->project->id.' и '.$this->secondProject->id;
        }
        $response = $this->ask($searches, $question);
        self::assertTrue($response['message']['metadata']['needs_clarification']);
        self::assertSame([], $response['message']['metadata']['source_refs']);
        self::assertSame('partial', $response['message']['metadata']['validation_status']);
        self::assertStringContainsString(trans_message('ai_assistant_facts.comparison_scope_incomplete'), $response['message']['content']);
        self::assertNotEmpty(array_filter($this->observedResults, static fn (array $result): bool => isset($result['domain_search_count_evidence'])));
        if ($case === 'failed_target') {
            self::assertNotEmpty(array_filter($this->observedResults, static fn (array $result): bool => isset($result['error'])));
        }
    }

    public function test_both_current_scopes_are_read_and_a_second_request_cannot_reuse_their_coverage(): void
    {
        $response = $this->ask([$this->arguments($this->project->id), $this->arguments(null)]);
        self::assertStringNotContainsString(trans_message('ai_assistant_facts.comparison_scope_incomplete'), $response['message']['content']);
        self::assertSame([1, 1], array_column(array_column($this->observedResults, 'result_window'), 'total'));
        $response = $this->ask([$this->arguments($this->project->id)]);
        self::assertTrue($response['message']['metadata']['needs_clarification']);
        self::assertSame([], $response['message']['metadata']['source_refs']);
        self::assertStringContainsString(trans_message('ai_assistant_facts.comparison_scope_incomplete'), $response['message']['content']);
    }

    public function test_signed_zero_results_cover_both_scopes(): void
    {
        ContractPerformanceAct::query()->where('project_id', $this->project->id)->delete();
        $response = $this->ask([$this->arguments(null), $this->arguments($this->project->id)]);
        self::assertSame([0, 0], array_column(array_column($this->observedResults, 'result_window'), 'total'));
        self::assertStringNotContainsString(trans_message('ai_assistant_facts.comparison_scope_incomplete'), $response['message']['content']);
    }

    public function test_every_named_project_can_be_read_with_the_organization_including_an_empty_project(): void
    {
        $response = $this->ask([$this->arguments(null), $this->arguments($this->project->id), $this->arguments($this->secondProject->id)],
            'Сравни все акты организации с проектами '.$this->project->id.' и '.$this->secondProject->id);
        self::assertSame([1, 1, 0], array_column(array_column($this->observedResults, 'result_window'), 'total'));
        self::assertStringNotContainsString(trans_message('ai_assistant_facts.comparison_scope_incomplete'), $response['message']['content']);
    }

    public function test_model_can_request_the_other_scope_in_a_later_provider_turn(): void
    {
        $this->oneSearchPerTurn = true;
        $response = $this->ask([$this->arguments($this->project->id), $this->arguments(null)]);
        self::assertSame([1, 1], array_column(array_column($this->observedResults, 'result_window'), 'total'));
        self::assertStringNotContainsString(trans_message('ai_assistant_facts.comparison_scope_incomplete'), $response['message']['content']);
    }

    public function test_project_access_revoked_after_reads_cannot_publish_a_comparison(): void
    {
        $this->beforeFinal = fn () => $this->actor->assignedProjects()->updateExistingPivot($this->project->id, ['is_active' => false]);
        try {
            $response = $this->ask([$this->arguments(null), $this->arguments($this->project->id)]);
            self::assertTrue($response['message']['metadata']['needs_clarification']);
            self::assertSame([], $response['message']['metadata']['source_refs']);
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
        }
        self::assertFalse((bool) $this->actor->assignedProjects()->whereKey($this->project->id)->firstOrFail()->pivot->is_active);
    }

    private function ask(array $searches, ?string $question = null): array
    {
        $this->nextSearches = $searches;
        $this->emitSearches = true;
        $this->emittedSearches = 0;
        $this->observedResults = [];
        if ($this->service === null) {
            $provider = $this->createMock(ComparisonResponsesProvider::class);
            $provider->method('getModel')->willReturn('gpt-6-luna');
            $provider->method('isAvailable')->willReturn(true);
            $provider->method('countTokens')->willReturn(100);
            $provider->expects(self::never())->method('chat');
            $provider->method('responses')->willReturnCallback(function (array $input): array {
                foreach ($input as $item) {
                    if (is_array($item) && ($item['type'] ?? null) === 'function_call_output') {
                        $this->observedResults[$item['call_id']] = json_decode($item['output'], true, 512, JSON_THROW_ON_ERROR);
                    }
                }
                $batch = [];
                if ($this->emitSearches) {
                    $searches = $this->oneSearchPerTurn ? [array_shift($this->nextSearches)] : $this->nextSearches;
                    $this->emitSearches = $this->oneSearchPerTurn && $this->nextSearches !== [];
                    foreach ($searches as $arguments) {
                        $id = 'comparison-'.$this->emittedSearches++;
                        $batch[] = ['type' => 'function_call', 'id' => $id, 'status' => 'completed', 'call_id' => $id,
                            'name' => 'assistant_domain_search', 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
                    }
                } elseif ($this->beforeFinal !== null) {
                    ($this->beforeFinal)();
                    $this->beforeFinal = null;
                }

                return ['content' => $batch === [] ? 'Сравнение завершено.' : '', 'output' => $batch, 'function_calls' => $batch,
                    'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200,
                    'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
            });
            $this->service = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null]);
        }
        $question ??= 'Сравни все акты организации с проектом '.$this->project->id;
        $payload = ['request_id' => (string) Str::uuid(), 'message' => $question, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => (int) $this->project->id]]]];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->actor, $payload)['quote_id'];

        return $this->service->ask($question, $this->fixture->organization->id, $this->actor, null, $payload);
    }

    private function arguments(?int $projectId): array
    {
        return ['domain' => 'acts', 'entity_type' => 'performance_act', 'query' => '', 'project_id' => $projectId,
            'limit' => 20, 'cursor' => null, 'predicate' => null, 'fields' => ['id', 'project_id', 'status', 'currency']];
    }

    private function act(Project $project): void
    {
        Model::withoutEvents(function () use ($project): void {
            $contractor = Contractor::query()->create(['organization_id' => $project->organization_id, 'name' => 'Подрядчик акта']);
            $contract = Contract::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id,
                'contractor_id' => $contractor->id, 'number' => 'C-'.Str::uuid(), 'date' => '2026-09-01',
                'total_amount' => '10000.00', 'currency' => 'RUB']);
            ContractPerformanceAct::query()->create(['contract_id' => $contract->id, 'project_id' => $project->id,
                'act_document_number' => 'COMPARISON-'.Str::uuid(), 'act_date' => '2026-10-10',
                'period_start' => '2026-10-10', 'period_end' => '2026-10-10',
                'amount' => '10.00', 'currency' => 'RUB', 'status' => 'approved', 'is_approved' => true]);
        });
    }
}

interface ComparisonResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
