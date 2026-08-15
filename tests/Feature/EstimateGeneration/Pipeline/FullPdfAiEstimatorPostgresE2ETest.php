<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisModel;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationEvidence;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Questions\AnswerEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class FullPdfAiEstimatorPostgresE2ETest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function all_twenty_two_real_pdf_pages_reach_questions_and_a_sourced_nonzero_server_draft(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('1', getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT'));
        $pdfPath = getenv('MOST_ESTIMATE_E2E_PDF');
        if (! is_string($pdfPath) || ! is_file($pdfPath)) {
            self::markTestSkipped('Requires explicit MOST_ESTIMATE_E2E_PDF fixture.');
        }
        $recording = json_decode((string) file_get_contents(
            dirname(__DIR__, 4).'/tests/Fixtures/EstimateGeneration/analysis/ar-1-22-page-routes.json',
        ), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(22, $recording['source_pages'] ?? null);

        Queue::fake();
        $objects = [];
        $files = $this->fileService($objects);
        $this->app->instance(FileService::class, $files);
        $provider = new RecordedFullPdfVisionProvider(
            $recording['pages'],
            app(VisionPhysicalAttemptStore::class),
            app(AiUsageStore::class),
        );
        $this->app->instance(VisionProvider::class, $provider);
        $synthesis = new RecordedFullPdfProjectSynthesisModel;
        $this->app->instance(ProjectSynthesisModel::class, $synthesis);
        $composer = new RecordedFullPdfEstimateComposerModel;
        $this->app->instance(EstimateComposerModel::class, $composer);
        $auditor = new RecordedFullPdfEstimateAuditModel;
        $this->app->instance(EstimateAuditModel::class, $auditor);
        config()->set('estimate-generation.ocr.geometry.python_binary', 'python');
        config()->set('estimate-generation.ocr.geometry.timeout_seconds', 180);
        config()->set('estimate-generation.generation.document_cost_limit_rub', '100000.00');
        $this->seedGlobalSettings();
        $normativeContext = $this->seedNormativePricingFixture();

        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => [
                'description' => 'house 80 m2, one floor, roof, facade, engineering systems',
                'building_type' => 'house',
                'generation_mode' => 'ai_assisted',
                'area' => 80,
                'floors' => 1,
                'regional_context' => [
                    'region_name' => 'Республика Татарстан',
                    'year' => 2026,
                    'quarter' => 1,
                    'applicability_date' => '2026-03-31',
                    ...$normativeContext,
                ],
            ],
            'state_version' => 0,
        ]);
        $uploaded = new UploadedFile($pdfPath, 'architectural-project.pdf', 'application/pdf', null, true);
        $documents = app(DocumentParsingService::class)->storeParsedDocuments($session, [$uploaded], $user);
        self::assertCount(1, $documents);
        $document = $documents->first()->fresh(['session']);
        self::assertNotNull($document);
        self::assertSame(22, $recording['source_pages']);

        $attemptId = (string) ($document->meta['processing_attempt_id'] ?? '');
        $sourceVersion = (string) $document->source_version;
        $snapshot = FailureExecutionSnapshot::capture(
            $document->session,
            'document_manifest',
            $attemptId,
            (int) $document->id,
            $sourceVersion,
        );
        app(ProcessEstimateGenerationDocument::class)->handle((int) $document->id, $snapshot);

        $units = EstimateGenerationProcessingUnit::query()
            ->where('document_id', $document->id)
            ->where('source_version', $sourceVersion)
            ->orderBy('unit_index')
            ->get();
        self::assertCount(22, $units);
        self::assertSame(range(1, 22), $units->pluck('unit_index')->all());

        $processor = app(ProcessDocumentUnit::class);
        foreach ($units as $unit) {
            $processor->handle((int) $unit->id, $sourceVersion);
        }

        $terminal = EstimateGenerationProcessingUnit::query()
            ->where('document_id', $document->id)
            ->where('source_version', $sourceVersion)
            ->get();
        self::assertSame(['completed' => 22], $terminal->countBy(
            static fn (EstimateGenerationProcessingUnit $unit): string => $unit->status->value,
        )->all(), json_encode([
            'units' => $terminal->countBy('failure_code')->all(),
            'failures' => DB::table('estimate_generation_failure_identities')
                ->where('session_id', $session->id)
                ->select(['stage', 'operation', 'code'])
                ->distinct()
                ->get()
                ->all(),
            'safe_contexts' => DB::table('estimate_generation_failure_events')
                ->where('session_id', $session->id)
                ->pluck('safe_context')
                ->unique()
                ->values()
                ->all(),
            'document' => DB::table('estimate_generation_documents')
                ->where('id', $document->id)
                ->first([
                    'status',
                    'processing_stage',
                    'processing_control_status',
                    'processing_control_reason',
                    'processing_cost_limit',
                ]),
            'status_by_unit' => $terminal->mapWithKeys(
                static fn (EstimateGenerationProcessingUnit $unit): array => [
                    $unit->unit_index => $unit->status->value,
                ],
            )->all(),
            'provider_calls' => [
                'logical' => $provider->logicalCalls,
                'physical' => $provider->physicalCalls,
            ],
            'role_contracts' => DB::table('estimate_generation_ai_role_runs')
                ->where('session_id', $session->id)
                ->selectRaw("role, status, failure_code, count(*) as rows, min(coalesce(jsonb_array_length(result_payload->'claims'), 0)) as min_claims, min(coalesce(jsonb_array_length(result_payload->'evidence'), 0)) as min_evidence")
                ->groupBy(['role', 'status', 'failure_code'])
                ->orderBy('role')
                ->get()
                ->all(),
            'usage' => DB::table('estimate_generation_ai_usage')
                ->where('session_id', $session->id)
                ->selectRaw('count(*) as rows, coalesce(sum(cost_amount), 0) as spent')
                ->first(),
        ], JSON_THROW_ON_ERROR));
        self::assertSame(0, $terminal->where('failure_code', 'unit_claim_lost')->count());
        self::assertSame(22, $terminal->sum('output_count'));
        self::assertSame(82, $provider->logicalCalls);
        self::assertSame(82, $provider->physicalCalls);
        self::assertSame($this->expectedCallsByPage(), $provider->callsByPage);
        self::assertSame(array_fill_keys(range(3, 22), 3), $provider->arbitrationClaimCounts);

        $beforeReplay = [$provider->logicalCalls, $provider->physicalCalls];
        foreach ($terminal as $unit) {
            $processor->handle((int) $unit->id, $sourceVersion);
        }
        self::assertSame($beforeReplay, [$provider->logicalCalls, $provider->physicalCalls]);
        self::assertSame(82, DB::table('estimate_generation_ai_role_runs')
            ->where('session_id', $session->id)
            ->where('document_id', $document->id)
            ->count());
        self::assertSame(82, DB::table('estimate_generation_vision_physical_attempts')
            ->where('document_id', $document->id)
            ->where('state', 'completed')
            ->where('usage_recorded', true)
            ->count());
        self::assertSame(82, DB::table('estimate_generation_ai_usage')
            ->where('document_id', $document->id)
            ->where('pricing_status', 'available')
            ->where('cost_amount', '0')
            ->count());

        $document = $document->fresh(['pages', 'processingUnits']);
        self::assertNotNull($document);
        self::assertCount(22, $document->pages);
        self::assertSame(22, $document->pages->whereIn('status', ['ready', 'needs_review'])->count());
        foreach ($document->pages->sortBy('page_number') as $page) {
            $payload = is_array($page->normalized_payload) ? $page->normalized_payload : [];
            $observations = is_array($payload['independent_observations'] ?? null)
                ? $payload['independent_observations']
                : [];
            $expectedObserverCount = (int) $page->page_number <= 2 ? 1 : 3;
            self::assertCount($expectedObserverCount, $observations);
            foreach ($observations as $observation) {
                self::assertCount(1, is_array($observation['claims'] ?? null) ? $observation['claims'] : []);
                self::assertCount(1, is_array($observation['evidence'] ?? null) ? $observation['evidence'] : []);
            }
            if ((int) $page->page_number <= 2) {
                self::assertNull($payload['document_arbitration'] ?? null);
            } else {
                self::assertSame('arbiter', $payload['document_arbitration']['role'] ?? null);
                $decisions = is_array($payload['document_arbitration']['decisions'] ?? null)
                    ? $payload['document_arbitration']['decisions']
                    : [];
                $quarantined = is_array($payload['document_arbitration']['quarantined_intents'] ?? null)
                    ? $payload['document_arbitration']['quarantined_intents']
                    : [];
                self::assertGreaterThanOrEqual(2, count($decisions));
                self::assertSame(3, count($decisions) + count($quarantined));
                if ((int) $page->page_number === 3) {
                    $pageClaims = array_merge(...array_values(array_map(
                        static fn (array $observation): array => is_array($observation['claims'] ?? null)
                            ? $observation['claims']
                            : [],
                        $observations,
                    )));
                    $areaClaim = array_values(array_filter(
                        $pageClaims,
                        static fn (array $claim): bool => ($claim['factType'] ?? null) === 'area',
                    ))[0] ?? null;
                    self::assertIsArray($areaClaim);
                    $pageEvidence = array_merge(...array_values(array_map(
                        static fn (array $observation): array => is_array($observation['evidence'] ?? null)
                            ? $observation['evidence']
                            : [],
                        $observations,
                    )));
                    $areaEvidence = array_values(array_filter(
                        $pageEvidence,
                        static fn (array $evidence): bool => ($evidence['key'] ?? null) === $areaClaim['evidenceRef'],
                    ))[0] ?? null;
                    self::assertSame(true, $areaEvidence['locator']['explicit'] ?? null);
                    self::assertSame('accepted', array_values(array_filter(
                        $decisions,
                        static fn (array $decision): bool => ($decision['status'] ?? null) === 'accepted',
                    ))[0]['status'] ?? null);
                }
            }
        }
        self::assertGreaterThanOrEqual(20, EstimateGenerationEvidence::query()
            ->where('session_id', $session->id)
            ->where('source_version', $sourceVersion)
            ->count());
        self::assertGreaterThanOrEqual(1, DB::table('estimate_generation_project_model_assertions')
            ->where('session_id', $session->id)
            ->where('assertion_type', 'area')
            ->count());
        self::assertSame(1, DB::table('estimate_generation_project_model_assertions')
            ->where('session_id', $session->id)
            ->where('assertion_type', 'area')
            ->where('fact_status', 'confirmed')
            ->count());
        self::assertSame(1, DB::table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->where('projection.session_id', $session->id)
            ->where('projection.is_current', true)
            ->where('fact.assertion_type', 'area')
            ->where('fact.fact_status', 'confirmed')
            ->count());
        self::assertSame(1, DB::table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->join('estimate_generation_project_model_entities as entity', 'entity.id', '=', 'fact.entity_id')
            ->where('projection.session_id', $session->id)
            ->where('projection.is_current', true)
            ->where('fact.assertion_type', 'area')
            ->where('fact.fact_status', 'confirmed')
            ->where('entity.entity_kind', 'room')
            ->count());

        $questions = app(ListEstimateClarifications::class)->handle(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
        );
        self::assertNotEmpty($questions);
        self::assertMatchesRegularExpression('/^arbiter_question_[a-f0-9]{16}$/D', (string) $questions[0]['code']);
        self::assertSame('Материал наружной стены', $questions[0]['subject']);
        self::assertSame([5], $questions[0]['source_locator']['page_numbers']);
        self::assertSame($sourceVersion, $questions[0]['source_version']);

        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $selectedResponse = (string) ($questions[0]['choices'][0]['value'] ?? '');
        self::assertNotSame('', $selectedResponse);
        $answer = app(AnswerEstimateClarification::class)->handle(
            $user,
            $session,
            new ActorContext(
                (int) $organization->id,
                (int) $project->id,
                (int) $user->id,
                'full-pdf-question-answer-0001',
                (string) $questions[0]['source_version'],
                (string) $questions[0]['answer_fingerprint'],
            ),
            (string) $questions[0]['code'],
            $selectedResponse,
        );
        self::assertSame('answered', $answer->status);
        self::assertSame(1, $synthesis->physicalCalls);
        self::assertSame(1, DB::table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->where('projection.session_id', $session->id)
            ->where('projection.is_current', true)
            ->where('fact.assertion_type', 'area')
            ->where('fact.fact_status', 'confirmed')
            ->count());
        self::assertSame([], app(ListEstimateClarifications::class)->handle(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
        ));

        $generationAttemptId = (string) Str::uuid();
        $session->refresh();
        $session->forceFill([
            'status' => 'generating',
            'processing_stage' => 'object_analysis',
            'processing_progress' => 35,
            'input_payload' => [
                ...(array) $session->input_payload,
                'generation_attempt_id' => $generationAttemptId,
            ],
        ])->save();
        app(AiEstimateQuotaService::class)->reserve($session->fresh());
        $generationSnapshot = FailureExecutionSnapshot::capture(
            $session,
            'full_pdf_generation_pipeline',
            $generationAttemptId,
        );
        $pipeline = app(DraftPipelineEntrypoint::class);
        foreach (ProcessingStage::cases() as $expectedStage) {
            $run = $pipeline->run($generationSnapshot);
            self::assertSame($expectedStage, $run->executedStage);
            if ($expectedStage === ProcessingStage::ExtractQuantities) {
                $outputs = app(PipelineOutputRepository::class)->priorOutputs(new PipelineContext(
                    $generationSnapshot->sessionId,
                    $generationSnapshot->organizationId,
                    $generationSnapshot->projectId,
                    $generationSnapshot->stateVersion,
                    'sha256:'.str_repeat('0', 64),
                    $generationSnapshot->status,
                    generationAttemptId: $generationAttemptId,
                ));
                $payload = $outputs->payload($expectedStage);
                $quantities = $payload['building_quantities']['quantities'] ?? [];
                self::assertNotEmpty($quantities, json_encode([
                    'diagnostic_codes' => array_column(
                        $payload['building_quantities']['diagnostics'] ?? [],
                        'code',
                    ),
                    'warning_codes' => array_column(
                        $payload['stage6_generation_context']['warnings'] ?? [],
                        'code',
                    ),
                ], JSON_THROW_ON_ERROR));
                self::assertContains('floor_area', array_column($quantities, 'key'));
            }
            if (in_array($expectedStage, [
                ProcessingStage::PlanWorkItems,
                ProcessingStage::MatchNormatives,
                ProcessingStage::ResolvePrices,
                ProcessingStage::BuildDraft,
            ], true)) {
                $outputs = app(PipelineOutputRepository::class)->priorOutputs(new PipelineContext(
                    $generationSnapshot->sessionId,
                    $generationSnapshot->organizationId,
                    $generationSnapshot->projectId,
                    $generationSnapshot->stateVersion,
                    'sha256:'.str_repeat('0', 64),
                    $generationSnapshot->status,
                    generationAttemptId: $generationAttemptId,
                ));
                $payload = $outputs->payload($expectedStage);
                $localEstimates = $expectedStage === ProcessingStage::BuildDraft
                    ? ($payload['draft']['local_estimates'] ?? [])
                    : ($payload['local_estimates'] ?? []);
                $diagnostic = $expectedStage === ProcessingStage::BuildDraft ? json_encode([
                    'review_codes' => array_column($payload['draft']['stage6_review_items'] ?? [], 'code'),
                    'candidates' => array_map(static fn (array $item): array => [
                        'pricing_blocker' => $item['pricing_blocker'] ?? null,
                        'quantity_key' => $item['quantity_evidence']['key'] ?? null,
                        'quantity_snapshot' => $item['quantity_evidence']['formula_inputs']['snapshot_identity']['input_fingerprint'] ?? null,
                        'norm_status' => $item['normative_match']['status'] ?? null,
                        'norm_dataset' => $item['normative_match']['dataset_version'] ?? null,
                        'retrieval_status' => $item['normative_retrieval']['status'] ?? null,
                        'retrieval_issues' => $item['normative_retrieval']['blocking_issues'] ?? null,
                        'price_status' => $item['price_snapshot']['status'] ?? null,
                    ], $payload['draft']['stage6_candidate_rows'] ?? []),
                ], JSON_THROW_ON_ERROR) : $expectedStage->value;
                self::assertGreaterThan(0, $this->workItemCount($localEstimates), $diagnostic);
            }
        }
        $session->refresh();
        self::assertSame('confirmed', DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $organization->id)
            ->where('session_id', $session->id)
            ->value('status'));
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        $workItems = [];
        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $workItem) {
                    if (is_array($workItem)) {
                        $workItems[] = $workItem;
                    }
                }
            }
        }
        self::assertNotEmpty($workItems);
        self::assertNotEmpty(array_filter(
            $workItems,
            static fn (array $item): bool => (float) ($item['total_cost'] ?? 0) > 0,
        ));
        self::assertNotEmpty(array_filter(
            $workItems,
            static fn (array $item): bool => is_array($item['source_refs'] ?? null) && $item['source_refs'] !== [],
        ));
        $pricedItem = array_values(array_filter(
            $workItems,
            static fn (array $item): bool => (float) ($item['total_cost'] ?? 0) > 0,
        ))[0];
        self::assertSame('calculated', $pricedItem['pricing_status'] ?? null);
        self::assertSame('matched', $pricedItem['normative_match']['status'] ?? null);
        self::assertSame(true, $pricedItem['normative_match']['hard_gate_passed'] ?? null);
        self::assertSame('11-01-011-01', $pricedItem['normative_match']['code'] ?? null);
        self::assertSame(
            $normativeContext['normative_dataset_version'],
            $pricedItem['normative_match']['dataset_version']['version_key'] ?? null,
        );
        self::assertSame(
            $normativeContext['estimate_regional_price_version_id'],
            $pricedItem['price_snapshot']['version_id'] ?? null,
        );
        self::assertTrue(BigDecimal::of((string) $pricedItem['quantity'])->isEqualTo('80'));
        $resourceTotal = BigDecimal::zero();
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $resourceGroup) {
            foreach ($pricedItem[$resourceGroup] ?? [] as $resource) {
                $resourceTotal = $resourceTotal->plus((string) ($resource['total_price'] ?? '0'));
            }
        }
        self::assertTrue($resourceTotal->isGreaterThan(BigDecimal::zero()));
        self::assertTrue($resourceTotal->isEqualTo((string) $pricedItem['price_snapshot']['final_amount']));
        self::assertTrue($resourceTotal->isEqualTo((string) $pricedItem['total_cost']));
        self::assertSame(1, $composer->logicalCalls);
        self::assertSame(1, $composer->physicalCalls);
        self::assertSame(1, $auditor->logicalCalls);
        self::assertSame(1, $auditor->physicalCalls);
        self::assertSame(
            85,
            $provider->physicalCalls + $synthesis->physicalCalls + $composer->physicalCalls + $auditor->physicalCalls,
        );
    }

    private function workItemCount(mixed $localEstimates): int
    {
        $count = 0;
        foreach (is_array($localEstimates) ? $localEstimates : [] as $localEstimate) {
            foreach (is_array($localEstimate['sections'] ?? null) ? $localEstimate['sections'] : [] as $section) {
                $count += count(is_array($section['work_items'] ?? null) ? $section['work_items'] : []);
            }
        }

        return $count;
    }

    /** @param array<string, array<string, mixed>> $objects */
    private function fileService(array &$objects): FileService
    {
        $files = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upload', 'describeHead', 'describeCurrent', 'putImmutable'])
            ->getMock();
        $files->method('upload')->willReturnCallback(
            static function (UploadedFile $file, string $directory, mixed ...$arguments) use (&$objects): string {
                $organization = $arguments[2] ?? null;
                $organizationId = $organization instanceof Organization ? (int) $organization->id : 0;
                $path = sprintf('org-%d/%s/%s', $organizationId, $directory, $file->getClientOriginalName());
                $body = (string) file_get_contents($file->getRealPath());
                $objects[$path] = ['body' => $body, 'content_type' => 'application/pdf'];

                return $path;
            },
        );
        $files->method('describeHead')->willReturnCallback(
            static function (string $path) use (&$objects): array {
                return [
                    'path' => $path,
                    'size' => strlen($objects[$path]['body']),
                    'content_type' => $objects[$path]['content_type'],
                    'etag' => hash('md5', $objects[$path]['body']),
                ];
            },
        );
        $files->method('describeCurrent')->willReturnCallback(
            static function (string $path) use (&$objects): array {
                return [
                    'path' => $path,
                    'body' => $objects[$path]['body'],
                    'content_type' => $objects[$path]['content_type'],
                    'size' => strlen($objects[$path]['body']),
                    'sha256' => hash('sha256', $objects[$path]['body']),
                    'etag' => hash('md5', $objects[$path]['body']),
                ];
            },
        );
        $files->method('putImmutable')->willReturnCallback(
            static function (string $path, string $body, string $contentType) use (&$objects): array {
                $created = ! isset($objects[$path]);
                $objects[$path] = ['body' => $body, 'content_type' => $contentType];

                return [
                    'path' => $path,
                    'body' => $body,
                    'content_type' => $contentType,
                    'size' => strlen($body),
                    'sha256' => hash('sha256', $body),
                    'etag' => hash('md5', $body),
                    'created' => $created,
                ];
            },
        );

        return $files;
    }

    private function seedGlobalSettings(): void
    {
        $snapshot = EstimateGenerationSettingsData::fromArray([
            'scope' => 'global',
            'organization_id' => null,
            'expected_version' => 0,
            'idempotency_key' => '01J2X5B8YWFK9YD8Q6V1VZ4H3K',
            'models' => [
                'vision' => 'openai/gpt-5.6-luna',
                'classification' => 'fixture/classification',
                'normative_matching' => 'fixture/normative',
            ],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 800],
            'timeouts' => ['vision' => 81, 'classification' => 82, 'normative_matching' => 83],
            'retries' => ['vision' => 0, 'classification' => 1, 'normative_matching' => 2],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'],
            'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
        ])->snapshot();
        $hash = SettingsSnapshotHash::calculate($snapshot);
        $adminId = DB::table('system_admins')->insertGetId([
            'name' => 'Contract',
            'email' => Str::uuid().'@example.test',
            'password' => 'not-used',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $snapshotId = DB::table('estimate_generation_setting_snapshots')->insertGetId([
            'scope' => 'global',
            'organization_id' => null,
            'version' => 1,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'snapshot_hash' => $hash,
            'daily_budget' => '100.00',
            'monthly_budget' => '1000.00',
            'currency' => 'RUB',
            'created_by_system_admin_id' => $adminId,
            'created_at' => now(),
        ]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert([
            'setting_snapshot_id' => $snapshotId,
            'algorithm' => 'jcs-sha256-v1',
            'snapshot_hash' => $hash,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, int|string> */
    private function seedNormativePricingFixture(): array
    {
        $now = now();
        $versionKey = 'full-pdf-'.strtolower((string) Str::ulid());
        $datasetId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022',
            'version_key' => $versionKey,
            'bucket' => 'contract',
            'prefix' => $versionKey,
            'status' => 'parsed',
            'files_count' => 1,
            'rows_read' => 1,
            'rows_imported' => 1,
            'errors_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $priceDatasetId = (int) DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fgis_labor_prices',
            'version_key' => $versionKey,
            'bucket' => 'contract',
            'prefix' => $versionKey.'-prices',
            'status' => 'parsed',
            'files_count' => 1,
            'rows_read' => 1,
            'rows_imported' => 1,
            'errors_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $collectionId = (int) DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $datasetId,
            'code' => 'gesn-11',
            'name' => 'Полы',
            'norm_type' => 'gesn',
            'source_file' => 'gesn-11.xml',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $normId = (int) DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'code' => '11-01-011-01',
            'name' => 'Устройство цементной стяжки пола',
            'unit' => 'm2',
            'canonical_unit' => 'm2',
            'unit_dimension' => 'area',
            'section_code' => '11-01',
            'section_name' => 'Стяжки',
            'work_composition' => json_encode(
                ['Устройство цементной стяжки пола'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $resourceCode = 'labor-floor-screed-'.$normId;
        DB::table('estimate_norm_resources')->insert([
            'estimate_norm_id' => $normId,
            'resource_code' => $resourceCode,
            'resource_name' => 'Затраты труда рабочих',
            'unit' => 'h',
            'quantity' => '0.250000',
            'resource_type' => 'labor',
            'raw_payload' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $regionId = (int) DB::table('estimate_regions')->insertGetId([
            'code' => 'RU-RT-'.$normId,
            'name' => 'Республика Татарстан',
            'fgiscs_subject_id' => 160000 + $normId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $priceZoneId = (int) DB::table('estimate_price_zones')->insertGetId([
            'estimate_region_id' => $regionId,
            'name' => 'Республика Татарстан',
            'fgiscs_price_zone_id' => 1600000 + $normId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $periodId = (int) DB::table('estimate_price_periods')->insertGetId([
            'fgiscs_period_id' => 20260100 + $normId,
            'name' => 'I квартал 2026',
            'year' => 2026,
            'quarter' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $regionalPriceVersionId = (int) DB::table('estimate_regional_price_versions')->insertGetId([
            'source' => 'fgiscs',
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'version_key' => $versionKey,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('estimate_resource_prices')->insert([
            'dataset_version_id' => $priceDatasetId,
            'regional_price_version_id' => $regionalPriceVersionId,
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'resource_code' => $resourceCode,
            'resource_name' => 'Затраты труда рабочих',
            'unit' => 'h',
            'base_price' => '600.0000',
            'price_type' => 'labor',
            'raw_payload' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('estimate_regional_price_versions')
            ->where('id', $regionalPriceVersionId)
            ->update(['status' => 'active', 'updated_at' => $now]);

        return [
            'normative_dataset_id' => $datasetId,
            'normative_dataset_version' => $versionKey,
            'region_id' => $regionId,
            'price_zone_id' => $priceZoneId,
            'period_id' => $periodId,
            'estimate_regional_price_version_id' => $regionalPriceVersionId,
            'price_version' => $versionKey,
            'version_key' => $versionKey,
        ];
    }

    /** @return array<int, list<string>> */
    private function expectedCallsByPage(): array
    {
        $calls = [];
        foreach (range(1, 22) as $page) {
            $calls[$page] = $page <= 2
                ? ['observer_literal']
                : ['observer_literal', 'observer_construction', 'observer_risk', 'arbiter'];
        }

        return $calls;
    }
}

final class RecordedFullPdfProjectSynthesisModel implements ProjectSynthesisModel
{
    public int $logicalCalls = 0;

    public int $physicalCalls = 0;

    public function synthesize(
        ProjectSynthesisInput $input,
        array $candidateLinks,
        array $candidateQuestions,
        callable $onPhysicalAttemptReserved,
    ): array {
        $this->logicalCalls++;
        $fingerprint = $input->fingerprint();
        $hex = hash('sha256', 'recorded-project-synthesis|'.$fingerprint);
        $attemptId = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
        $now = new DateTimeImmutable;
        DB::table('estimate_generation_vision_physical_attempts')->insertOrIgnore([
            'attempt_id' => $attemptId,
            'request_fingerprint' => $fingerprint,
            'logical_request_fingerprint' => $fingerprint,
            'organization_id' => $input->organizationId,
            'project_id' => $input->projectId,
            'session_id' => $input->sessionId,
            'state' => 'completed',
            'response_payload' => json_encode([
                'recording' => 'ar-1-project-synthesis-v1',
                'accepted_link_ids' => [],
                'question_conflict_ids' => [],
            ], JSON_THROW_ON_ERROR),
            'status' => 'success',
            'http_code' => 200,
            'duration_ms' => 1,
            'reported_model' => 'openai/gpt-5.6-luna',
            'price_snapshot' => '{}',
            'usage_recorded' => true,
            'response_received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $onPhysicalAttemptReserved($attemptId);
        $this->physicalCalls++;

        return ['accepted_link_ids' => [], 'question_conflict_ids' => []];
    }
}

final class RecordedFullPdfEstimateComposerModel implements EstimateComposerModel
{
    public int $logicalCalls = 0;

    public int $physicalCalls = 0;

    public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
    {
        $this->logicalCalls++;
        $intents = array_map(static fn (array $candidate): array => [
            'kind' => 'existing',
            'candidate_id' => $candidate['candidate_id'],
            'work_key' => null,
            'name' => null,
            'derived_quantity_id' => null,
            'source_fact_ids' => $candidate['source_fact_ids'],
            'technology_package_candidate' => $candidate['technology_package_candidate'],
            'assumptions' => [],
            'exclusions' => [],
            'missing_document_recommendations' => [],
        ], $input->candidates);
        if ($intents === []) {
            $quantity = array_values(array_filter(
                $input->derivedQuantities,
                static fn (array $quantity): bool => ($quantity['unit'] ?? null) === 'm2'
                    && is_numeric($quantity['value'] ?? null)
                    && (float) $quantity['value'] > 0,
            ))[0] ?? null;
            $source = array_values(array_filter(
                $input->facts,
                static fn (array $fact): bool => ($fact['status'] ?? null) === 'confirmed'
                    && ($fact['type'] ?? null) === 'area'
                    && is_numeric($fact['value'] ?? null),
            ))[0] ?? null;
            if (is_array($source) && is_array($quantity) && is_string($quantity['id'] ?? null)) {
                $intents[] = [
                    'kind' => 'supplementary',
                    'candidate_id' => null,
                    'work_key' => 'floor-screed-from-documented-area',
                    'name' => 'Устройство цементной стяжки пола',
                    'derived_quantity_id' => $quantity['id'],
                    'source_fact_ids' => [$source['id']],
                    'technology_package_candidate' => null,
                    'assumptions' => [],
                    'exclusions' => [],
                    'missing_document_recommendations' => [],
                ];
            }
        }
        if ($intents === []) {
            throw new \RuntimeException('recorded_composer_source_grounding_missing');
        }
        $attemptId = RecordedFullPdfPhysicalAttempt::uuid('composer|'.$input->fingerprint());
        $onPhysicalAttemptReserved($attemptId);
        RecordedFullPdfPhysicalAttempt::complete($attemptId, [
            'recording' => 'ar-1-estimate-composer-v1',
            'work_intents' => $intents,
        ]);
        $this->physicalCalls++;

        return ['work_intents' => $intents];
    }
}

final class RecordedFullPdfEstimateAuditModel implements EstimateAuditModel
{
    public int $logicalCalls = 0;

    public int $physicalCalls = 0;

    public function audit(EstimateAuditInput $input, callable $onAttemptStarted): array
    {
        $this->logicalCalls++;
        $attemptId = RecordedFullPdfPhysicalAttempt::uuid('audit|'.$input->fingerprint());
        $onAttemptStarted($attemptId);
        RecordedFullPdfPhysicalAttempt::complete($attemptId, [
            'recording' => 'ar-1-estimate-audit-v1',
            'accepted' => true,
            'findings' => [],
        ]);
        $this->physicalCalls++;

        return ['accepted' => true, 'findings' => []];
    }
}

final class RecordedFullPdfPhysicalAttempt
{
    /** @param array<string,mixed> $response */
    public static function complete(string $attemptId, array $response): void
    {
        DB::table('estimate_generation_vision_physical_attempts')
            ->where('attempt_id', $attemptId)
            ->update([
                'state' => 'completed',
                'owner_token' => null,
                'lease_expires_at' => null,
                'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
                'status' => 'success',
                'http_code' => 200,
                'duration_ms' => 1,
                'reported_model' => 'recorded-model',
                'price_snapshot' => '{}',
                'usage_recorded' => true,
                'response_received_at' => new DateTimeImmutable,
                'updated_at' => new DateTimeImmutable,
            ]);
    }

    public static function uuid(string $value): string
    {
        $hex = hash('sha256', $value);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}

final class RecordedFullPdfVisionProvider implements VisionProvider
{
    public int $logicalCalls = 0;

    public int $physicalCalls = 0;

    /** @var array<int, list<string>> */
    public array $callsByPage = [];

    /** @var array<int, int> */
    public array $arbitrationClaimCounts = [];

    /** @var array<int, array<string, mixed>> */
    private array $pages = [];

    /** @param list<array<string, mixed>> $pages */
    public function __construct(
        array $pages,
        private readonly VisionPhysicalAttemptStore $physicalAttempts,
        private readonly AiUsageStore $usage,
    ) {
        foreach ($pages as $page) {
            $this->pages[(int) $page['page']] = $page;
        }
    }

    public function analyze(VisionDocumentInput $input): VisionAnalysisData
    {
        $role = isset($input->auxiliaryMetadata['arbitration'])
            ? 'arbiter'
            : 'observer_'.(string) ($input->auxiliaryMetadata['observer']['profile'] ?? 'unknown');
        $this->logicalCalls++;
        $this->callsByPage[$input->pageNumber][] = $role;
        $requestFingerprint = hash('sha256', implode('|', [
            $input->pageNumber,
            $role,
            $input->sourceVersion,
            $input->derivativeHash,
        ]));
        $ownerToken = $this->uuid($input->pageNumber, $role.'|owner');
        $now = new DateTimeImmutable;
        $this->physicalAttempts->claim(
            $input->operationContext,
            $requestFingerprint,
            $ownerToken,
            $now,
            $now->modify('+5 minutes'),
        );
        $physicalAttemptId = $input->operationContext->attemptId;
        ($input->onPhysicalAttemptReserved)($physicalAttemptId);
        $this->physicalAttempts->markWireStarted(
            $physicalAttemptId,
            $requestFingerprint,
            $ownerToken,
            $now,
            $now->modify('+5 minutes'),
        );
        $this->physicalCalls++;

        $analysis = $role === 'arbiter'
            ? $this->arbitration($input)
            : $this->observation($input, $role);
        $priceSnapshot = AiPriceSnapshot::fromArray([
            'input_per_million' => '0',
            'cached_input_per_million' => '0',
            'output_per_million' => '0',
            'image_unit' => '0',
            'page_unit' => '0',
            'currency' => 'RUB',
            'source' => 'fixture',
            'version' => 'recorded-ar-1-v1',
            'effective_at' => '2026-08-15T00:00:00+03:00',
        ]);
        $this->physicalAttempts->storeResponse(
            $physicalAttemptId,
            $requestFingerprint,
            $ownerToken,
            ['recording' => 'ar-1-v1', 'page' => $input->pageNumber, 'role' => $role],
            'success',
            200,
            1,
            'openai/gpt-5.6-luna',
            $priceSnapshot->toArray(),
        );
        $this->usage->record(new AiUsageData(
            context: $input->operationContext,
            provider: 'recorded',
            requestedModel: 'openai/gpt-5.6-luna',
            status: 'succeeded',
            durationMs: 1,
            reportedModel: 'openai/gpt-5.6-luna',
            usageStatus: 'measured',
            inputTokens: 100,
            outputTokens: 50,
            imageCount: $input->imageDetail === null ? 0 : 1,
            pageCount: 1,
            imageDetail: $input->imageDetail,
            httpCode: 200,
            priceSnapshot: $priceSnapshot,
        ));
        $this->physicalAttempts->markUsageRecorded($physicalAttemptId, $requestFingerprint);

        return $analysis;
    }

    private function observation(VisionDocumentInput $input, string $role): VisionAnalysisData
    {
        $page = $this->pages[$input->pageNumber];
        $profile = str_replace('observer_', '', $role);
        $evidenceKey = sprintf('page-%d-%s', $input->pageNumber, $profile);
        $factValue = match (true) {
            $input->pageNumber === 3 && $profile === 'literal' => 80.0,
            $profile === 'literal' => (string) $page['title'],
            $profile === 'construction' => 'Кладка и отделка учитываются раздельно',
            $profile === 'risk' => $input->pageNumber === 5 ? 'Требуется подтвердить толщину наружной стены' : 'Требуется проверка размерных границ',
            default => 'Наблюдение',
        };
        $factType = match (true) {
            $input->pageNumber === 3 && $profile === 'literal' => 'area',
            $profile === 'literal' => 'note',
            $profile === 'construction' => 'material',
            default => 'risk',
        };
        $payload = [
            'schema_version' => 4,
            'sheet_type' => in_array($page['kind'], ['drawing'], true) ? 'floor_plan' : 'schedule',
            'evidence' => [[
                'key' => $evidenceKey,
                'locator' => [
                    'page_id' => $input->pageId,
                    'page_number' => $input->pageNumber,
                    'processing_unit_id' => $input->processingUnitId,
                    'source_version' => $input->sourceVersion,
                    'coordinate_space' => 'normalized_derivative_v1',
                    'explicit' => $profile === 'literal',
                ],
            ]],
            'elements' => [],
            'scale_candidates' => [],
            'warnings' => ['scale_missing'],
            'visual_attributes' => [],
            'project_sheet_analysis' => [
                'contractVersion' => 'sheet-analysis:v3',
                'role' => 'unknown',
                'facts' => [[
                    'entityKey' => $input->pageNumber === 3 && $profile === 'literal'
                        ? 'room:main'
                        : 'building-1',
                    'factType' => $factType,
                    'value' => ['type' => is_float($factValue) ? 'number' : 'string', 'data' => $factValue],
                    'unit' => is_float($factValue) ? 'm2' : null,
                    'evidenceRef' => $evidenceKey,
                    'sourcePolygonOrNativeRef' => [[0.1, 0.1], [0.9, 0.9]],
                    'confidence' => 0.91,
                    'contractVersion' => 'sheet-analysis:v3',
                ]],
            ],
            'analysis_routing' => [
                'page_kind' => $page['kind'],
                'requested_depth' => $page['route'],
                'information_density' => $page['route'] === 'simple_context' ? 'low' : 'high',
                'readability' => 'high',
                'confidence' => 0.98,
                'ambiguous' => false,
                'material_risk' => $page['route'] === 'simple_context' ? 'low' : 'high',
                'reasons' => ['recorded_route_for_full_pdf_gate'],
                'semantic_regions' => [],
            ],
        ];

        return VisionAnalysisData::fromProviderArray(
            $payload,
            'recorded',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'recorded-ar-1-v1',
            'measured',
            100,
            50,
            64,
            64,
            $input->nativeReferences,
        );
    }

    private function arbitration(VisionDocumentInput $input): VisionAnalysisData
    {
        $claims = $input->auxiliaryMetadata['arbitration']['claims'] ?? [];
        $this->arbitrationClaimCounts[$input->pageNumber] = is_array($claims) ? count($claims) : 0;
        $decisions = array_map(function (array $claim) use ($input): array {
            $claimId = (string) $claim['id'];
            $role = (string) $claim['role'];
            $evidenceRef = (string) $claim['evidence_ref'];
            $hasExplicitEvidence = ($claim['explicit_evidence'] ?? false) === true;
            $isConstruction = str_contains($role, 'construction');
            $isConstructionConflict = $input->pageNumber === 5 && $isConstruction;

            return [
                'claim_id' => $claimId,
                'status' => match (true) {
                    $hasExplicitEvidence => 'accepted',
                    $isConstructionConflict => 'unresolved',
                    default => 'candidate',
                },
                'supporting_claim_ids' => [$claimId],
                'evidence_refs' => [$evidenceRef],
                'reason_code' => match (true) {
                    $hasExplicitEvidence => 'explicit_source_observation',
                    $isConstructionConflict => 'source_conflict',
                    $isConstruction => 'construction_interpretation_requires_review',
                    default => 'bounded_risk_observation',
                },
                ...($isConstructionConflict ? ['question' => [
                    'code' => 'wall_material_conflict_page_5',
                    'subject' => 'Материал наружной стены',
                    'reason' => 'Наблюдатели по-разному определили материал наружной стены.',
                    'impact' => 'От ответа зависят состав работ, нормы и стоимость материалов.',
                    'recommendation' => 'Выберите материал, подтверждённый проектной документацией.',
                    'choices' => ['Газобетон', 'Керамический блок', 'Оставить нерешённым'],
                    'source_locator' => ['page_number' => 5, 'evidence_refs' => [$evidenceRef]],
                ]] : []),
            ];
        }, $claims);

        return new VisionAnalysisData(
            'detail',
            [VisionEvidenceData::fromArray([
                'key' => 'arbiter-page-'.$input->pageNumber,
                'locator' => [
                    'page_id' => $input->pageId,
                    'page_number' => $input->pageNumber,
                    'processing_unit_id' => $input->processingUnitId,
                    'source_version' => $input->sourceVersion,
                    'coordinate_space' => 'normalized_derivative_v1',
                ],
            ])],
            [],
            [],
            ['scale_missing'],
            'recorded',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'recorded-ar-1-v1',
            'measured',
            100,
            50,
            [],
            null,
            [],
            $decisions,
        );
    }

    private function uuid(int $page, string $role): string
    {
        $hex = hash('sha256', $page.'|'.$role);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
