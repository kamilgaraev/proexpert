<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineAdapter;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPipelineResultData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Project;
use App\Services\Credits\AICreditService;
use Brick\Math\BigDecimal;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;

/** Only canonical test DB; expected/oracle data never enters this adapter. */
final class RecordedUniversalPipelineAdapter implements BenchmarkPipelineAdapter
{
    public array $lastRun = [];

    private ?MockHandler $transport = null;

    private array $history = [];

    private ?InMemoryS3HttpStorage $storage = null;

    public function __construct(private readonly Application $app, private readonly BenchmarkObjectReader $objects,
        private readonly string $recordingPath, private readonly string $recordingSha256) {}

    public function id(): string
    {
        return 'recorded-universal-test';
    }

    public function run(BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
    {
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'pgsql'
            || DB::connection()->getConfig('host') !== '127.0.0.1' || (string) DB::connection()->getConfig('port') !== '55433'
            || ! str_ends_with(DB::getDatabaseName(), '_testing')) {
            throw new BenchmarkContractException('recorded_replay_test_namespace_required');
        }
        if ($case->sourceType !== BenchmarkSourceType::TextDescription) {
            return BenchmarkPipelineResultData::unsupported();
        }
        $recording = file_get_contents($this->recordingPath);
        if (! is_string($recording) || strlen($recording) > 1000000 || ! hash_equals($this->recordingSha256, hash('sha256', $recording))) {
            throw new BenchmarkContractException('recorded_replay_recording_invalid');
        }
        $fixture = json_decode($recording, true, 32, JSON_THROW_ON_ERROR);
        if (($fixture['schema_version'] ?? null) !== 1 || ($fixture['provenance'] ?? null) !== 'synthetic_transport_fixture'
            || ($fixture['accuracy_calibrated'] ?? null) !== false || count($fixture['recordings'] ?? []) !== 2) {
            throw new BenchmarkContractException('recorded_replay_descriptor_invalid');
        }
        $description = $this->objects->read($case, 'input', 65536);
        if (! hash_equals($case->inputSha256, hash('sha256', $description))) {
            throw new BenchmarkContractException('recorded_replay_source_mismatch');
        }
        ($this->storage ??= new InMemoryS3HttpStorage)->install();
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->seedSettings();
        config(['estimate-generation.universal_enabled' => true, 'estimate-generation.credits.enabled' => true,
            'ai-assistant-credits.enforce' => false, 'cache.default' => 'array',
            'ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $rights = AssistantRealAuthorizationFixture::create();
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => [
            'estimate_generation.create', 'estimate_generation.generate', 'estimate_generation.view', 'estimate_generation.review',
        ]]]);
        $actor = $rights->member;
        $actor->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        $this->app->make('auth')->guard()->setUser($actor);
        $session = $this->app->make(CreateEstimateGenerationSession::class)->handle([
            'organization_id' => $rights->organization->id, 'project_id' => $project->id,
            'input_payload' => ['description' => $description, 'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal',
                'profile_id' => $fixture['profile_id'], 'construction_type' => $fixture['construction_type'],
                'price_policy' => 'catalog', 'selected_sections' => $fixture['selected_sections']],
        ], $actor);
        $facts = $this->app->make(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $project->id, (int) $session->id)->facts;
        $descriptionFacts = array_values(array_filter($facts, static fn ($fact): bool => $fact->type === 'evaluation_answer'
            && is_array($fact->value) && ($fact->value['kind'] ?? null) === 'initial_description'
            && ($fact->value['description'] ?? null) === trim($description)));
        if (count($descriptionFacts) !== 1) {
            throw new BenchmarkContractException('recorded_replay_description_fact_missing');
        }
        $historyStart = count($this->history);
        $responses = [];
        foreach ($fixture['recordings'] as $index => $entry) {
            if (($entry['role'] ?? null) !== ['estimate_composition', 'estimate_audit'][$index]
                || ! is_array($entry['response'] ?? null) || ($entry['response']['model'] ?? null) !== 'openai/gpt-6-luna') {
                throw new BenchmarkContractException('recorded_replay_role_invalid');
            }
            $body = json_encode($entry['response'], JSON_THROW_ON_ERROR);
            // The one explicit fixture binding follows the current canonical input Fact ID.
            $body = str_replace('__description_fact_id__', $descriptionFacts[0]->id, $body);
            $responses[] = new Response(200, ['Content-Type' => 'application/json'], $body);
        }
        if ($this->transport === null) {
            $this->transport = new MockHandler($responses);
            $stack = HandlerStack::create($this->transport);
            $stack->push(Middleware::history($this->history));
            $this->app->instance(RerankWireClient::class, new TimewebRerankWireClient(
                $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
                $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
                $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
                httpClient: new Client(['handler' => $stack]),
            ));
        } else {
            $this->transport->append(...$responses);
        }
        $credits = $this->app->make(AICreditService::class);
        $credits->grant($rights->organization, 1000000, 'purchase', null, 'test:recorded-universal-replay');
        $operationId = (string) Str::uuid();
        $quote = $this->app->make(EstimatorCreditOperations::class)->quote($actor, (int) $project->id, (int) $session->id, 0, $operationId, 'generation');
        $this->app->make(RequestEstimateGeneration::class)->handle($session, 0, 'ai_assisted', $actor,
            creditApproval: ['request_id' => $operationId, 'quote_id' => $quote['quote_id']]);
        $job = Queue::pushed(GenerateEstimateDraftJob::class)->first();
        if (! $job instanceof GenerateEstimateDraftJob) {
            throw new BenchmarkContractException('recorded_replay_generation_not_queued');
        }
        foreach (ProcessingStage::cases() as $stage) {
            $job->handle($this->app->make(RunEstimateGenerationDraft::class));
        }
        $session->refresh();
        $evaluation = $session->draft_payload['evaluation_result'] ?? null;
        if (! is_array($evaluation) || count($this->history) - $historyStart !== 2) {
            throw new BenchmarkContractException('recorded_replay_result_missing');
        }
        $usage = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->get();
        $cost = BigDecimal::zero();
        foreach ($usage as $row) {
            if ($row->pricing_status !== 'available' || $row->currency !== 'RUB' || ! is_string($row->cost_amount)) {
                throw new BenchmarkContractException('recorded_replay_usage_unknown');
            }
            $cost = $cost->plus($row->cost_amount);
        }
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)->sole();
        $workIds = array_column($evaluation['scenarios'][0]['positions'], 'work_key');
        $quantities = $costs = $areas = [];
        foreach ($evaluation['scenarios'][0]['positions'] as $position) {
            if ($position['quantity'] !== null) {
                $quantities[$position['work_key']] = (string) $position['quantity'];
            }
            if (isset($position['total_cost'])) {
                $costs[$position['work_key']] = (string) $position['total_cost'];
            }
        }
        $current = $this->app->make(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $project->id, (int) $session->id);
        foreach ($current->facts as $fact) {
            if ($fact->status === 'confirmed' && $fact->type === 'area' && is_numeric($fact->value)) {
                $areas['room:'.substr(hash('sha256', $fact->entityId), 0, 32)] = (string) $fact->value;
            }
        }
        $prediction = ['sheet_type' => 'partial_worklist', 'room_cells' => array_keys($areas), 'wall_cells' => [], 'opening_ids' => [],
            'areas' => $areas, 'quantities' => $quantities, 'work_ids' => $workIds, 'normative_rankings' => [], 'costs' => $costs,
            'applicable_item_ids' => [], 'evidence_ids_by_item' => [], 'model_schema_version' => 'recorded-universal-test:v1'];
        $this->lastRun = ['session_id' => (int) $session->id, 'evaluation' => $evaluation, 'usage' => $usage->all(),
            'provider_requests' => count($this->history) - $historyStart, 'consumed_units_minor' => (int) $operation->consumed_units_minor,
            'available_minor' => $credits->balance($rights->organization)['available_minor'], 'recording_sha256' => $this->recordingSha256];
        $this->lastRun['completed_stages'] = DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('status', 'completed')->pluck('stage')->unique()->sort()->values()->all();
        $this->lastRun['request_hashes'] = array_map(static fn ($exchange): string => hash('sha256', (string) $exchange['request']->getBody()), array_slice($this->history, $historyStart));
        $job->handle($this->app->make(RunEstimateGenerationDraft::class));
        $this->lastRun['requests_after_replay'] = count($this->history) - $historyStart;
        $this->lastRun['usage_after_replay'] = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count();
        Http::assertNothingSent();

        return BenchmarkPipelineResultData::success($prediction, ['text_model' => 'gpt-6-luna', 'replay' => 'synthetic:v1'], (string) $cost, 'RUB');
    }

    private function seedSettings(): void
    {
        if (DB::table('estimate_generation_setting_snapshots')->where('scope', 'global')->whereNull('organization_id')->exists()) {
            return;
        }
        $snapshot = EstimateGenerationSettingsData::fromArray([
            'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::ulid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB'],
        ])->snapshot();
        $hash = SettingsSnapshotHash::calculate($snapshot);
        $adminId = DB::table('system_admins')->insertGetId(['name' => 'Replay fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null,
            'version' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB', 'created_by_system_admin_id' => $adminId, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $id,
            'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
    }
}
