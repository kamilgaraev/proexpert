<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisModel;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningResult;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationEvidence;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Questions\AnswerEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore;
use App\BusinessModules\Addons\EstimateGeneration\Vision\RoleVisionResponseCanonicalizer;
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
            $recording['recorded_source_facts'],
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
        $corrector = new RecordedFullPdfEstimateComposerCorrectionModel;
        $this->app->instance(EstimateComposerCorrectionModel::class, $corrector);
        config()->set('estimate-generation.ocr.geometry.python_binary', 'python');
        config()->set('estimate-generation.ocr.geometry.timeout_seconds', 180);
        config()->set('estimate-generation.generation.document_cost_limit_rub', '100000.00');
        config()->set('estimate-generation.generation.session_cost_limit_rub', '100000.00');
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
                'description' => 'Одноэтажный жилой дом. Требуется устройство цементной стяжки пола по документированной площади.',
                'building_type' => 'house',
                'generation_mode' => 'ai_assisted',
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
            try {
                $processor->handle((int) $unit->id, $sourceVersion);
            } catch (\Throwable $error) {
                throw new \RuntimeException('full_pdf_page_failed:'.$unit->unit_index, 0, $error);
            }
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
        self::assertSame([2 => 0, 3 => 0, 4 => 0], $provider->rawCapturedFactCounts);
        $expectedArbitrationClaimCounts = array_fill_keys(range(3, 22), 3);
        $expectedArbitrationClaimCounts[3] = 28;
        $expectedArbitrationClaimCounts[4] = 31;
        $expectedArbitrationClaimCounts[5] = 15;
        $expectedArbitrationClaimCounts[9] = 2;
        $expectedArbitrationClaimCounts[11] = 2;
        self::assertSame($expectedArbitrationClaimCounts, $provider->arbitrationClaimCounts);
        self::assertSame([2, 1], array_values($provider->arbitrationValueCounts[6]));

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
        self::assertSame($sourceVersion, $document->units_finalized_source_version);
        self::assertSame($sourceVersion, $document->units_reconciled_source_version);
        self::assertNotSame('processing', $document->status);
        self::assertSame(0, $document->processingUnits->filter(
            static fn (EstimateGenerationProcessingUnit $unit): bool => in_array(
                $unit->status->value,
                ['pending', 'running'],
                true,
            ),
        )->count());
        self::assertNotSame('processing_documents', $session->fresh()->status->value);
        self::assertCount(22, $document->pages);
        self::assertSame(22, $document->pages->whereIn('status', ['ready', 'needs_review'])->count());
        $capturedPageFourFacts = [
            'building_dimensions' => ['11,1 x 7,3', 'm'],
            'building_height' => ['4.32', 'm'],
            'building_area_total' => ['72.19', 'm2'],
            'building_area_without_terrace' => ['50.09', 'm2'],
            'above_ground_storeys' => ['1', 'pcs'],
            'below_ground_storeys' => ['0', 'pcs'],
            'foundation_type' => ['свайно-винтовой; также указана утепленная шведская плита (УШП)', null],
            'external_wall_construction' => ['доска сухая обрезная 42x142, шаг 0,58 м', null],
            'internal_wall_construction' => ['доска сухая обрезная 42x92, шаг 0,58 м', null],
            'facade_finish' => ['каменная штукатурка, имитация бруса', null],
            'attic_floor' => ['деревянные балки', null],
            'roof_form' => ['двускатная', null],
            'roof_covering' => ['битумная черепица', null],
            'facade_area' => ['77.01', 'm2'],
            'roof_area' => ['92.58', 'm2'],
        ];
        foreach ($document->pages->sortBy('page_number') as $page) {
            $payload = is_array($page->normalized_payload) ? $page->normalized_payload : [];
            $observations = is_array($payload['independent_observations'] ?? null)
                ? $payload['independent_observations']
                : [];
            $expectedObserverCount = (int) $page->page_number <= 2 ? 1 : 3;
            self::assertCount($expectedObserverCount, $observations);
            foreach ($observations as $observation) {
                $claims = is_array($observation['claims'] ?? null) ? $observation['claims'] : [];
                $isMalformedObservation = (int) $page->page_number === 9
                    && ($observation['role'] ?? null) === 'observer_risk';
                $isCapturedLiteral = ($observation['role'] ?? null) === 'observer_literal';
                $expectedClaims = match (true) {
                    $isMalformedObservation => 0,
                    $isCapturedLiteral && (int) $page->page_number === 3 => 26,
                    $isCapturedLiteral && (int) $page->page_number === 4 => 29,
                    (int) $page->page_number === 5 => 5,
                    $isCapturedLiteral && (int) $page->page_number === 11 => 0,
                    default => 1,
                };
                $expectedEvidence = match (true) {
                    $isCapturedLiteral && (int) $page->page_number === 3 => 25,
                    $isCapturedLiteral && (int) $page->page_number === 4 => 5,
                    $isCapturedLiteral && (int) $page->page_number === 11 => 10,
                    default => 1,
                };
                self::assertCount($expectedClaims, $claims);
                self::assertCount(
                    $expectedEvidence,
                    is_array($observation['evidence'] ?? null) ? $observation['evidence'] : [],
                );
                if ($isCapturedLiteral && (int) $page->page_number === 2) {
                    self::assertCount(1, $observation['observation']['elements'] ?? []);
                }
                if ($isCapturedLiteral && (int) $page->page_number === 4) {
                    $claimsByEntity = array_column($claims, null, 'entityKey');
                    foreach ($capturedPageFourFacts as $entityKey => [$expectedValue, $expectedUnit]) {
                        self::assertArrayHasKey($entityKey, $claimsByEntity);
                        self::assertSame($expectedValue, $claimsByEntity[$entityKey]['value']['data'] ?? null);
                        self::assertSame($expectedUnit, $claimsByEntity[$entityKey]['unit'] ?? null);
                    }
                }
                if ($isCapturedLiteral && (int) $page->page_number === 11) {
                    self::assertCount(16, $observation['observation']['elements'] ?? []);
                    self::assertNotContains('scale_missing', $observation['observation']['warnings'] ?? []);
                    self::assertSame(
                        'scale_missing_warning_mismatch',
                        $observation['observation']['quarantined_items'][0]['reason'] ?? null,
                    );
                }
                if ($isMalformedObservation) {
                    self::assertSame('project_sheet_analysis',
                        $observation['observation']['quarantined_items'][0]['section'] ?? null);
                }
            }
            if (in_array((int) $page->page_number, [7, 10], true)) {
                $literalObservation = array_values(array_filter(
                    $observations,
                    static fn (array $observation): bool => ($observation['role'] ?? null) === 'observer_literal',
                ))[0] ?? null;
                self::assertIsArray($literalObservation);
                self::assertNotEmpty($literalObservation['observation']['quarantined_items'] ?? []);
                self::assertCount(1, $literalObservation['claims'] ?? []);
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
                self::assertGreaterThanOrEqual(
                    1,
                    count($decisions),
                    'page='.(int) $page->page_number.' decisions='.json_encode($decisions, JSON_THROW_ON_ERROR),
                );
                $expectedDecisionCoverage = match ((int) $page->page_number) {
                    3 => 28,
                    4 => 31,
                    5 => 15,
                    9 => 2,
                    11 => 2,
                    default => 3,
                };
                self::assertSame($expectedDecisionCoverage, array_sum(array_map(
                    static fn (array $decision): int => count($decision['supporting_claim_ids'] ?? []),
                    $decisions,
                )) + count($quarantined));
                if ((int) $page->page_number === 4) {
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
                    $areaDecision = array_values(array_filter(
                        $decisions,
                        static fn (array $decision): bool => ($decision['canonical_claim']['entity_key'] ?? null)
                            === 'building_area_total',
                    ))[0] ?? null;
                    self::assertIsArray($areaDecision);
                    self::assertSame('accepted', $areaDecision['status'] ?? null);
                    self::assertSame('72.19', $areaDecision['canonical_claim']['value']['data'] ?? null);
                    self::assertSame('m2', $areaDecision['canonical_claim']['unit'] ?? null);
                    self::assertNotEmpty($areaDecision['evidence_refs'] ?? []);
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
        self::assertSame(14, DB::table('estimate_generation_project_model_assertions')
            ->where('session_id', $session->id)
            ->where('assertion_type', 'area')
            ->where('fact_status', 'confirmed')
            ->count());
        self::assertSame(14, DB::table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->where('projection.session_id', $session->id)
            ->where('projection.is_current', true)
            ->where('fact.assertion_type', 'area')
            ->where('fact.fact_status', 'confirmed')
            ->count());
        self::assertSame(12, DB::table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->join('estimate_generation_project_model_entities as entity', 'entity.id', '=', 'fact.entity_id')
            ->where('projection.session_id', $session->id)
            ->where('projection.is_current', true)
            ->where('fact.assertion_type', 'area')
            ->where('fact.fact_status', 'confirmed')
            ->where('entity.entity_kind', 'dimension')
            ->count());

        $documentUnderstanding = is_array($document->facts_summary['document_understanding'] ?? null)
            ? $document->facts_summary['document_understanding']
            : [];
        $pageFourPayload = $document->pages->firstWhere('page_number', 4)?->normalized_payload;
        self::assertSame('drawing_analysis', $documentUnderstanding['role_for_estimation'] ?? null, json_encode($document->facts_summary, JSON_THROW_ON_ERROR));
        self::assertTrue($documentUnderstanding['extracted_capabilities']['has_quantities'] ?? false, json_encode([
            'understanding' => $documentUnderstanding,
            'observations' => is_array($pageFourPayload) ? ($pageFourPayload['independent_observations'] ?? null) : null,
            'arbitration' => is_array($pageFourPayload) ? ($pageFourPayload['document_arbitration'] ?? null) : null,
        ], JSON_THROW_ON_ERROR));

        $projectModels = app(ProjectModelRepository::class);
        $currentProjectFacts = $projectModels->currentFacts(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
        );
        $acceptedAreaFacts = array_values(array_filter(
            $currentProjectFacts,
            static fn ($fact): bool => $fact->type === 'area'
                && $fact->status === 'confirmed'
                && $fact->sourceVersion === $sourceVersion
                && (string) $fact->value === $recording['recorded_source_facts']['total_area_m2'],
        ));
        self::assertCount(1, $acceptedAreaFacts);
        $acceptedAreaFact = $acceptedAreaFacts[0];
        self::assertSame($recording['recorded_source_facts']['total_area_m2'], (string) $acceptedAreaFact->value);
        $pageFiveVisualCandidates = DB::table('estimate_generation_project_model_assertions as fact')
            ->join('estimate_generation_project_model_fact_evidence as binding', 'binding.fact_id', '=', 'fact.id')
            ->join('estimate_generation_evidence as evidence', 'evidence.id', '=', 'binding.evidence_id')
            ->join('estimate_generation_project_model_entities as entity', 'entity.id', '=', 'fact.entity_id')
            ->where('fact.session_id', $session->id)
            ->where('fact.source_version', $sourceVersion)
            ->whereIn('fact.assertion_type', ['sanitary_fixture', 'kitchen_fixture'])
            ->where('fact.fact_status', 'candidate')
            ->where('evidence.locator->page', 5)
            ->select(['fact.id', 'fact.assertion_type', 'fact.fact_value', 'entity.stable_key', 'entity.payload'])
            ->distinct()
            ->get();
        self::assertCount(2, $pageFiveVisualCandidates, $pageFiveVisualCandidates->toJson(JSON_PRETTY_PRINT));
        self::assertSame(0, DB::table('estimate_generation_project_model_assertions as fact')
            ->join('estimate_generation_project_model_fact_evidence as binding', 'binding.fact_id', '=', 'fact.id')
            ->join('estimate_generation_evidence as evidence', 'evidence.id', '=', 'binding.evidence_id')
            ->where('fact.session_id', $session->id)
            ->where('fact.source_version', $sourceVersion)
            ->where('fact.assertion_type', 'furniture')
            ->where('evidence.locator->page', 5)
            ->distinct('fact.id')
            ->count('fact.id'));

        $areaDocumentFact = DB::table('estimate_generation_document_facts')
            ->where('document_id', $document->id)
            ->where('fact_type', 'total_area')
            ->where('scope_key', 'building_area_total')
            ->where('unit', 'm2')
            ->where('confidence', '1.00')
            ->selectRaw('value_number::text as value_number')
            ->first();
        self::assertNotNull($areaDocumentFact);
        self::assertSame('72.1900', $areaDocumentFact->value_number);
        self::assertSame(28, DB::table('estimate_generation_document_facts')
            ->where('document_id', $document->id)
            ->where('page_id', $document->pages()->where('page_number', 4)->value('id'))
            ->where('normalized_payload->projection', 'arbiter_consensus:v1')
            ->count());
        $expectedDocumentFacts = [
            'building_dimensions' => ['11,1 x 7,3', null],
            'building_height' => [null, '4.3200'],
            'building_area_total' => [null, '72.1900'],
            'building_area_without_terrace' => [null, '50.0900'],
            'above_ground_storeys' => [null, '1.0000'],
            'below_ground_storeys' => [null, '0.0000'],
            'foundation_type' => ['свайно-винтовой; также указана утепленная шведская плита (УШП)', null],
            'external_wall_construction' => ['доска сухая обрезная 42x142, шаг 0,58 м', null],
            'internal_wall_construction' => ['доска сухая обрезная 42x92, шаг 0,58 м', null],
            'facade_finish' => ['каменная штукатурка, имитация бруса', null],
            'attic_floor' => ['деревянные балки', null],
            'roof_form' => ['двускатная', null],
            'roof_covering' => ['битумная черепица', null],
            'facade_area' => [null, '77.0100'],
            'roof_area' => [null, '92.5800'],
        ];
        foreach ($expectedDocumentFacts as $scopeKey => [$expectedText, $expectedNumber]) {
            $fact = DB::table('estimate_generation_document_facts')
                ->where('document_id', $document->id)
                ->where('scope_key', $scopeKey)
                ->selectRaw('value_text, value_number::text as value_number')
                ->first();
            self::assertNotNull($fact, $scopeKey);
            self::assertSame($expectedText, $fact->value_text, $scopeKey);
            self::assertSame($expectedNumber, $fact->value_number, $scopeKey);
        }
        $currentFactsByEntity = [];
        foreach ($currentProjectFacts as $fact) {
            if ($fact->status === 'confirmed' && $fact->sourceVersion === $sourceVersion) {
                $currentFactsByEntity[$fact->entityId] = (string) $fact->value;
            }
        }
        foreach ([
            'building_height' => ['dimension', '4.32'],
            'building_area_total' => ['room', '72.19'],
            'building_area_without_terrace' => ['dimension', '50.09'],
            'above_ground_storeys' => ['dimension', '1'],
            'below_ground_storeys' => ['dimension', '0'],
            'external_wall_construction' => ['material', 'доска сухая обрезная 42x142, шаг 0,58 м'],
            'internal_wall_construction' => ['material', 'доска сухая обрезная 42x92, шаг 0,58 м'],
            'attic_floor' => ['material', 'деревянные балки'],
            'roof_covering' => ['material', 'битумная черепица'],
            'facade_area' => ['dimension', '77.01'],
            'roof_area' => ['dimension', '92.58'],
        ] as $entityKey => [$entityType, $expectedValue]) {
            $entityId = 'entity:'.hash('sha256', implode('|', [$entityType, $entityKey]));
            self::assertArrayHasKey($entityId, $currentFactsByEntity, $entityKey);
            self::assertSame($expectedValue, $currentFactsByEntity[$entityId], $entityKey);
        }

        app(ProjectUnderstandingCoordinator::class)->refresh(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            (string) Str::uuid(),
            1,
        );

        $questions = app(ListEstimateClarifications::class)->handle(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
        );
        self::assertNotEmpty($questions);
        $materialQuestion = array_values(array_filter(
            $questions,
            static fn (array $question): bool => ($question['subject'] ?? null)
                === 'В документах указаны разные значения для «материал». Какое значение использовать?',
        ))[0] ?? null;
        self::assertIsArray($materialQuestion);
        self::assertMatchesRegularExpression('/^project_question_[a-f0-9]{32}$/D', (string) $materialQuestion['code']);
        self::assertSame(
            'В документах указаны разные значения для «материал». Какое значение использовать?',
            $materialQuestion['subject'],
        );
        self::assertSame([5], $materialQuestion['source_locator']['page_numbers']);
        self::assertSame($sourceVersion, $materialQuestion['source_version']);
        self::assertSame($synthesis->selectedConflictId, $materialQuestion['source_locator']['conflict_id']);
        self::assertSame(
            [['type' => 'choice_quarantined', 'count' => 1]],
            $materialQuestion['source_locator']['limitations'],
        );
        $fixtureQuestion = array_values(array_filter(
            $questions,
            static fn (array $question): bool => str_contains((string) ($question['text'] ?? ''), 'Унитаз')
                || str_contains((string) ($question['text'] ?? ''), 'Кухонная мойка'),
        ))[0] ?? null;
        self::assertIsArray($fixtureQuestion);
        self::assertSame([5], $fixtureQuestion['source_locator']['page_numbers']);
        self::assertStringNotContainsString('Кровать', (string) $fixtureQuestion['text']);
        self::assertNotContains(str_repeat('Повреждённый вариант ', 9), array_column($materialQuestion['choices'], 'label'));
        self::assertContains('other', array_column($materialQuestion['choices'], 'value'));
        self::assertContains('leave_unresolved', array_column($materialQuestion['choices'], 'value'));

        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $reanalysis = new RecordedFullPdfPlanningReanalysisTrigger(app(ProjectPlanningPipeline::class));
        $this->app->instance(PlanningReanalysisTrigger::class, $reanalysis);
        $selectedChoice = array_values(array_filter(
            $materialQuestion['choices'],
            static fn (array $choice): bool => ! in_array(
                $choice['value'] ?? null,
                ['other', 'leave_unresolved'],
                true,
            ),
        ))[0] ?? null;
        self::assertIsArray($selectedChoice);
        $selectedResponse = (string) ($selectedChoice['value'] ?? '');
        self::assertNotSame('', $selectedResponse);
        $answer = app(AnswerEstimateClarification::class)->handle(
            $user,
            $session,
            new ActorContext(
                (int) $organization->id,
                (int) $project->id,
                (int) $user->id,
                'full-pdf-question-answer-0001',
                (string) $materialQuestion['source_version'],
                (string) $materialQuestion['answer_fingerprint'],
            ),
            (string) $materialQuestion['code'],
            $selectedResponse,
        );
        self::assertSame('answered', $answer->status);
        self::assertInstanceOf(ProjectPlanningResult::class, $reanalysis->result);
        self::assertTrue($reanalysis->result->isReadyForCompleteness(), json_encode([
            'status' => $reanalysis->result->status,
            'limitations' => $reanalysis->result->limitations,
            'source_version' => $reanalysis->result->sourceVersion,
            'input_fingerprint' => $reanalysis->result->inputFingerprint,
            'current_fact_states' => DB::table('estimate_generation_project_model_fact_projections as projection')
                ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
                ->where('projection.session_id', $session->id)
                ->where('projection.is_current', true)
                ->selectRaw('fact.assertion_type, fact.fact_status, fact.fact_origin, count(*) as aggregate')
                ->groupBy('fact.assertion_type', 'fact.fact_status', 'fact.fact_origin')
                ->orderBy('fact.assertion_type')
                ->orderBy('fact.fact_status')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $synthesis->physicalCalls);
        self::assertSame(13, DB::table('estimate_generation_project_model_fact_projections as projection')
            ->join('estimate_generation_project_model_assertions as fact', 'fact.id', '=', 'projection.fact_id')
            ->where('projection.session_id', $session->id)
            ->where('projection.is_current', true)
            ->where('fact.assertion_type', 'area')
            ->where('fact.fact_status', 'confirmed')
            ->count());
        $remainingQuestions = app(ListEstimateClarifications::class)->handle(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
        );
        self::assertSame([], $remainingQuestions, json_encode(array_map(
            static fn (array $question): array => [
                'subject' => $question['subject'] ?? null,
                'pages' => $question['source_locator']['page_numbers'] ?? [],
                'choices' => array_column($question['choices'] ?? [], 'label'),
            ],
            $remainingQuestions,
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame([], app(ListEstimateClarifications::class)->handle(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
        ));

        $planningCapture = $projectModels->snapshotForPlanning(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            10_001,
        );
        $technology = $projectModels->currentTechnologyRecommendations(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
        );
        $completeness = $projectModels->currentCompleteness(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
        );
        $stageFiveDiagnostic = json_encode([
            'token' => $planningCapture['token'],
            'technology' => $technology,
            'completeness' => $completeness,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertIsArray($technology, $stageFiveDiagnostic);
        self::assertIsArray($completeness, $stageFiveDiagnostic);
        self::assertTrue($technology['is_current'] ?? false, $stageFiveDiagnostic);
        self::assertTrue($completeness['is_current'] ?? false, $stageFiveDiagnostic);
        self::assertSame($planningCapture['token'], $technology['input_fingerprint'] ?? null, $stageFiveDiagnostic);
        self::assertSame($planningCapture['token'], $completeness['input_fingerprint'] ?? null, $stageFiveDiagnostic);

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
                ], JSON_THROW_ON_ERROR) : json_encode([
                    'stage' => $expectedStage->value,
                    'regional_context' => $payload['regional_context'] ?? null,
                    'normative_context_pin' => $payload['normative_context_pin'] ?? null,
                    'candidates' => $this->workItemDiagnostics($localEstimates),
                ], JSON_THROW_ON_ERROR);
                self::assertGreaterThan(0, $this->workItemCount($localEstimates), $diagnostic);
                if ($expectedStage === ProcessingStage::PlanWorkItems) {
                    self::assertSame('pinned', $payload['normative_context_pin']['status'] ?? null, $diagnostic);
                    self::assertNotNull(
                        $this->workItemDiagnostics($localEstimates)[0]['quantity_evidence']['formula_inputs']['snapshot_identity']['input_fingerprint'] ?? null,
                        $diagnostic,
                    );
                }
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
        self::assertTrue(BigDecimal::of((string) $pricedItem['quantity'])->isEqualTo(
            (string) $recording['recorded_source_facts']['total_area_m2'],
        ));
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
        self::assertSame(2, $auditor->logicalCalls);
        self::assertSame(2, $auditor->physicalCalls);
        self::assertSame(1, $corrector->logicalCalls);
        self::assertSame(1, $corrector->physicalCalls);
        self::assertSame('accepted', $draft['independent_audit']['status'] ?? null);
        self::assertSame(1, $draft['independent_audit']['correction_cycles'] ?? null);
        self::assertCount(2, $draft['independent_audit']['history'] ?? []);
        $beforeFullReplay = [
            'model_calls' => [
                $provider->logicalCalls, $provider->physicalCalls,
                $synthesis->logicalCalls, $synthesis->physicalCalls,
                $composer->logicalCalls, $composer->physicalCalls,
                $auditor->logicalCalls, $auditor->physicalCalls,
                $corrector->logicalCalls, $corrector->physicalCalls,
            ],
            'usage_count' => DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count(),
            'usage_cost' => (string) DB::table('estimate_generation_ai_usage')
                ->where('session_id', $session->id)
                ->sum('cost_amount'),
            'role_runs' => DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->count(),
            'checkpoints' => DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)->count(),
            'project_facts' => DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count(),
            'draft_hash' => hash('sha256', json_encode($session->draft_payload, JSON_THROW_ON_ERROR)),
        ];

        try {
            $pipeline->run($generationSnapshot);
            self::fail('Completed pipeline replay must be rejected by the exact state-version fence.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState $error) {
            self::assertStringContainsString('no longer has state version', $error->getMessage());
        }
        $session->refresh();

        self::assertSame($beforeFullReplay, [
            'model_calls' => [
                $provider->logicalCalls, $provider->physicalCalls,
                $synthesis->logicalCalls, $synthesis->physicalCalls,
                $composer->logicalCalls, $composer->physicalCalls,
                $auditor->logicalCalls, $auditor->physicalCalls,
                $corrector->logicalCalls, $corrector->physicalCalls,
            ],
            'usage_count' => DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count(),
            'usage_cost' => (string) DB::table('estimate_generation_ai_usage')
                ->where('session_id', $session->id)
                ->sum('cost_amount'),
            'role_runs' => DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->count(),
            'checkpoints' => DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)->count(),
            'project_facts' => DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count(),
            'draft_hash' => hash('sha256', json_encode($session->draft_payload, JSON_THROW_ON_ERROR)),
        ]);
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

    /** @return list<array<string, mixed>> */
    private function workItemDiagnostics(mixed $localEstimates): array
    {
        $diagnostics = [];
        foreach (is_array($localEstimates) ? $localEstimates : [] as $localEstimate) {
            foreach (is_array($localEstimate['sections'] ?? null) ? $localEstimate['sections'] : [] as $section) {
                foreach (is_array($section['work_items'] ?? null) ? $section['work_items'] : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $diagnostics[] = [
                        'key' => $item['key'] ?? null,
                        'pricing_blocker' => $item['pricing_blocker'] ?? null,
                        'quantity_evidence' => $item['quantity_evidence'] ?? null,
                        'normative_match' => $item['normative_match'] ?? null,
                        'normative_retrieval' => $item['normative_retrieval'] ?? null,
                        'price_snapshot' => $item['price_snapshot'] ?? null,
                    ];
                }
            }
        }

        return array_slice($diagnostics, 0, 5);
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
            'name' => 'Устройство стяжек цементных толщиной 20 мм',
            'unit' => 'm2',
            'canonical_unit' => 'm2',
            'unit_dimension' => 'area',
            'material' => 'цементная стяжка',
            'object_type' => 'жилой дом',
            'valid_from' => '2020-01-01',
            'valid_to' => '2030-12-31',
            'section_code' => '11-01',
            'section_name' => 'Стяжки',
            'work_composition' => json_encode(
                ['Устройство стяжек цементных толщиной 20 мм'],
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

final class RecordedFullPdfPlanningReanalysisTrigger implements PlanningReanalysisTrigger
{
    public ?ProjectPlanningResult $result = null;

    public function __construct(private readonly ProjectPlanningPipeline $pipeline) {}

    public function trigger(int $sessionId, ActorContext $context): void
    {
        $this->result = $this->pipeline->refresh(
            $context->organizationId,
            $context->projectId,
            $sessionId,
            (string) Str::uuid(),
            1,
        );
    }
}

final class RecordedFullPdfProjectSynthesisModel implements ProjectSynthesisModel
{
    public int $logicalCalls = 0;

    public int $physicalCalls = 0;

    public ?string $selectedConflictId = null;

    public function synthesize(
        ProjectSynthesisInput $input,
        array $candidateLinks,
        array $candidateQuestions,
        callable $onPhysicalAttemptReserved,
    ): array {
        $this->logicalCalls++;
        $fingerprint = $input->fingerprint();
        $currentMaterialFactIds = array_fill_keys(array_column(array_filter(
            $input->facts,
            static fn (array $fact): bool => ($fact['current'] ?? false) === true
                && ($fact['type'] ?? null) === 'material',
        ), 'id'), true);
        $matchingQuestions = array_values(array_filter(
            $candidateQuestions,
            static function (array $question) use ($currentMaterialFactIds): bool {
                $pages = $question['source_locator']['page_numbers'] ?? [];
                $factIds = is_array($question['fact_ids'] ?? null) ? $question['fact_ids'] : [];

                return in_array(5, is_array($pages) ? $pages : [], true)
                    && $factIds !== []
                    && array_diff($factIds, array_keys($currentMaterialFactIds)) === [];
            },
        ));
        usort($matchingQuestions, static fn (array $left, array $right): int => strcmp(
            (string) ($left['conflict_id'] ?? ''),
            (string) ($right['conflict_id'] ?? ''),
        ));
        $hasPersistedDecision = $input->decisions !== [];
        $questionConflictId = $matchingQuestions[0]['conflict_id'] ?? null;
        if (! $hasPersistedDecision && $questionConflictId === null) {
            throw new \LogicException('recorded_project_synthesis_question_candidate_missing');
        }
        if (! $hasPersistedDecision) {
            $this->selectedConflictId = $questionConflictId;
        }
        $selection = [
            'accepted_link_ids' => [],
            'question_conflict_ids' => $hasPersistedDecision ? [] : [$questionConflictId],
        ];
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
                ...$selection,
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

        return $selection;
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
            throw new \RuntimeException('recorded_composer_requires_production_planning_candidates '.json_encode([
                'derived_quantities' => $input->derivedQuantities,
                'facts' => $input->facts,
                'missing_documents' => $input->missingDocuments,
            ], JSON_THROW_ON_ERROR));
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
        $workItem = self::firstWorkItem($input->draft);
        $factId = (string) ($input->facts[0]['id'] ?? '');
        if ($workItem === null || $factId === '') {
            throw new \RuntimeException('recorded_auditor_grounding_missing');
        }
        $result = $input->cycle === 0 ? [
            'accepted' => false,
            'findings' => [[
                'finding_id' => 'full-pdf-quantity-review',
                'type' => 'quantity_mismatch',
                'severity' => 'material',
                'item_key' => $workItem['key'],
                'source_fact_ids' => [$factId],
                'source_locator' => ['fact_id' => $factId],
                'reason' => 'Количество требует повторной сверки с документированной площадью.',
                'impact' => 'Неточная величина изменяет стоимость работы и связанных ресурсов.',
                'recommendation' => 'Повторно применить каноническое количество из проверенного факта.',
                'correction' => ['operation' => 'operator_review'],
            ]],
        ] : ['accepted' => true, 'findings' => []];
        RecordedFullPdfPhysicalAttempt::complete($attemptId, [
            'recording' => 'ar-1-estimate-audit-v1',
            ...$result,
        ]);
        $this->physicalCalls++;

        return $result;
    }

    /** @return array<string,mixed>|null */
    public static function firstWorkItem(array $draft): ?array
    {
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach (is_array($estimate) ? ($estimate['sections'] ?? []) : [] as $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $item) {
                    if (is_array($item) && is_string($item['key'] ?? null)) {
                        return $item;
                    }
                }
            }
        }

        return null;
    }
}

final class RecordedFullPdfEstimateComposerCorrectionModel implements EstimateComposerCorrectionModel
{
    public int $logicalCalls = 0;

    public int $physicalCalls = 0;

    public function correct(EstimateComposerCorrectionInput $input, callable $onPhysicalAttemptReserved): array
    {
        $this->logicalCalls++;
        $item = RecordedFullPdfEstimateAuditModel::firstWorkItem($input->audit->draft);
        $quantity = $input->audit->derivedQuantities[0] ?? null;
        if (! is_array($item) || ! is_array($quantity)
            || ! is_string($item['key'] ?? null) || ! is_string($quantity['id'] ?? null)) {
            throw new \RuntimeException('recorded_correction_grounding_missing');
        }
        $corrections = [[
            'operation' => 'replace_quantity',
            'finding_id' => (string) $input->findings[0]['finding_id'],
            'target_item_key' => $item['key'],
            'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($item),
            'derived_quantity_id' => $quantity['id'],
        ]];
        $attemptId = RecordedFullPdfPhysicalAttempt::uuid('correction|'.$input->fingerprint());
        $onPhysicalAttemptReserved($attemptId);
        RecordedFullPdfPhysicalAttempt::complete($attemptId, [
            'recording' => 'ar-1-estimate-correction-v1',
            'corrections' => $corrections,
        ]);
        $this->physicalCalls++;

        return ['corrections' => $corrections];
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

    /** @var array<int, array<string, int>> */
    public array $arbitrationValueCounts = [];

    /** @var array<int, int> */
    public array $rawCapturedFactCounts = [];

    /** @var array<int, array<string, mixed>> */
    private array $pages = [];

    /** @param list<array<string, mixed>> $pages */
    public function __construct(
        array $pages,
        private readonly array $recordedSourceFacts,
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
            new AiCost('0', 'RUB', 'available'),
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
        if ($role === 'observer_literal' && in_array($input->pageNumber, [2, 3, 4], true)) {
            return $this->capturedProductionObservation($input);
        }
        if ($role === 'observer_literal' && $input->pageNumber === 11) {
            return $this->capturedSession77Observation($input);
        }
        $page = $this->pages[$input->pageNumber];
        $profile = str_replace('observer_', '', $role);
        $evidenceKey = sprintf('page-%d-%s', $input->pageNumber, $profile);
        $factValue = match (true) {
            $input->pageNumber === 4 && $profile === 'literal' => (float) $this->recordedSourceFacts['total_area_m2'],
            $input->pageNumber === 5 && $profile === 'literal' => $this->recordedSourceFacts['majority_wall_material'],
            $input->pageNumber === 5 && $profile === 'construction' => $this->recordedSourceFacts['majority_wall_material'],
            $input->pageNumber === 5 && $profile === 'risk' => str_repeat('Повреждённый вариант ', 9),
            $input->pageNumber === 6 && in_array($profile, ['literal', 'risk'], true) => $this->recordedSourceFacts['majority_wall_material'],
            $input->pageNumber === 6 && $profile === 'construction' => $this->recordedSourceFacts['minority_wall_material'],
            $input->pageNumber === 9 && $profile === 'literal' => '22.10',
            $input->pageNumber === 9 && $profile === 'construction' => '5.50',
            $profile === 'literal' => (string) $page['title'],
            $profile === 'construction' => 'Кладка и отделка учитываются раздельно',
            $profile === 'risk' => 'Требуется проверка размерных границ',
            default => 'Наблюдение',
        };
        $factType = match (true) {
            $input->pageNumber === 4 && $profile === 'literal' => 'area',
            in_array($input->pageNumber, [5, 6], true) => 'material',
            $input->pageNumber === 9 && $profile === 'literal' => 'area',
            $input->pageNumber === 9 && $profile === 'construction' => 'dimension_chain',
            $profile === 'literal' => 'note',
            $profile === 'construction' => 'material',
            default => 'risk',
        };
        $facts = [[
            'entityKey' => match (true) {
                $input->pageNumber === 4 && $profile === 'literal' => 'room:main',
                in_array($input->pageNumber, [5, 6], true) => 'building-1',
                $input->pageNumber === 9 && in_array($profile, ['literal', 'construction'], true) => 'room.kitchen',
                default => sprintf('sheet:page-%d:%s', $input->pageNumber, $profile),
            },
            'factType' => $factType,
            'value' => [
                'type' => is_float($factValue) || ($input->pageNumber === 9 && in_array($profile, ['literal', 'construction'], true))
                    ? 'number'
                    : 'string',
                'data' => $factValue,
            ],
            'unit' => match (true) {
                is_float($factValue) => 'm2',
                $input->pageNumber === 9 && $profile === 'literal' => 'm2',
                $input->pageNumber === 9 && $profile === 'construction' => 'm',
                default => null,
            },
            'evidenceRef' => $evidenceKey,
            'sourcePolygonOrNativeRef' => [[0.1, 0.1], [0.9, 0.9]],
            'confidence' => 0.91,
            'contractVersion' => 'sheet-analysis:v3',
        ]];
        if ($input->pageNumber === 5) {
            $descriptions = match ($profile) {
                'literal' => ['Унитаз', 'Кухонная мойка', 'Кровать условно'],
                'construction' => ['Напольный унитаз', 'Мойка на кухне', 'Условное изображение кровати'],
                default => ['Санитарный прибор: унитаз', 'Раковина кухни', 'Кровать показана условно'],
            };
            foreach ([
                ['room.bathroom.toilet', 'sanitary_fixture', $descriptions[0]],
                ['room.kitchen.sink', 'kitchen_fixture', $descriptions[1]],
                ['room.bedroom.bed', 'furniture', $descriptions[2]],
                ['sheet.page-5.conditional-note', 'note', 'Мебель на плане показана условно и не входит в объём поставки'],
            ] as [$entityKey, $visualFactType, $description]) {
                $facts[] = [
                    ...$facts[0],
                    'entityKey' => $entityKey,
                    'factType' => $visualFactType,
                    'value' => ['type' => 'string', 'data' => $description],
                    'unit' => null,
                ];
            }
        }
        if ($input->pageNumber === 7 && $profile === 'literal') {
            $facts[] = [
                ...$facts[0],
                'entityKey' => 'malformed-secondary-observation',
                'evidenceRef' => 'unknown-evidence',
                'confidence' => 1.2,
            ];
        }
        if ($input->pageNumber === 10 && $profile === 'literal') {
            $facts[] = [
                ...$facts[0],
                'entityKey' => 'unknown-optional-field-observation',
                'unknown_optional_field' => ['provider' => 'future-version'],
            ];
        }
        $elements = $input->pageNumber === 8 && $profile === 'literal' ? [[
            'key' => 'malformed-element',
            'type' => 'text',
            'label' => 'Повреждённая геометрия наблюдения',
            'polygon' => [[0.1, 0.1]],
            'confidence' => 0.9,
            'evidence_ref' => $evidenceKey,
        ]] : [];
        $projectSheetAnalysis = $input->pageNumber === 9 && $profile === 'risk'
            ? [
                'contractVersion' => 'malformed-observation:v1',
                'role' => 'unknown',
                'facts' => 'malformed-observation',
            ]
            : [
                'contractVersion' => 'sheet-analysis:v3',
                'role' => 'unknown',
                'facts' => $facts,
            ];
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
            'elements' => $elements,
            'scale_candidates' => [],
            'warnings' => ['scale_missing'],
            'visual_attributes' => [],
            'project_sheet_analysis' => $projectSheetAnalysis,
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

    private function capturedProductionObservation(VisionDocumentInput $input): VisionAnalysisData
    {
        $payload = json_decode((string) file_get_contents(sprintf(
            '%s/tests/Fixtures/EstimateGeneration/Vision/session-73-page-%d-observer.json',
            dirname(__DIR__, 4),
            $input->pageNumber,
        )), true, flags: JSON_THROW_ON_ERROR);
        try {
            $raw = VisionAnalysisData::fromProviderArray(
                $payload,
                'recorded',
                'openai/gpt-5.6-luna',
                'openai/gpt-5.6-luna',
                'recorded-session-73-v1',
                'measured',
                100,
                50,
                64,
                64,
                $input->nativeReferences,
            )->assertProvenance($input, 'normalized_derivative_v1');
            $this->rawCapturedFactCounts[$input->pageNumber] = count($raw->projectSheetAnalysis?->facts ?? []);
        } catch (\Throwable) {
            $this->rawCapturedFactCounts[$input->pageNumber] = 0;
        }
        $canonical = (new RoleVisionResponseCanonicalizer)->canonicalize($payload, $input);

        return VisionAnalysisData::fromProviderArray(
            $canonical->payload,
            'recorded',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'recorded-session-73-v1',
            'measured',
            100,
            50,
            64,
            64,
            $input->nativeReferences,
        );
    }

    private function capturedSession77Observation(VisionDocumentInput $input): VisionAnalysisData
    {
        $fixture = json_decode((string) file_get_contents(
            dirname(__DIR__, 4).'/tests/Fixtures/EstimateGeneration/Vision/session-77-pages-5-9-11.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $payload = $fixture['page_11']['provider_response'];
        foreach ($payload['evidence'] as &$evidence) {
            $evidence['locator'] = [
                'page_id' => $input->pageId,
                'page_number' => $input->pageNumber,
                'processing_unit_id' => $input->processingUnitId,
                'source_version' => $input->sourceVersion,
                'coordinate_space' => 'normalized_derivative_v1',
                'explicit' => true,
            ];
        }
        unset($evidence);

        return VisionAnalysisData::fromProviderArray(
            $payload,
            'recorded',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'recorded-session-77-v1',
            'measured',
            100,
            50,
            64,
            64,
            $input->nativeReferences,
        )->assertProvenance($input, 'normalized_derivative_v1');
    }

    private function arbitration(VisionDocumentInput $input): VisionAnalysisData
    {
        $claims = $input->auxiliaryMetadata['arbitration']['claims'] ?? [];
        $this->arbitrationClaimCounts[$input->pageNumber] = is_array($claims) ? count($claims) : 0;
        $groups = [];
        foreach (is_array($claims) ? $claims : [] as $claim) {
            $identity = implode('|', [
                (string) ($claim['entity_key'] ?? ''),
                (string) ($claim['fact_type'] ?? ''),
                json_encode($claim['value'] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                (string) ($claim['unit'] ?? ''),
            ]);
            $groups[$identity][] = $claim;
        }
        $counts = [];
        foreach ($groups as $identity => $group) {
            $counts[$identity] = count($group);
        }
        arsort($counts, SORT_NUMERIC);
        $this->arbitrationValueCounts[$input->pageNumber] = $counts;
        $decisions = [];
        foreach ($groups as $group) {
            $representative = $group[0];
            $supportingClaimIds = array_values(array_map(
                static fn (array $claim): string => (string) $claim['id'],
                $group,
            ));
            $evidenceRefs = array_values(array_unique(array_map(
                static fn (array $claim): string => (string) $claim['evidence_ref'],
                $group,
            )));
            $hasExplicitEvidence = in_array(true, array_map(
                static fn (array $claim): bool => ($claim['explicit_evidence'] ?? false) === true,
                $group,
            ), true);
            $majority = count($group) >= 2;
            $isMaterialConflict = in_array($input->pageNumber, [5, 6], true)
                && ($representative['fact_type'] ?? null) === 'material';
            if ($input->pageNumber === 6 && ! $majority) {
                $decisions[] = [
                    'claim_id' => (string) $representative['id'],
                    'status' => 'minority',
                    'supporting_claim_ids' => [(string) $representative['id']],
                    'evidence_refs' => [(string) $representative['evidence_ref']],
                    'reason_code' => 'minority_without_explicit_source_evidence',
                ];

                continue;
            }
            $decisions[] = [
                'claim_id' => (string) $representative['id'],
                'status' => $isMaterialConflict && ! $majority
                    ? 'unresolved'
                    : 'accepted',
                'supporting_claim_ids' => $supportingClaimIds,
                'evidence_refs' => $evidenceRefs,
                'reason_code' => match (true) {
                    $majority && $hasExplicitEvidence => 'majority_with_explicit_source_evidence',
                    $majority => 'independent_observer_majority',
                    $hasExplicitEvidence => 'explicit_source_observation',
                    $isMaterialConflict => 'preserved_source_conflict_for_project_review',
                    default => 'bounded_observer_observation',
                },
            ];
        }

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
