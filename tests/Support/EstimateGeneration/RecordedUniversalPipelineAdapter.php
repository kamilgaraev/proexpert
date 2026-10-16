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
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\IsolatedPostgresTestDatabase;

/** Only canonical test DB; expected/oracle data never enters this adapter. */
final class RecordedUniversalPipelineAdapter implements BenchmarkPipelineAdapter
{
    public array $lastRun = [];

    private ?MockHandler $transport = null;

    private array $history = [];

    private ?InMemoryS3HttpStorage $storage = null;

    public function __construct(private readonly Application $app, private readonly BenchmarkObjectReader $objects,
        private readonly string $recordingPath, private readonly string $recordingSha256,
        private readonly ?\Closure $afterDocumentUnits = null) {}

    public function id(): string
    {
        return 'recorded-universal-test';
    }

    public function run(BenchmarkPredictionCaseData $case, int $timeoutMs): BenchmarkPipelineResultData
    {
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'pgsql'
            || DB::connection()->getConfig('host') !== '127.0.0.1' || (string) DB::connection()->getConfig('port') !== (string) IsolatedPostgresTestDatabase::profilePort()
            || ! str_ends_with(DB::getDatabaseName(), '_testing')) {
            throw new BenchmarkContractException('recorded_replay_test_namespace_required');
        }
        if (! in_array($case->sourceType, [BenchmarkSourceType::TextDescription, BenchmarkSourceType::SitePhoto, BenchmarkSourceType::Spreadsheet, BenchmarkSourceType::Dxf], true)) {
            return BenchmarkPipelineResultData::unsupported();
        }
        $recording = file_get_contents($this->recordingPath);
        if (! is_string($recording) || strlen($recording) > 1000000 || ! hash_equals($this->recordingSha256, hash('sha256', $recording))) {
            throw new BenchmarkContractException('recorded_replay_recording_invalid');
        }
        $fixture = json_decode($recording, true, 32, JSON_THROW_ON_ERROR);
        if (($fixture['schema_version'] ?? null) !== 1 || ($fixture['provenance'] ?? null) !== 'synthetic_transport_fixture'
            || ($fixture['accuracy_calibrated'] ?? null) !== false || count($fixture['recordings'] ?? []) < 2
            || count($fixture['recordings']) > 16) {
            throw new BenchmarkContractException('recorded_replay_descriptor_invalid');
        }
        $initialInput = $fixture['initial_input'] ?? [];
        if (! is_array($initialInput) || array_diff(array_keys($initialInput), ['area', 'floors', 'height']) !== []) {
            throw new BenchmarkContractException('recorded_replay_initial_input_invalid');
        }
        $source = $this->objects->read($case, 'input', $case->sourceType === BenchmarkSourceType::TextDescription ? 65536 : 1000000);
        if (! hash_equals($case->inputSha256, hash('sha256', $source))) {
            throw new BenchmarkContractException('recorded_replay_source_mismatch');
        }
        $description = $case->sourceType === BenchmarkSourceType::TextDescription ? $source : $fixture['description'];
        ($this->storage ??= new InMemoryS3HttpStorage)->install();
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->seedSettings();
        config(['estimate-generation.universal_enabled' => true, 'estimate-generation.credits.enabled' => true,
            'ai-assistant-credits.enforce' => false, 'cache.default' => 'array',
            'ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1',
            'estimate-generation.vision.api_key' => 'fixture-not-a-real-key',
            'estimate-generation.vision.base_uri' => 'https://vision.fixture.invalid/v1']);
        $rights = AssistantRealAuthorizationFixture::create();
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => [
            'estimate_generation.create', 'estimate_generation.generate', 'estimate_generation.view', 'estimate_generation.review', 'estimate_generation.upload_documents',
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
                'price_policy' => 'catalog', 'selected_sections' => $fixture['selected_sections'], ...$initialInput],
        ], $actor);
        $credits = $this->app->make(AICreditService::class);
        $credits->grant($rights->organization, 1000000, 'purchase', null, 'test:recorded-universal-replay');
        $documentRun = [];
        $facts = $this->app->make(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $project->id, (int) $session->id)->facts;
        $descriptionFacts = array_values(array_filter($facts, static fn ($fact): bool => $fact->type === 'evaluation_answer'
            && is_array($fact->value) && ($fact->value['kind'] ?? null) === 'initial_description'
            && is_string($description) && ($fact->value['description'] ?? null) === trim($description)));
        if (count($descriptionFacts) !== ($description === null ? 0 : 1)) {
            throw new BenchmarkContractException('recorded_replay_description_fact_missing');
        }
        if (in_array($case->sourceType, [BenchmarkSourceType::SitePhoto, BenchmarkSourceType::Spreadsheet, BenchmarkSourceType::Dxf], true)) {
            $session = $this->processDocument($session, $actor, $source, $fixture, $documentRun, $case->sourceType);
        }
        $historyStart = count($this->history);
        $responses = [];
        foreach ($fixture['recordings'] as $index => $entry) {
            if (($entry['role'] ?? null) !== ($index === 0 ? 'estimate_composition' : 'estimate_audit')
                || ! is_array($entry['response'] ?? null) || ($entry['response']['model'] ?? null) !== 'openai/gpt-6-luna') {
                throw new BenchmarkContractException('recorded_replay_role_invalid');
            }
            $body = json_encode($entry['response'], JSON_THROW_ON_ERROR);
            // The one explicit fixture binding follows the current canonical input Fact ID.
            if (str_contains($body, '__description_fact_id__')) {
                if ($descriptionFacts === []) {
                    throw new BenchmarkContractException('recorded_replay_description_binding_missing');
                }
                $body = str_replace('__description_fact_id__', $descriptionFacts[0]->id, $body);
            }
            if (str_contains($body, '__native_')) {
                $responses[] = function (\Psr\Http\Message\RequestInterface $request) use ($body, $session): Response {
                    try {
                        $resolved = strtr($body, $this->nativeBindings($session, $request));
                    } catch (BenchmarkContractException $error) {
                        $this->lastRun['native_binding_error'] = $error->reason;
                        throw $error;
                    }
                    if (str_contains($resolved, '__native_')) {
                        throw new BenchmarkContractException('recorded_replay_native_binding_unknown');
                    }

                    return new Response(200, ['Content-Type' => 'application/json'], $resolved);
                };
            } else {
                $responses[] = new Response(200, ['Content-Type' => 'application/json'], $body);
            }
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
        $operationId = (string) Str::uuid();
        $quote = $this->app->make(EstimatorCreditOperations::class)->quote($actor, (int) $project->id, (int) $session->id, (int) $session->state_version, $operationId, 'generation');
        $request = $this->app->make(RequestEstimateGeneration::class)->handle($session, (int) $session->state_version, 'ai_assisted', $actor,
            creditApproval: ['request_id' => $operationId, 'quote_id' => $quote['quote_id']]);
        $job = $this->queue()->pushed(GenerateEstimateDraftJob::class)->first();
        if (! $job instanceof GenerateEstimateDraftJob) {
            $summary = $request->context['documents_summary'] ?? [];
            throw new BenchmarkContractException('recorded_replay_generation_not_queued:'.json_encode([
                'message_key' => $request->messageKey,
                'http_status' => $request->httpStatus,
                'session_status' => $request->session->status->value,
                'problem_flags' => $summary['problem_flags'] ?? [],
                'document_run' => $documentRun,
            ], JSON_THROW_ON_ERROR));
        }
        try {
            foreach (ProcessingStage::cases() as $stage) {
                $job->handle($this->app->make(RunEstimateGenerationDraft::class));
            }
        } catch (\Throwable $error) {
            throw new BenchmarkContractException('recorded_replay_pipeline_failure:'.json_encode([
                'cause_class' => $error::class,
                'attempt_status' => $error instanceof \App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException ? $error->attemptStatus : null,
                'composer_candidate_shapes' => array_values(array_filter(array_map(static function (array $exchange): ?array {
                    $body = json_decode((string) $exchange['request']->getBody(), true, 128, JSON_THROW_ON_ERROR);
                    foreach ($body['messages'] ?? [] as $message) {
                        if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                            $project = json_decode($message['content'], true, 128, JSON_THROW_ON_ERROR)['project'] ?? null;
                            if (is_array($project) && is_array($project['candidates'] ?? null)) {
                                return ['count' => count($project['candidates']), 'items' => array_map(static fn (array $item): array => [
                                    'key' => $item['work_key'], 'name' => $item['name'], 'unit' => $item['unit'], 'quantity' => $item['quantity'],
                                ], array_slice($project['candidates'], 0, 64))];
                            }
                        }
                    }

                    return null;
                }, array_slice($this->history, $historyStart)))),
                'audit_request_shapes' => array_values(array_filter(array_map(static function (array $exchange): ?array {
                    $body = json_decode((string) $exchange['request']->getBody(), true, 128, JSON_THROW_ON_ERROR);
                    foreach ($body['messages'] ?? [] as $message) {
                        if (($message['role'] ?? null) !== 'user' || ! is_string($message['content'] ?? null)) {
                            continue;
                        }
                        $input = json_decode($message['content'], true, 128, JSON_THROW_ON_ERROR)['audit_input'] ?? null;
                        if (is_array($input)) {
                            $workKeys = [];
                            foreach ($input['draft']['local_estimates'] ?? [] as $local) {
                                foreach ($local['sections'] ?? [] as $section) {
                                    foreach ($section['work_items'] ?? [] as $item) {
                                        $workKeys[] = $item['key'] ?? null;
                                    }
                                }
                            }

                            return ['cycle' => $input['cycle'] ?? null, 'part_id' => $input['part_id'] ?? null,
                                'work_keys' => array_column($input['context_manifest']['work_manifest'] ?? [], 'key'),
                                'part_work_keys' => $workKeys,
                                'total_facts' => $input['context_manifest']['total_facts'] ?? null];
                        }
                    }

                    return null;
                }, array_slice($this->history, $historyStart)))),
                'native_binding_error' => $this->lastRun['native_binding_error'] ?? null,
                'native_quantity_metadata' => $this->lastRun['native_quantity_metadata'] ?? [],
                'native_calculation_debug' => $this->lastRun['native_calculation_debug'] ?? [],
                'role_runs' => DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)
                    ->orderBy('id')->limit(10)->get(['role', 'status', 'subject_type', 'subject_id', 'failure_code'])->all(),
                'request_count' => count($this->history) - $historyStart,
                'completed_stage_count' => DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
                    ->where('status', 'completed')->count(),
            ], JSON_THROW_ON_ERROR), previous: $error);
        }
        $session->refresh();
        $evaluation = $session->draft_payload['evaluation_result'] ?? null;
        if (! is_array($evaluation) || count($this->history) - $historyStart !== count($fixture['recordings'])) {
            throw new BenchmarkContractException('recorded_replay_result_missing:'.json_encode([
                'evaluation_exists' => is_array($evaluation), 'request_count' => count($this->history) - $historyStart,
                'recording_count' => count($fixture['recordings']), 'status' => $session->status->value,
                'stage' => $session->processing_stage,
            ], JSON_THROW_ON_ERROR));
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
            'description_fact_count' => count($descriptionFacts),
            'confirmed_numeric_facts' => array_values(array_map(static fn ($fact): array => ['type' => $fact->type, 'value' => $fact->value],
                array_filter($current->facts, static fn ($fact): bool => $fact->status === 'confirmed' && is_numeric($fact->value)))),
            'provider_requests' => count($this->history) - $historyStart, 'consumed_units_minor' => (int) $operation->consumed_units_minor,
            'available_minor' => $credits->balance($rights->organization)['available_minor'], 'recording_sha256' => $this->recordingSha256];
        $this->lastRun['document_run'] = $documentRun;
        $this->lastRun['completed_stages'] = DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('status', 'completed')->pluck('stage')->unique()->sort()->values()->all();
        $this->lastRun['request_hashes'] = array_map(static fn ($exchange): string => hash('sha256', (string) $exchange['request']->getBody()), array_slice($this->history, $historyStart));
        $job->handle($this->app->make(RunEstimateGenerationDraft::class));
        $this->lastRun['requests_after_replay'] = count($this->history) - $historyStart;
        $this->lastRun['usage_after_replay'] = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count();
        if (in_array($case->sourceType, [BenchmarkSourceType::TextDescription, BenchmarkSourceType::Spreadsheet, BenchmarkSourceType::Dxf], true)) {
            Http::assertNothingSent();
        }

        return BenchmarkPipelineResultData::success($prediction, ['text_model' => 'gpt-6-luna', 'replay' => 'synthetic:v1'], (string) $cost, 'RUB');
    }

    private function processDocument(\App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession $session, \App\Models\User $actor,
        string $source, array $fixture, array &$run, BenchmarkSourceType $sourceType): \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession
    {
        $photo = $sourceType === BenchmarkSourceType::SitePhoto;
        if ($photo && ! is_array($fixture['vision_response'] ?? null)) {
            throw new BenchmarkContractException('recorded_replay_vision_missing');
        }
        $wireOwnership = [];
        if ($photo) {
            Http::fake(['https://vision.fixture.invalid/v1/chat/completions' => function () use ($fixture, $session, &$wireOwnership) {
                $physical = DB::table('estimate_generation_vision_physical_attempts')->where('session_id', $session->id)->where('state', 'wire_started')->sole();
                $role = DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->where('physical_attempt_id', $physical->attempt_id)->sole();
                $wireOwnership[] = $physical->owner_token !== $role->owner_uuid;

                return Http::response($fixture['vision_response'], 200);
            }]);
        }
        $path = tempnam(sys_get_temp_dir(), 'most-document-replay-');
        $filename = match ($sourceType) {
            BenchmarkSourceType::SitePhoto => 'room.png', BenchmarkSourceType::Dxf => 'dimensions.dxf', default => 'dimensions.xlsx'
        };
        $mimeType = match ($sourceType) {
            BenchmarkSourceType::SitePhoto => 'image/png', BenchmarkSourceType::Dxf => 'application/dxf', default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        };
        try {
            file_put_contents($path, $source);
            $upload = $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments::class)
                ->handle($session, (int) $session->state_version, [new \Illuminate\Http\UploadedFile($path,
                    $filename, $mimeType, null, true)], $actor, (string) Str::uuid());
        } finally {
            unlink($path);
        }
        $document = $upload->documents->sole();
        $manifestJob = $this->queue()->pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob::class)->first();
        $manifestJob->handle($this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessEstimateGenerationDocument::class));
        $session->refresh();
        $id = null;
        if ($photo) {
            $id = (string) Str::uuid();
            $quote = $this->app->make(EstimatorCreditOperations::class)->quote($actor, (int) $session->project_id, (int) $session->id, (int) $session->state_version, $id, 'documents');
            $session = $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\StartEstimatorDocumentOperation::class)
                ->handle($actor, (int) $session->project_id, (int) $session->id, ['request_id' => $id, 'quote_id' => $quote['quote_id'], 'state_version' => (int) $session->state_version]);
        }
        $units = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->where('document_id', $document->id)->get();
        if ($units->count() !== 1) {
            throw new BenchmarkContractException('recorded_replay_document_manifest_invalid');
        }
        $processor = $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit::class);
        if ($photo) {
            foreach ($units as $unit) {
                $processor->handle((int) $unit->id, $unit->source_version);
            }
        } else {
            $queued = $this->queue()->pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob::class)->first();
            if (! $queued instanceof \App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob
                || $queued->rateLimitKey() !== 'unit:'.$units->sole()->id) {
                throw new BenchmarkContractException('recorded_replay_native_free_job_missing');
            }
            $queued->handle($processor);
            if (DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count() !== 0
                || DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count() !== 0) {
                throw new BenchmarkContractException('recorded_replay_native_processing_not_free');
            }
        }
        ($this->afterDocumentUnits)?->__invoke($session->fresh(), $id);
        if ($photo) {
            $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\CompleteEstimatorDocumentOperation::class)->handle($session->fresh());
        }
        $session->refresh();
        $run = ['document_id' => (int) $document->id, 'operation_id' => $id, 'vision_requests' => Http::recorded()->count(),
            'provider_ownership_preserved' => $wireOwnership,
            'status' => $document->fresh()->status, 'units' => $units->map(static fn ($unit): string => $unit->fresh()->status->value)->all(),
            'error_code' => $document->fresh()->error_code,
            'unit_failure_codes' => $units->map(static fn ($unit): ?string => $unit->fresh()->failure_code)->all(),
            'receipt' => $photo ? json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('receipt'), true, 32, JSON_THROW_ON_ERROR) : null];
        foreach ($units as $unit) {
            $processor->handle((int) $unit->id, $unit->source_version);
        }
        $run['vision_requests_after_replay'] = Http::recorded()->count();
        if ($run['units'] !== ['completed']) {
            throw new BenchmarkContractException('recorded_replay_document_not_completed:'.json_encode($run, JSON_THROW_ON_ERROR));
        }
        if ($session->status->value === 'input_review_required') {
            $session = $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
                ->handle($session, (int) $session->state_version, \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::InputConfirmed, $actor);
        }

        return $session;
    }

    private function queue(): QueueFake
    {
        $queue = Queue::getFacadeRoot();
        if (! $queue instanceof QueueFake) {
            throw new BenchmarkContractException('recorded_replay_fake_queue_required');
        }

        return $queue;
    }

    /** Bind canned model references to actual production-published facts, never oracle or invented dimensions. */
    private function nativeBindings(\App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession $session,
        \Psr\Http\Message\RequestInterface $request): array
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $this->app->make(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $bindings = [];
        foreach (['length', 'width'] as $parameter) {
            $facts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === $parameter
                && $fact->origin === 'document' && $fact->status === 'confirmed'));
            if (count($facts) !== 1) {
                throw new BenchmarkContractException('recorded_replay_native_fact_missing_or_ambiguous');
            }
            $bindings['__native_'.$parameter.'_fact_id__'] = $facts[0]->id;
        }
        $wire = json_decode((string) $request->getBody(), true, 128, JSON_THROW_ON_ERROR);
        $project = null;
        foreach ($wire['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                $content = json_decode($message['content'], true, 128, JSON_THROW_ON_ERROR);
                $project = $content['project'] ?? null;
            }
        }
        if (! is_array($project) || ($project['scope'] ?? null) !== array_combine(['organization_id', 'project_id', 'session_id'], $scope)) {
            throw new BenchmarkContractException('recorded_replay_native_input_scope_invalid');
        }
        $areas = array_values(array_filter($project['derived_quantities'] ?? [], static fn (array $quantity): bool => ($quantity['formula_key'] ?? null) === 'floor_area'
            && ($quantity['review_blockers'] ?? []) === []
            && array_diff(array_values($bindings), $quantity['operand_fact_ids'] ?? []) === []));
        if (count($areas) !== 1 || ! is_string($areas[0]['id'] ?? null)) {
            $this->lastRun['native_quantity_metadata'] = array_map(static fn (array $quantity): array => array_intersect_key($quantity,
                array_flip(['id', 'formula_key', 'operand_fact_ids', 'review_blockers'])), array_slice($project['derived_quantities'] ?? [], 0, 20));
            $models = $this->app->make(ProjectModelRepository::class);
            $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
            $simulation = $this->app->make(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)->simulate(
                $capture['snapshot'], $capture['token'], $models->currentTechnologyRecommendations(...$scope), $models->currentCompleteness(...$scope), []);
            $this->lastRun['native_calculation_debug'] = ['context' => $simulation['context'],
                'warnings' => array_slice($simulation['warnings'], 0, 10), 'quantity_keys' => array_keys($simulation['quantities'])];
            throw new BenchmarkContractException('recorded_replay_native_area_missing');
        }
        $bindings['__native_floor_area_quantity_id__'] = $areas[0]['id'];

        return $bindings;
    }

    private function seedSettings(): void
    {
        $latest = DB::table('estimate_generation_setting_snapshots')->where('scope', 'global')->whereNull('organization_id')
            ->orderByDesc('version')->first(['version', 'snapshot_hash']);
        $version = (int) ($latest?->version ?? 0) + 1;
        $snapshot = EstimateGenerationSettingsData::fromArray([
            'scope' => 'global', 'organization_id' => null, 'expected_version' => $version - 1, 'idempotency_key' => (string) Str::ulid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png', 'xlsx', 'dxf'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB'],
        ])->snapshot();
        $hash = SettingsSnapshotHash::calculate($snapshot);
        if ($latest !== null && $latest->snapshot_hash === $hash) {
            return;
        }
        $adminId = DB::table('system_admins')->insertGetId(['name' => 'Replay fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null,
            'version' => $version, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB', 'created_by_system_admin_id' => $adminId, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $id,
            'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
    }
}
