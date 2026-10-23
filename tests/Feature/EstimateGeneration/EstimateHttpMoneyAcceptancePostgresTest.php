<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativePinClock;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningSourceTrustPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EquipmentCountKnowledgeValidationService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Enums\AuthSessionStatus;
use App\Models\Estimate;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Credits\AICreditService;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class EstimateHttpMoneyAcceptancePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    private const GENERIC_WORK_KEY = 'custom.exposition_setup';

    private const SECONDARY_WORK_KEY = 'custom.exposition_positioning';

    private array $wireHistory = [];

    private array $composerProjects = [];

    private ?\Throwable $recordingFailure = null;

    private ?MockHandler $wireHandler = null;

    #[DataProvider('regionalPriceCases')]
    public function test_http_uploaded_native_quantity_reaches_fresh_resource_money_export_apply_and_ordinary_recalculation(bool $withMatchingPrice): void
    {
        [$rights, $project, $session, $catalog, $headers, $count, $revision] = $this->confirmedSession();
        $url = $this->url($project, $session);
        $original = get_object_vars($count);
        $oldArtifact = $session->fresh()->draft_payload['artifact_hash'];
        $oldPrice = (array) DB::table('estimate_resource_prices')->where('id', $catalog['price_id'])->sole();
        $next = $this->anotherRegionalPriceVersion($catalog, $withMatchingPrice);
        self::assertSame(0, DB::table('estimate_resource_prices')->where('resource_code', $catalog['resource_code'])
            ->whereNull('regional_price_version_id')->count());
        $selection = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'estimate_regional_price_version_id' => $next['version_id'], 'conditions' => ['as_of_date' => '2026-10-01',
                'vat_mode' => 'excluded', 'vat_rate' => '22', 'delivery' => 'outside_scope',
                'basis' => 'Синтетическая версия цены того же труда; документ и количество не изменены.']];
        $selected = $this->postJson($url.'/evaluation/pricing-source', $selection, $headers)->assertOk()->json('data');
        self::assertSame('0', $selected['cost_units']);
        self::assertSame(CanonicalPipelineJson::encode($selected), CanonicalPipelineJson::encode(
            $this->postJson($url.'/evaluation/pricing-source', $selection, $headers)->assertOk()->json('data')));
        self::assertSame('ready_to_apply', $session->fresh()->status->value);
        self::assertSame($oldArtifact, $session->fresh()->draft_payload['artifact_hash']);
        $this->assertPriorArtifactRejected($project, $session, $headers, $revision, $oldArtifact);
        $revision = $this->postJson($url.'/evaluation/recalculate', ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version], $headers)->assertOk()->json('data');
        $row = $this->installation($session->fresh());
        self::assertSame('3', $row['quantity']);
        self::assertSame($count->id, $row['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        self::assertSame($oldPrice, (array) DB::table('estimate_resource_prices')->where('id', $catalog['price_id'])->sole());
        $current = app(ProjectModelRepository::class)->factsByIds((int) $session->organization_id, (int) $project->id, (int) $session->id, [$count->id]);
        self::assertSame($original, get_object_vars($current[0]));
        if (! $withMatchingPrice) {
            self::assertFalse($revision['result']['can_confirm_for_apply']);
            self::assertNull($row['total_cost']);
            self::assertNull($revision['result']['scenarios'][0]['positions'][0]['price_snapshot']);
            $before = $session->fresh()->getAttributes();
            $this->postJson($url.'/evaluation/confirm-scenario', ['request_id' => (string) Str::uuid(),
                'state_version' => $session->fresh()->state_version, 'revision_id' => $revision['revision_id'],
                'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true], $headers)
                ->assertUnprocessable()->assertJsonValidationErrors('scenario');
            $this->getJson($url.'/export?format=json', $headers)->assertUnprocessable();
            $this->postJson($url.'/apply', ['state_version' => $session->fresh()->state_version,
                'artifact_hash' => $session->fresh()->draft_payload['artifact_hash'], 'idempotency_key' => (string) Str::uuid()], $headers)->assertUnprocessable();
            self::assertSame($before, $session->fresh()->getAttributes());
            self::assertNull($session->fresh()->applied_estimate_id);
            self::assertSame(0, Estimate::query()->where('project_id', $project->id)->count());
            $this->assertNoExtraModelWork($session);

            return;
        }
        self::assertTrue($revision['result']['can_confirm_for_apply']);
        self::assertSame('60.00', $revision['result']['scenarios'][0]['known_subtotals']['RUB']);
        self::assertSame('73.20', $revision['result']['scenarios'][0]['gross_subtotals']['RUB']);
        self::assertSame('0.3', $row['labor'][0]['quantity']);
        self::assertSame('200', (string) BigDecimal::of($row['labor'][0]['unit_price'])->strippedOfTrailingZeros());
        self::assertSame($next['price_id'], $row['labor'][0]['normative_ref']['price_id']);
        self::assertSame($catalog['dataset_key'], $row['normative_match']['dataset_version']['version_key']);
        self::assertSame($next['version_id'], $session->fresh()->draft_payload['regional_context']['estimate_regional_price_version_id']);
        $this->confirm($project, $session, $headers, $revision);
        $export = $this->getJson($url.'/export?format=json', $headers)->assertOk();
        $exportDraft = json_decode($export->streamedContent(), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame($next['version_id'], $exportDraft['regional_context']['estimate_regional_price_version_id']);
        $exportRows = array_merge(...array_map(static fn (array $estimate): array => array_merge(...array_column($estimate['sections'], 'work_items')),
            $exportDraft['local_estimates']));
        self::assertCount(1, $exportRows);
        self::assertSame('3', $exportRows[0]['quantity']);
        self::assertSame('0.3', $exportRows[0]['labor'][0]['quantity']);
        self::assertSame('60.00', $exportRows[0]['total_cost']);
        self::assertSame($next['price_id'], $exportRows[0]['labor'][0]['normative_ref']['price_id']);
        self::assertSame($count->id, $exportRows[0]['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        $apply = ['state_version' => $session->fresh()->state_version, 'artifact_hash' => $session->fresh()->draft_payload['artifact_hash'],
            'idempotency_key' => (string) Str::uuid(), 'name' => 'Synthetic HTTP native resource acceptance', 'type' => 'local', 'estimate_date' => '2026-10-01'];
        $applied = $this->postJson($url.'/apply', $apply, $headers)->assertOk()->json('data');
        self::assertTrue($applied['created']);
        self::assertSame(['estimate_id' => $applied['estimate_id'], 'created' => false],
            $this->postJson($url.'/apply', $apply, $headers)->assertOk()->json('data'));
        $estimate = Estimate::query()->findOrFail($session->fresh()->applied_estimate_id);
        self::assertSame('60.00', $estimate->total_amount);
        self::assertSame('73.20', $estimate->total_amount_with_vat);
        $parent = $estimate->items()->whereNull('parent_work_id')->sole();
        $child = $estimate->items()->whereNotNull('parent_work_id')->sole();
        $resource = $parent->resources()->sole();
        $identities = [$parent->id, $child->id, $resource->id];
        for ($pass = 0; $pass < 2; $pass++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame('60.00', $estimate->total_amount);
            self::assertSame('73.20', $estimate->total_amount_with_vat);
            self::assertSame(2, $estimate->items()->count());
            self::assertSame($identities, [$parent->fresh()->id, $child->fresh()->id, $resource->fresh()->id]);
            self::assertSame('3', (string) BigDecimal::of($parent->fresh()->quantity)->strippedOfTrailingZeros());
            self::assertSame('0.3', (string) BigDecimal::of($parent->fresh()->labor_hours)->strippedOfTrailingZeros());
            self::assertSame('0', (string) BigDecimal::of($parent->fresh()->machinery_hours)->strippedOfTrailingZeros());
            self::assertSame('13.20', $parent->fresh()->metadata['generated_source_money']['money']['vat_amount']);
            self::assertSame('22', $parent->fresh()->metadata['generated_source_money']['money']['vat_rate']);
            self::assertSame('0.3', (string) BigDecimal::of($resource->fresh()->total_quantity)->strippedOfTrailingZeros());
            self::assertSame('0.1', (string) BigDecimal::of($resource->fresh()->quantity_per_unit)->strippedOfTrailingZeros());
            self::assertSame('200', (string) BigDecimal::of($resource->fresh()->unit_price)->strippedOfTrailingZeros());
            self::assertSame('60.00', $resource->fresh()->total_amount);
            self::assertSame($catalog['norm_id'], $child->fresh()->metadata['normative_ref']['norm_id']);
            self::assertSame($catalog['resource_id'], $child->fresh()->metadata['normative_ref']['norm_resource_id']);
            self::assertSame($next['price_id'], $child->fresh()->metadata['normative_ref']['price_id']);
            self::assertSame($count->id, $parent->fresh()->metadata['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        }
        $current = app(ProjectModelRepository::class)->factsByIds((int) $session->organization_id, (int) $project->id, (int) $session->id, [$count->id]);
        self::assertSame($original, get_object_vars($current[0]));
        $this->assertNoExtraModelWork($session);
        $this->getJson($url.'/evaluation', $this->headers($rights->foreignOwner))->assertForbidden()
            ->assertJsonPath('message', trans_message('project.access_denied'));
    }

    public static function regionalPriceCases(): array
    {
        return ['current V2 resource price' => [true], 'missing V2 without eligible base price' => [false]];
    }

    public function test_public_quantity_decision_rejects_the_prior_confirmed_artifact_without_rewriting_native_source(): void
    {
        [, $project, $session, , $headers, $count, $revision] = $this->confirmedSession();
        $original = get_object_vars($count);
        $artifact = $session->fresh()->draft_payload['artifact_hash'];
        $this->postJson($this->url($project, $session).'/evaluation/quantity', ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'work_item_key' => $this->installation($session->fresh())['key'],
            'value' => '4', 'unit' => 'count', 'reason' => 'Пользователь изменил объём работы; исходная ведомость по-прежнему содержит три единицы.'], $headers)->assertOk();
        self::assertSame('ready_to_apply', $session->fresh()->status->value);
        self::assertSame($artifact, $session->fresh()->draft_payload['artifact_hash']);
        $this->assertPriorArtifactRejected($project, $session, $headers, $revision, $artifact);
        $current = app(ProjectModelRepository::class)->factsByIds((int) $session->organization_id, (int) $project->id, (int) $session->id, [$count->id]);
        self::assertSame($original, get_object_vars($current[0]));
        $this->assertNoExtraModelWork($session);
    }

    public function test_http_manual_work_quantity_survives_a_new_approved_generation_without_replacing_native_source(): void
    {
        [$rights, $project, $session, , $headers, $native] = $this->generatedSession(false);
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $original = get_object_vars($native);
        $item = $this->installation($session);
        $url = $this->url($project, $session);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'work_item_key' => $item['key'], 'value' => '4', 'unit' => 'count',
            'reason' => 'Для этой работы пользователь явно задал четыре единицы; исходная ведомость содержит три.'];
        $quantity = $this->postJson($url.'/evaluation/quantity', $input, $headers)->assertOk()->json('data');
        self::assertSame('0', $quantity['cost_units']);
        self::assertSame(CanonicalPipelineJson::encode($quantity), CanonicalPipelineJson::encode(
            $this->postJson($url.'/evaluation/quantity', $input, $headers)->assertOk()->json('data')));
        $facts = array_column(app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts, null, 'id');
        $entered = $facts[$quantity['source_fact_id']];
        self::assertSame(['4', 'count', $native->entityId, 'user_input', []],
            [$entered->value, $entered->unit, $entered->entityId, $entered->origin, $entered->nativeNumericProofs]);
        self::assertNotSame($native->id, $entered->id);
        self::assertSame($original, get_object_vars($facts[$native->id]));
        self::assertCount(2, $this->wireHistory);
        $this->transport($session, [...$quantity, 'work_item_key' => $item['key'], 'target_entity_id' => $native->entityId]);
        $operation = (string) Str::uuid();
        $quote = $this->postJson($url.'/operations/quote', ['request_id' => $operation,
            'state_version' => $session->fresh()->state_version, 'operation_kind' => 'generation',
            'operation_parameters' => ['max_work_items' => 4]], $headers)->assertOk()->json('data');
        $queuedBefore = Queue::pushed(GenerateEstimateDraftJob::class)->count();
        $this->postJson($url.'/generate', ['request_id' => $operation, 'quote_id' => $quote['quote_id'],
            'state_version' => $session->fresh()->state_version, 'generation_mode' => 'ai_assisted'], $headers)->assertSuccessful();
        $jobs = Queue::pushed(GenerateEstimateDraftJob::class)->slice($queuedBefore);
        self::assertCount(1, $jobs);
        $job = $jobs->sole();
        foreach (ProcessingStage::cases() as $stage) {
            try {
                $job->handle(app(RunEstimateGenerationDraft::class));
            } catch (\Throwable $error) {
                throw $this->recordingFailure ?? $error;
            }
        }
        self::assertSame(9, DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('generation_attempt_id', $operation)->where('status', 'completed')->count());
        $row = $this->installation($session->fresh());
        self::assertSame($item['key'], $row['key']);
        self::assertSame($native->entityId, $row['metadata']['scope_entity_id']);
        self::assertSame(['4', 'count'], [$row['quantity'], $row['unit']], json_encode(
            array_intersect_key($row, array_flip(['key', 'quantity', 'unit', 'metadata', 'quantity_evidence', 'composition_intent'])),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $operand = $row['quantity_evidence']['formula_inputs']['operands'][0];
        self::assertSame($quantity['source_fact_id'], $operand['fact_id']);
        self::assertSame($quantity['fact_id'], $operand['quantity_binding']['fact_id']);
        self::assertSame($quantity['decision_id'], $operand['quantity_binding']['decision_id']);
        $facts = array_column(app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts, null, 'id');
        self::assertSame(get_object_vars($entered), get_object_vars($facts[$entered->id]));
        self::assertSame($original, get_object_vars($facts[$native->id]));
        self::assertCount(4, $this->wireHistory);
        $balance = app(AICreditService::class)->balance($rights->organization);
        $receipts = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->orderBy('attempt_id')->get()->toJson();
        $credit = (array) DB::table('estimate_generation_credit_operations')->where('operation_id', $operation)->sole();
        $job->handle(app(RunEstimateGenerationDraft::class));
        self::assertCount(4, $this->wireHistory);
        self::assertSame($balance, app(AICreditService::class)->balance($rights->organization));
        self::assertSame($receipts, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->orderBy('attempt_id')->get()->toJson());
        self::assertSame($credit, (array) DB::table('estimate_generation_credit_operations')->where('operation_id', $operation)->sole());
        self::assertSame(0, Estimate::query()->where('project_id', $project->id)->count());
        Http::assertNothingSent();
    }

    public function test_http_generic_semantic_installation_covers_only_its_requirement_and_retains_an_assumed_quantity(): void
    {
        self::assertFalse((new WorkItemQuantityMapper)->isKnownWorkKey(self::GENERIC_WORK_KEY));
        [$rights, $project, $session, $catalog, $headers, $native] = $this->generatedSession(false, true, 'installation');
        self::assertNull($native);
        self::assertSame([], $catalog);
        self::assertSame(0, $session->documents()->count());
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $descriptions = array_values(array_filter($snapshot->facts, static fn ($fact): bool =>
            $fact->type === 'evaluation_answer' && ($fact->value['kind'] ?? null) === 'initial_description'));
        self::assertCount(1, $descriptions);
        $description = $descriptions[0];
        self::assertSame('user_input', $description->origin);
        self::assertFalse($description->value['numeric_parameters_confirmed']);
        self::assertSame($session->input_payload['description'], $description->value['description']);
        $originalFact = get_object_vars($description);
        $originalEntity = get_object_vars(array_column($snapshot->entities, null, 'id')[$description->entityId]);
        $originalEvidence = array_map(get_object_vars(...), array_values(array_filter($snapshot->evidence,
            static fn ($evidence): bool => in_array($evidence->id, $description->evidenceIds, true))));
        $originalDecisions = array_map(get_object_vars(...), $models->decisionsForSelectedFacts(...[...$scope, [$description->id]]));
        self::assertNotEmpty($originalEvidence);
        self::assertNotEmpty($originalDecisions);
        foreach ($snapshot->facts as $fact) {
            self::assertSame([], $fact->nativeNumericProofs);
            self::assertNotSame('equipment_count', $fact->type);
        }
        $item = $this->installation($session, self::GENERIC_WORK_KEY);
        self::assertSame($description->entityId, $item['metadata']['scope_entity_id']);
        self::assertSame([null, null], [$item['quantity'], $item['unit']]);
        self::assertContains('unit_requires_confirmation', $item['validation_flags']);
        self::assertSame('count', $item['composition_intent']['unit']);
        self::assertNull($item['composition_intent']['derived_quantity_id']);
        self::assertSame([$description->id], $item['composition_intent']['source_fact_ids']);
        $url = $this->url($project, $session);
        $evaluation = $this->getJson($url.'/evaluation', $headers)->assertOk()->json('data.result');
        $missing = $evaluation['missing_requirements'];
        self::assertCount(4, $missing);
        self::assertEqualsCanonicalizing(['equipment_specification', 'equipment_purchase', 'delivery', 'commissioning'],
            array_column($missing, 'requirement'));
        self::assertSame([$description->entityId], array_values(array_unique(array_column($missing, 'entity_id'))));
        self::assertSame(['equipment'], array_values(array_unique(array_column($missing, 'section'))));
        self::assertSame('installation', $item['metadata']['requirement_key']);
        self::assertSame(self::GENERIC_WORK_KEY, $item['composition_intent']['work_key']);
        self::assertFalse($evaluation['can_confirm_for_apply']);
        self::assertNull($item['unit_price']);
        self::assertNull($item['total_cost']);
        self::assertFalse(isset($item['normative_match']['norm_id']));
        $firstCalls = count($this->wireHistory);
        self::assertSame(2, $firstCalls);
        self::assertSame($firstCalls, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $manualBalance = app(AICreditService::class)->balance($rights->organization);
        $manualUsage = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->orderBy('attempt_id')->get()->toJson();
        $manualOperations = DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count();
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'work_item_key' => $item['key'], 'value' => '3', 'unit' => 'count',
            'reason' => 'Пользователь принял предварительное количество и единицу этой работы; документального измерения нет.'];
        $quantity = $this->postJson($url.'/evaluation/quantity', $input, $headers)->assertOk()->json('data');
        self::assertSame('0', $quantity['cost_units']);
        $beforeReplay = $session->fresh()->getAttributes();
        $ledgerCounts = [];
        foreach (['project_model_assertions', 'project_model_corrections', 'evidence'] as $table) {
            $ledgerCounts[$table] = DB::table('estimate_generation_'.$table)->where('session_id', $session->id)->count();
        }
        self::assertSame(CanonicalPipelineJson::encode($quantity), CanonicalPipelineJson::encode(
            $this->postJson($url.'/evaluation/quantity', $input, $headers)->assertOk()->json('data')));
        self::assertSame($beforeReplay, $session->fresh()->getAttributes());
        foreach ($ledgerCounts as $table => $count) {
            self::assertSame($count, DB::table('estimate_generation_'.$table)->where('session_id', $session->id)->count());
        }
        self::assertCount($firstCalls, $this->wireHistory);
        self::assertSame($manualBalance, app(AICreditService::class)->balance($rights->organization));
        self::assertSame($manualUsage, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->orderBy('attempt_id')->get()->toJson());
        self::assertSame($manualOperations, DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count());
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $entered = array_column($snapshot->facts, null, 'id')[$quantity['source_fact_id']];
        self::assertSame(['3', 'count', $description->entityId, 'user_assumption', []],
            [$entered->value, $entered->unit, $entered->entityId, $entered->origin, $entered->nativeNumericProofs]);
        $recalculated = $this->postJson($url.'/evaluation/recalculate', ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version], $headers)->assertOk()->json('data');
        self::assertSame('0', $recalculated['cost_units']);
        self::assertCount(4, $recalculated['result']['missing_requirements']);
        $position = $recalculated['result']['scenarios'][0]['positions'][0];
        self::assertSame([$item['key'], $description->entityId, '3', 'count', 'assumed', 'direct_quantity'],
            [$position['key'], $position['entity_id'], $position['quantity'], $position['unit'],
                $position['quantity_status'], $position['quantity_formula']]);
        self::assertFalse($recalculated['result']['can_confirm_for_apply']);
        self::assertCount($firstCalls, $this->wireHistory);
        self::assertSame($manualBalance, app(AICreditService::class)->balance($rights->organization));
        self::assertSame($manualUsage, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->orderBy('attempt_id')->get()->toJson());
        self::assertSame($manualOperations, DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count());
        $this->transport($session, [...$quantity, 'work_item_key' => $item['key'], 'target_entity_id' => $description->entityId], true, 'installation');
        $operation = (string) Str::uuid();
        $quote = $this->postJson($url.'/operations/quote', ['request_id' => $operation,
            'state_version' => $session->fresh()->state_version, 'operation_kind' => 'generation',
            'operation_parameters' => ['max_work_items' => 4]], $headers)->assertOk()->json('data');
        $queuedBefore = Queue::pushed(GenerateEstimateDraftJob::class)->count();
        $this->postJson($url.'/generate', ['request_id' => $operation, 'quote_id' => $quote['quote_id'],
            'state_version' => $session->fresh()->state_version, 'generation_mode' => 'ai_assisted'], $headers)->assertSuccessful();
        $jobs = Queue::pushed(GenerateEstimateDraftJob::class)->slice($queuedBefore);
        self::assertCount(1, $jobs);
        $job = $jobs->sole();
        foreach (ProcessingStage::cases() as $stage) {
            try {
                $job->handle(app(RunEstimateGenerationDraft::class));
            } catch (\Throwable $error) {
                throw $this->recordingFailure ?? $error;
            }
        }
        self::assertSame(9, DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('generation_attempt_id', $operation)->where('status', 'completed')->count());
        $row = $this->installation($session->fresh(), self::GENERIC_WORK_KEY);
        self::assertSame([$item['key'], $description->entityId, '3', 'count', 'assumed'],
            [$row['key'], $row['metadata']['scope_entity_id'], $row['quantity'], $row['unit'], $row['quantity_status']],
            json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame('estimated', $row['quantity_basis_details']['source']);
        self::assertSame([$entered->id], $row['quantity_basis_details']['assumptions']);
        $operand = $row['quantity_basis_details']['formula_inputs']['operands'][0];
        self::assertSame([$entered->id, $quantity['fact_id'], $quantity['decision_id'], $item['key']],
            [$operand['fact_id'], $operand['quantity_binding']['fact_id'], $operand['quantity_binding']['decision_id'], $operand['quantity_binding']['work_item_key']]);
        self::assertNull($row['unit_price']);
        self::assertNull($row['total_cost']);
        self::assertSame('price_unknown', $row['pricing_status']);
        self::assertFalse(isset($row['normative_match']['norm_id']));
        self::assertSame('installation', $row['metadata']['requirement_key']);
        $freshEvaluation = $this->getJson($url.'/evaluation', $headers)->assertOk()->json('data.result');
        self::assertCount(4, $freshEvaluation['missing_requirements']);
        self::assertEqualsCanonicalizing(['equipment_specification', 'equipment_purchase', 'delivery', 'commissioning'],
            array_column($freshEvaluation['missing_requirements'], 'requirement'));
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $facts = array_column($snapshot->facts, null, 'id');
        self::assertSame($originalFact, get_object_vars($facts[$description->id]));
        self::assertSame(get_object_vars($entered), get_object_vars($facts[$entered->id]));
        self::assertSame($originalEntity, get_object_vars(array_column($snapshot->entities, null, 'id')[$description->entityId]));
        self::assertSame($originalEvidence, array_map(get_object_vars(...), array_values(array_filter($snapshot->evidence,
            static fn ($evidence): bool => in_array($evidence->id, $description->evidenceIds, true)))));
        self::assertSame($originalDecisions, array_map(get_object_vars(...), $models->decisionsForSelectedFacts(...[...$scope, [$description->id]])));
        $calls = count($this->wireHistory);
        self::assertSame(4, $calls);
        self::assertSame($calls, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $balance = app(AICreditService::class)->balance($rights->organization);
        $receipts = DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->orderBy('attempt_id')->get()->toJson();
        $credit = (array) DB::table('estimate_generation_credit_operations')->where('operation_id', $operation)->sole();
        foreach ($ledgerCounts as $table => $count) {
            $ledgerCounts[$table] = DB::table('estimate_generation_'.$table)->where('session_id', $session->id)->count();
        }
        $job->handle(app(RunEstimateGenerationDraft::class));
        self::assertCount($calls, $this->wireHistory);
        self::assertSame($balance, app(AICreditService::class)->balance($rights->organization));
        self::assertSame($receipts, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->orderBy('attempt_id')->get()->toJson());
        self::assertSame($credit, (array) DB::table('estimate_generation_credit_operations')->where('operation_id', $operation)->sole());
        foreach ($ledgerCounts as $table => $count) {
            self::assertSame($count, DB::table('estimate_generation_'.$table)->where('session_id', $session->id)->count());
        }
        self::assertSame(0, $session->documents()->count());
        self::assertSame(0, Estimate::query()->where('project_id', $project->id)->count());
        Http::assertNothingSent();
    }

    public function test_http_current_work_technology_question_preserves_qualitative_trust_and_free_answer_history(): void
    {
        $clarification = ['question' => 'Как экспозиционное оборудование закрепляется и какой документ задаёт способ крепления?',
            'reason' => 'Исходное описание не содержит способа крепления и условий его применимости.'];
        [$rights, $project, $session, , $headers] = $this->generatedSession(false, true, 'installation', $clarification);
        $url = $this->url($project, $session);
        $questions = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
        $workQuestions = array_values(array_filter($questions, static fn (array $question): bool =>
            ($question['source_locator']['kind'] ?? null) === 'composer_work_technology'));
        self::assertCount(1, $workQuestions, 'The same-pass work intent has no public technology clarification.');
        $question = $workQuestions[0];
        self::assertStringStartsWith('composer_work_technology_', $question['code']);
        self::assertSame('unanswered', $question['status']);
        self::assertNull($question['answer']);
        self::assertSame(['answer'], $question['actions']);
        self::assertSame(['other', 'leave_unresolved'], array_column($question['choices'], 'value'));
        self::assertSame('estimate_review_required', $session->fresh()->status->value);
        $item = $this->installation($session->fresh(), self::GENERIC_WORK_KEY);
        self::assertSame([null, null, null, null], [$item['quantity'], $item['unit'], $item['unit_price'], $item['total_cost']]);
        $evaluation = $this->getJson($url.'/evaluation', $headers)->assertOk()->json('data.result');
        self::assertCount(4, $evaluation['missing_requirements']);
        self::assertFalse($evaluation['can_confirm_for_apply']);
        $this->getJson($url.'/questions', $this->headers($rights->foreignOwner))->assertForbidden();
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $sourceFacts = array_map(get_object_vars(...), $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts);
        $money = $this->modelMoneySnapshot($session);
        $input = ['response' => 'other', 'other' => 'Пользователь предполагает крепление по инструкции изготовителя; документа с условиями применимости пока нет.',
            'document_ids' => [], 'idempotency_key' => (string) Str::uuid(),
            'expected_source_version' => $question['source_version'], 'answer_fingerprint' => $question['answer_fingerprint']];
        $answerUrl = $url.'/questions/'.$question['code'].'/answer';
        $this->postJson($answerUrl, $input, $this->headers($rights->foreignOwner))->assertForbidden();
        $viewer = $rights->addMember(['ai-estimates' => ['estimate_generation.view']], ['admin.access']);
        $viewer->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $this->postJson($answerUrl, $input, $this->headers($viewer))->assertForbidden();
        $otherProject = Project::factory()->for($rights->organization)->create(['is_archived' => false]);
        $this->postJson($this->url($otherProject, $session).'/questions/'.$question['code'].'/answer', $input, $headers)->assertForbidden();
        $this->postJson($answerUrl, [...$input, 'answer_fingerprint' => str_repeat('0', 64)], $headers)->assertConflict();
        $this->postJson($answerUrl, [...$input, 'document_ids' => [PHP_INT_MAX]], $headers)
            ->assertConflict()->assertJsonPath('code', 'question_stale');
        $this->postJson($answerUrl, [...$input, 'target_entity_id' => $item['metadata']['scope_entity_id']], $headers)->assertUnprocessable();
        self::assertSame($money, $this->modelMoneySnapshot($session));
        $receipt = $this->postJson($answerUrl, $input, $headers)->assertOk()->json('data');
        $readback = $this->workTechnologyQuestion($project, $session, $headers);
        self::assertSame($question['code'], $readback['code']);
        self::assertSame('answered', $readback['status']);
        self::assertSame(['reanswer', 'revoke'], $readback['actions']);
        self::assertSame(['other', $input['other'], [], 'user_assumption', 'unresolved', false],
            [$readback['answer']['response'], $readback['answer']['other'], $readback['answer']['document_ids'],
                $readback['answer']['trust'], $readback['answer']['engineering_applicability'], $readback['answer']['numeric_parameters_confirmed']]);
        self::assertSame($receipt['decision_id'], $readback['answer']['decision_id']);
        $receiptQuery = array_intersect_key($input, array_flip(['idempotency_key', 'expected_source_version', 'answer_fingerprint', 'document_ids']));
        $receiptUrl = $url.'/questions/'.$question['code'].'/answer-receipt?';
        $saved = $this->getJson($receiptUrl.http_build_query($receiptQuery), $headers)->assertOk()->json('data');
        self::assertSame(['saved', $receipt['decision_id'], '0'], [$saved['status'], $saved['decision_id'], $saved['cost_units']]);
        $this->getJson($receiptUrl.http_build_query([...$receiptQuery, 'answer_fingerprint' => str_repeat('0', 64)]), $headers)
            ->assertOk()->assertJsonPath('data.status', 'unavailable');
        $this->getJson($receiptUrl.http_build_query($receiptQuery), $this->headers($rights->owner))
            ->assertOk()->assertJsonPath('data.status', 'unavailable');
        $this->getJson($receiptUrl.http_build_query($receiptQuery), $this->headers($rights->foreignOwner))->assertForbidden();
        $history = [$readback['answer']['fact_id'] => $input['other']];
        $firstDecision = $models->decisions(...[...$scope, [$receipt['decision_id']]]);
        self::assertCount(1, $firstDecision);
        self::assertSame(['user', (string) $rights->member->id, $readback['answer']['fact_id']],
            [$firstDecision[0]->actorType, $firstDecision[0]->actorId, $firstDecision[0]->selectedFactId]);
        $beforeReplay = $session->fresh()->getAttributes();
        $ledger = $this->answerLedgerCounts($session);
        self::assertSame(CanonicalPipelineJson::encode($receipt), CanonicalPipelineJson::encode(
            $this->postJson($answerUrl, $input, $headers)->assertOk()->json('data')));
        self::assertSame($beforeReplay, $session->fresh()->getAttributes());
        self::assertSame($ledger, $this->answerLedgerCounts($session));
        $this->postJson($answerUrl, [...$input, 'other' => 'Другой текст с тем же идентификатором запроса.'], $headers)->assertUnprocessable();
        foreach ([['other', 'Способ крепления уточнён пользователем; независимого инженерного подтверждения по-прежнему нет.', 'answered', 'user_assumption'],
            ['revoke', null, 'revoked', 'no_active_claim'], ['leave_unresolved', null, 'left_unresolved', 'no_active_claim'],
            ['other', 'Повторное предварительное решение пользователя; размеры и норматив отдельно не подтверждены.', 'answered', 'user_assumption']] as [$response, $other, $status, $trust]) {
            $current = $this->workTechnologyQuestion($project, $session, $headers);
            $next = ['response' => $response, 'idempotency_key' => (string) Str::uuid(),
                'expected_source_version' => $current['source_version'], 'answer_fingerprint' => $current['answer_fingerprint'],
                ...($other === null ? [] : ['other' => $other, 'document_ids' => []])];
            $nextReceipt = $this->postJson($answerUrl, $next, $headers)->assertOk()->json('data');
            $readback = $this->workTechnologyQuestion($project, $session, $headers);
            self::assertSame([$question['code'], $status, $trust, 'unresolved', false],
                [$readback['code'], $readback['status'], $readback['answer']['trust'],
                    $readback['answer']['engineering_applicability'], $readback['answer']['numeric_parameters_confirmed']]);
            self::assertSame($response === 'other' ? ['reanswer', 'revoke'] : ['reanswer'], $readback['actions']);
            self::assertSame($nextReceipt['decision_id'], $readback['answer']['decision_id']);
            self::assertArrayNotHasKey($readback['answer']['fact_id'], $history);
            $history[$readback['answer']['fact_id']] = $other;
            $ledger = $this->answerLedgerCounts($session);
            self::assertSame(CanonicalPipelineJson::encode($nextReceipt), CanonicalPipelineJson::encode(
                $this->postJson($answerUrl, $next, $headers)->assertOk()->json('data')));
            self::assertSame($ledger, $this->answerLedgerCounts($session));
            self::assertSame($money, $this->modelMoneySnapshot($session));
        }
        foreach ($models->factsByIds(...[...$scope, array_keys($history)]) as $fact) {
            self::assertSame(['evaluation_answer', 'user_assumption', null, [], 'composer_answer', false, $history[$fact->id]],
                [$fact->type, $fact->origin, $fact->unit, $fact->nativeNumericProofs, $fact->value['kind'],
                    $fact->value['numeric_parameters_confirmed'], $fact->value['other'] ?? null]);
        }
        self::assertCount(count($history), $models->factsByIds(...[...$scope, array_keys($history)]));
        $this->getJson($receiptUrl.http_build_query($receiptQuery), $headers)->assertOk()->assertJsonPath('data.status', 'saved');
        self::assertSame($sourceFacts, array_map(get_object_vars(...), $models->factsByIds(...[...$scope, array_column($sourceFacts, 'id')])));
        $after = $this->installation($session->fresh(), self::GENERIC_WORK_KEY);
        self::assertSame([null, null, null, null], [$after['quantity'], $after['unit'], $after['unit_price'], $after['total_cost']]);
        self::assertFalse(isset($after['normative_match']['norm_id']));
        $currentEvaluation = $this->getJson($url.'/evaluation', $headers)->assertOk()->json('data.result');
        self::assertCount(4, $currentEvaluation['missing_requirements']);
        self::assertFalse($currentEvaluation['can_confirm_for_apply']);
        foreach (EstimateGenerationLearningExample::query()->where('organization_id', $session->organization_id)
            ->where('generation_session_id', $session->id)->get() as $example) {
            self::assertFalse(EstimateGenerationLearningSourceTrustPolicy::isIndexable($example));
        }
        self::assertSame($money, $this->modelMoneySnapshot($session));
        $this->assertNoExtraModelWork($session);
    }

    #[DataProvider('workTechnologyGenerationCases')]
    public function test_http_approved_generation_reuses_current_work_technology_without_promoting_it_or_repeating_paid_work(string $status, bool $replaceWork = false): void
    {
        $clarification = ['question' => 'Как экспозиционное оборудование закрепляется на предусмотренном основании?',
            'reason' => 'Из исходного описания не следует способ крепления.'];
        [, $project, $session, , $headers] = $this->generatedSession(false, true, 'installation', $clarification);
        $url = $this->url($project, $session);
        $question = $this->workTechnologyQuestion($project, $session, $headers);
        $oldItem = $this->installation($session->fresh(), self::GENERIC_WORK_KEY);
        $staleOperation = (string) Str::uuid();
        $staleQuote = $this->postJson($url.'/operations/quote', ['request_id' => $staleOperation,
            'state_version' => $session->fresh()->state_version, 'operation_kind' => 'generation',
            'operation_parameters' => ['max_work_items' => 4]], $headers)->assertOk()->json('data');
        $response = $status === 'left_unresolved' ? 'leave_unresolved' : 'other';
        $answerInput = ['response' => $response, 'idempotency_key' => (string) Str::uuid(),
            'expected_source_version' => $question['source_version'], 'answer_fingerprint' => $question['answer_fingerprint'],
            ...($response === 'other' ? ['other' => 'Пользователь предполагает крепление по инструкции; независимой проверки технологии пока нет.', 'document_ids' => []] : [])];
        $this->postJson($url.'/questions/'.$question['code'].'/answer', $answerInput, $headers)->assertOk();
        $question = $this->workTechnologyQuestion($project, $session, $headers);
        $answerReferences = [$question['answer']['fact_id'], $question['answer']['decision_id']];
        if ($status === 'revoked') {
            $this->postJson($url.'/questions/'.$question['code'].'/answer', ['response' => 'revoke',
                'idempotency_key' => (string) Str::uuid(), 'expected_source_version' => $question['source_version'],
                'answer_fingerprint' => $question['answer_fingerprint']], $headers)->assertOk();
        }
        $question = $this->workTechnologyQuestion($project, $session, $headers);
        $answerReferences = [...$answerReferences, $question['answer']['fact_id'], $question['answer']['decision_id']];
        self::assertSame($status, $question['status']);
        self::assertSame($status === 'answered' ? ['other', $answerInput['other'], []]
            : [$status === 'revoked' ? 'revoke' : 'leave_unresolved', null, []],
            [$question['answer']['response'], $question['answer']['other'], $question['answer']['document_ids']]);
        $beforeStaleGenerate = $this->modelMoneySnapshot($session);
        $this->postJson($url.'/generate', ['request_id' => $staleOperation, 'quote_id' => $staleQuote['quote_id'],
            'state_version' => $session->fresh()->state_version, 'generation_mode' => 'ai_assisted'], $headers)->assertUnprocessable();
        self::assertSame($beforeStaleGenerate, $this->modelMoneySnapshot($session));
        $savedDraft = $session->fresh()->draft_payload;
        $replacement = $replaceWork ? ['work_key' => 'custom.relocated_exposition', 'name' => 'Размещение экспозиционного оборудования в другой зоне'] : null;
        $this->transport($session, descriptionOnly: true, requirementKey: 'installation', workOverride: $replacement);
        $operation = (string) Str::uuid();
        $quote = $this->postJson($url.'/operations/quote', ['request_id' => $operation,
            'state_version' => $session->fresh()->state_version, 'operation_kind' => 'generation',
            'operation_parameters' => ['max_work_items' => 4]], $headers)->assertOk()->json('data');
        self::assertCount(2, $this->wireHistory);
        $frozen = (array) DB::table('estimate_generation_credit_operations')->where('operation_id', $operation)->sole();
        $plan = json_decode($frozen['processing_plan'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', CanonicalPipelineJson::encode($savedDraft)), $plan['known_context']['saved_draft_hash']);
        self::assertSame(3 * $plan['composer_part_limit'], $plan['roles']['estimate_composition']['max_calls']);
        self::assertSame(array_sum(array_column($plan['roles'], 'max_calls')), $plan['maximum_calls']);
        $queuedBefore = Queue::pushed(GenerateEstimateDraftJob::class)->count();
        $this->postJson($url.'/generate', ['request_id' => $operation, 'quote_id' => $quote['quote_id'],
            'state_version' => $session->fresh()->state_version, 'generation_mode' => 'ai_assisted'], $headers)->assertSuccessful();
        self::assertSame('generating', $session->fresh()->status->value);
        self::assertSame($savedDraft, $session->fresh()->draft_payload);
        $during = $this->workTechnologyQuestion($project, $session, $headers);
        self::assertSame([], $during['actions']);
        $this->postJson($url.'/questions/'.$question['code'].'/answer', ['response' => 'other',
            'other' => 'Новая запись во время генерации запрещена.', 'idempotency_key' => (string) Str::uuid(),
            'expected_source_version' => $during['source_version'], 'answer_fingerprint' => $during['answer_fingerprint']], $headers)->assertConflict();
        $jobs = Queue::pushed(GenerateEstimateDraftJob::class)->slice($queuedBefore);
        self::assertCount(1, $jobs);
        $job = $jobs->sole();
        foreach (ProcessingStage::cases() as $stage) {
            try {
                $job->handle(app(RunEstimateGenerationDraft::class));
            } catch (\Throwable $error) {
                throw $this->recordingFailure ?? $error;
            }
        }
        self::assertSame(9, DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('generation_attempt_id', $operation)->where('status', 'completed')->count());
        self::assertCount(2, $this->composerProjects);
        $projectInput = $this->composerProjects[1];
        self::assertCount(1, $projectInput['work_technology_context']);
        $entry = $projectInput['work_technology_context'][0];
        self::assertSame($status, $entry['status']);
        self::assertSame($question['answer']['fact_id'], $entry['answer']['fact_id']);
        self::assertSame($question['answer']['decision_id'], $entry['answer']['decision_id']);
        self::assertSame([$question['answer']['response'], $question['answer']['other'], $question['answer']['document_ids']],
            [$entry['answer']['response'], $entry['answer']['other'], $entry['answer']['document_ids']]);
        if ($status !== 'answered') {
            self::assertSame([], $entry['documents']);
        }
        self::assertSame([$status === 'answered' ? 'user_assumption' : 'no_active_claim', 'unresolved', false],
            [$entry['answer']['trust'], $entry['answer']['engineering_applicability'], $entry['answer']['numeric_parameters_confirmed']]);
        self::assertNotContains($entry['answer']['fact_id'], array_column($projectInput['facts'], 'id'));
        $row = $this->installation($session->fresh(), $replacement['work_key'] ?? self::GENERIC_WORK_KEY);
        self::assertNotSame($oldItem['composition_intent']['candidate_id'], $row['composition_intent']['candidate_id']);
        self::assertSame([$oldItem['metadata']['scope_entity_id'], null, null, null, null],
            [$row['metadata']['scope_entity_id'], $row['quantity'], $row['unit'], $row['unit_price'], $row['total_cost']]);
        self::assertFalse(isset($row['normative_match']['norm_id']));
        if ($replaceWork) {
            self::assertNotSame($oldItem['key'], $row['key']);
            self::assertFalse(isset($row['metadata']['work_technology_context']));
            $items = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
            self::assertSame([], array_values(array_filter($items, static fn (array $item): bool =>
                ($item['code'] ?? null) === $question['code'])));
            $this->postJson($url.'/questions/'.$question['code'].'/answer', [...$answerInput,
                'idempotency_key' => (string) Str::uuid()], $headers)->assertConflict();
        } else {
            self::assertSame($oldItem['key'], $row['key']);
            self::assertSame($entry['context']['binding_hash'], $row['metadata']['work_technology_context']['binding_hash']);
            $current = $this->workTechnologyQuestion($project, $session, $headers);
            self::assertSame([$question['code'], $status, $question['answer']['fact_id']],
                [$current['code'], $current['status'], $current['answer']['fact_id']]);
        }
        self::assertSame(4, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertCount(4, $this->wireHistory);
        $this->getJson($url.'/operations/'.$operation, $headers)->assertOk()
            ->assertJsonPath('data.operation_id', $operation)->assertJsonPath('data.status', 'settled');
        self::assertSame($frozen['processing_plan'], DB::table('estimate_generation_credit_operations')->where('operation_id', $operation)->value('processing_plan'));
        $evaluation = $this->getJson($url.'/evaluation', $headers)->assertOk()->json('data.result');
        self::assertCount(4, $evaluation['missing_requirements']);
        self::assertFalse($evaluation['can_confirm_for_apply']);
        foreach (EstimateGenerationLearningExample::query()->where('organization_id', $session->organization_id)
            ->where('project_id', $project->id)->where('generation_session_id', $session->id)->get() as $example) {
            $references = [];
            $payload = ['context' => $example->context_payload ?? [], 'sources' => $example->source_refs ?? []];
            array_walk_recursive($payload,
                static function (mixed $value) use (&$references): void {
                    if (is_string($value)) {
                        $references[] = $value;
                    }
                });
            if (array_intersect($answerReferences, $references) === []) {
                continue;
            }
            self::assertFalse(EstimateGenerationLearningSourceTrustPolicy::isIndexable($example));
            self::assertFalse(EquipmentCountKnowledgeValidationService::hasPassingValidationMetadata($example));
            self::assertNotSame('passed', data_get($example->context_payload, 'source_claim_validation.engineering_applicability'));
        }
        $money = $this->modelMoneySnapshot($session);
        $job->handle(app(RunEstimateGenerationDraft::class));
        self::assertSame($money, $this->modelMoneySnapshot($session));
        Http::assertNothingSent();
    }

    public static function workTechnologyGenerationCases(): array
    {
        return ['active human assumption' => ['answered'], 'revoked human answer' => ['revoked'],
            'explicitly unresolved' => ['left_unresolved'], 'replacement work excludes old context' => ['answered', true]];
    }

    public function test_http_local_rebuild_excludes_foreign_work_technology_but_full_generation_keeps_both_contexts(): void
    {
        $clarification = ['question' => 'Как закрепляется экспозиционное оборудование?',
            'reason' => 'Исходное описание не определяет способ крепления.'];
        [, $project, $session, , $headers] = $this->generatedSession(false, true, technologyClarification: $clarification, withForeignSection: true);
        $url = $this->url($project, $session);
        $localKeys = array_column($session->fresh()->draft_payload['local_estimates'], 'key');
        sort($localKeys);
        self::assertSame(['ai-composer-supplementary:equipment', 'ai-composer-supplementary:finishing'], $localKeys);
        $questions = array_values(array_filter($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'),
            static fn (array $question): bool => ($question['source_locator']['kind'] ?? null) === 'composer_work_technology'));
        self::assertCount(2, $questions);
        $saved = [];
        foreach ($questions as $question) {
            $code = $question['code'];
            $fresh = array_values(array_filter($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'),
                static fn (array $item): bool => $item['code'] === $code));
            self::assertCount(1, $fresh);
            $question = $fresh[0];
            $workKey = $question['source_locator']['work_key'];
            $text = $workKey === self::GENERIC_WORK_KEY
                ? 'Для оборудования пользователь предполагает крепление по отдельной инструкции.'
                : 'Для подготовки поверхности пользователь предполагает иной порядок работ.';
            $this->postJson($url.'/questions/'.$question['code'].'/answer', ['response' => 'other', 'other' => $text,
                'document_ids' => [], 'idempotency_key' => (string) Str::uuid(),
                'expected_source_version' => $question['source_version'], 'answer_fingerprint' => $question['answer_fingerprint']], $headers)->assertOk();
            $saved[$workKey] = ['code' => $question['code'], 'other' => $text];
        }
        self::assertCount(2, $saved);
        $this->transport($session, descriptionOnly: true, technologyClarification: $clarification, withForeignSection: true);
        $fullOperation = (string) Str::uuid();
        $fullQuote = $this->postJson($url.'/operations/quote', ['request_id' => $fullOperation,
            'state_version' => $session->fresh()->state_version, 'operation_kind' => 'generation',
            'operation_parameters' => ['max_work_items' => 4]], $headers)->assertOk()->json('data');
        $queuedBefore = Queue::pushed(GenerateEstimateDraftJob::class)->count();
        $this->postJson($url.'/generate', ['request_id' => $fullOperation, 'quote_id' => $fullQuote['quote_id'],
            'state_version' => $session->fresh()->state_version, 'generation_mode' => 'ai_assisted'], $headers)->assertSuccessful();
        $fullJob = Queue::pushed(GenerateEstimateDraftJob::class)->slice($queuedBefore)->sole();
        foreach (ProcessingStage::cases() as $stage) {
            try {
                $fullJob->handle(app(RunEstimateGenerationDraft::class));
            } catch (\Throwable $error) {
                throw $this->recordingFailure ?? $error;
            }
        }
        self::assertCount(2, $this->composerProjects);
        $fullContexts = $this->composerProjects[1]['work_technology_context'];
        self::assertCount(2, $fullContexts);
        foreach ($fullContexts as $entry) {
            $workKey = $entry['context']['binding']['work_key'];
            self::assertArrayHasKey($workKey, $saved);
            self::assertSame(['answered', 'other', $saved[$workKey]['other'], [], 'user_assumption', 'unresolved', false],
                [$entry['status'], $entry['answer']['response'], $entry['answer']['other'], $entry['answer']['document_ids'],
                    $entry['answer']['trust'], $entry['answer']['engineering_applicability'], $entry['answer']['numeric_parameters_confirmed']]);
        }
        $readback = array_values(array_filter($this->getJson($url.'/questions', $headers)->assertOk()->json('data.items'),
            static fn (array $question): bool => ($question['source_locator']['kind'] ?? null) === 'composer_work_technology'));
        self::assertCount(2, $readback);
        foreach ($readback as $question) {
            $workKey = $question['source_locator']['work_key'];
            self::assertSame($saved[$workKey]['code'], $question['code']);
            self::assertSame($saved[$workKey]['other'], $question['answer']['other']);
            $saved[$workKey]['answer'] = $question['answer'];
        }
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $factsBefore = array_map(get_object_vars(...), $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts);
        $decisionsBefore = array_map(get_object_vars(...), $models->decisionsForSelectedFacts(...[...$scope, array_column($factsBefore, 'id')]));
        $ledgerBefore = $this->answerLedgerCounts($session);
        $this->transport($session, descriptionOnly: true, technologyClarification: $clarification, withForeignSection: true, onlyWorkSection: 'equipment');
        $rebuildOperation = (string) Str::uuid();
        $stateVersion = $session->fresh()->state_version;
        $quote = $this->postJson($url.'/operations/quote', ['request_id' => $rebuildOperation, 'state_version' => $stateVersion,
            'operation_kind' => 'rebuild', 'operation_parameters' => ['local_estimate_key' => 'ai-composer-supplementary:equipment']], $headers)->assertOk()->json('data');
        $plan = json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $rebuildOperation)->sole()->processing_plan,
            true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('ai-composer-supplementary:equipment', $plan['operation_parameters']['local_estimate_key']);
        self::assertSame(1, $plan['known_context']['known_work_count']);
        $queuedBefore = Queue::pushed(GenerateEstimateDraftJob::class)->count();
        $this->postJson($url.'/rebuild-section', ['request_id' => $rebuildOperation, 'quote_id' => $quote['quote_id'],
            'state_version' => $stateVersion, 'local_estimate_key' => 'ai-composer-supplementary:equipment'], $headers)->assertStatus(202);
        $rebuildJob = Queue::pushed(GenerateEstimateDraftJob::class)->slice($queuedBefore)->sole();
        foreach (ProcessingStage::cases() as $stage) {
            try {
                $rebuildJob->handle(app(RunEstimateGenerationDraft::class));
            } catch (\Throwable $error) {
                throw $this->recordingFailure ?? $error;
            }
        }
        self::assertCount(3, $this->composerProjects);
        $targetContexts = $this->composerProjects[2]['work_technology_context'];
        self::assertCount(1, $targetContexts, 'A local rebuild leaked another local estimate\'s work technology into the real Composer request.');
        self::assertSame(self::GENERIC_WORK_KEY, $targetContexts[0]['context']['binding']['work_key']);
        self::assertSame($saved[self::GENERIC_WORK_KEY]['answer'], $targetContexts[0]['answer']);
        self::assertStringNotContainsString($saved[self::SECONDARY_WORK_KEY]['other'], CanonicalPipelineJson::encode($targetContexts));
        self::assertSame($factsBefore, array_map(get_object_vars(...), $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts));
        self::assertSame($decisionsBefore, array_map(get_object_vars(...), $models->decisionsForSelectedFacts(...[...$scope, array_column($factsBefore, 'id')])));
        self::assertSame($ledgerBefore, $this->answerLedgerCounts($session));
        self::assertSame(9, DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('generation_attempt_id', $rebuildOperation)->where('status', 'completed')->count());
        self::assertSame('ai-composer-supplementary:equipment', $session->fresh()->input_payload['rebuild_section_key']);
        $this->transport($session, descriptionOnly: true, technologyClarification: $clarification, withForeignSection: true);
        $nextFullOperation = (string) Str::uuid();
        $nextFullState = $session->fresh()->state_version;
        $nextFullQuote = $this->postJson($url.'/operations/quote', ['request_id' => $nextFullOperation,
            'state_version' => $nextFullState, 'operation_kind' => 'generation',
            'operation_parameters' => ['max_work_items' => 4]], $headers)->assertOk()->json('data');
        self::assertSame('generation', DB::table('estimate_generation_credit_operations')->where('operation_id', $nextFullOperation)->sole()->kind);
        $queuedBefore = Queue::pushed(GenerateEstimateDraftJob::class)->count();
        $this->postJson($url.'/generate', ['request_id' => $nextFullOperation, 'quote_id' => $nextFullQuote['quote_id'],
            'state_version' => $nextFullState, 'generation_mode' => 'ai_assisted'], $headers)->assertSuccessful();
        self::assertNull($session->fresh()->input_payload['rebuild_section_key'] ?? null,
            'A full generation retained the preceding local rebuild target in its server input.');
        $nextFullJob = Queue::pushed(GenerateEstimateDraftJob::class)->slice($queuedBefore)->sole();
        foreach (ProcessingStage::cases() as $stage) {
            try {
                $nextFullJob->handle(app(RunEstimateGenerationDraft::class));
            } catch (\Throwable $error) {
                throw $this->recordingFailure ?? $error;
            }
        }
        self::assertCount(4, $this->composerProjects);
        self::assertSame(9, DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('generation_attempt_id', $nextFullOperation)->where('status', 'completed')->count());
        foreach ([$rebuildOperation => 'ai-composer-supplementary:equipment', $nextFullOperation => null] as $attempt => $expectedTarget) {
            $checkpoint = DB::table('estimate_generation_pipeline_checkpoints')->where('organization_id', $session->organization_id)
                ->where('project_id', $project->id)->where('session_id', $session->id)->where('generation_attempt_id', $attempt)
                ->where('stage', ProcessingStage::UnderstandDocuments->value)->where('status', 'completed')->sole();
            $envelope = json_decode($checkpoint->output_payload, true, 64, JSON_THROW_ON_ERROR);
            $manifest = json_decode(Storage::disk('s3')->get($envelope['artifact']['object_key']), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame($expectedTarget, $manifest['rebuild_section_key']);
        }
        self::assertNull($session->fresh()->input_payload['rebuild_section_key'] ?? null);
        Http::assertNothingSent();
    }

    public function test_http_work_technology_document_links_remain_assumptions_and_ignored_source_revokes_currentness(): void
    {
        $clarification = ['question' => 'Как закрепляется оборудование и какие условия крепления заданы изготовителем?',
            'reason' => 'Ведомость подтверждает состав и количество, но не условия крепления к основанию.'];
        [$rights, $project, $session, , $headers, $native] = $this->generatedSession(false, false, 'installation', $clarification);
        $url = $this->url($project, $session);
        $question = $this->workTechnologyQuestion($project, $session, $headers);
        $document = $session->documents()->sole();
        $originalNative = get_object_vars($native);
        $other = $this->postJson('/api/v1/admin/projects/'.$project->id.'/estimate-generation/sessions', [
            'request_id' => (string) Str::uuid(), 'description' => 'Отдельная сессия с собственной ведомостью оборудования.',
            'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal', 'profile_id' => 'construction',
            'construction_type' => 'new_construction', 'price_policy' => 'catalog', 'selected_sections' => ['equipment'],
        ], $headers)->assertCreated()->json('data');
        $otherSession = EstimateGenerationSession::query()->findOrFail($other['id']);
        $this->post($this->url($project, $otherSession).'/documents', ['request_id' => (string) Str::uuid(),
            'state_version' => $otherSession->fresh()->state_version, 'files' => [$this->workbook()]], $headers)->assertOk();
        $this->app->call([Queue::pushed(ProcessEstimateGenerationDocumentJob::class)->last(), 'handle']);
        $this->app->call([Queue::pushed(ProcessEstimateGenerationUnitJob::class)->last(), 'handle']);
        $otherDocument = $otherSession->documents()->sole();
        self::assertSame($document->fresh()->status, $otherDocument->fresh()->status);
        $money = $this->modelMoneySnapshot($session);
        $input = ['response' => 'other', 'other' => 'Пользователь ссылается на ведомость; метод крепления и норматив по ней не подтверждены.',
            'document_ids' => [(int) $document->id], 'idempotency_key' => (string) Str::uuid(),
            'expected_source_version' => $question['source_version'], 'answer_fingerprint' => $question['answer_fingerprint']];
        $answerUrl = $url.'/questions/'.$question['code'].'/answer';
        $this->postJson($answerUrl, [...$input, 'document_ids' => [(int) $otherDocument->id]], $headers)
            ->assertConflict()->assertJsonPath('code', 'question_stale');
        $receipt = $this->postJson($answerUrl, $input, $headers)->assertOk()->json('data');
        $readback = $this->workTechnologyQuestion($project, $session, $headers);
        self::assertSame(['answered', [(int) $document->id], 'user_assumption', 'unresolved', false],
            [$readback['status'], $readback['answer']['document_ids'], $readback['answer']['trust'],
                $readback['answer']['engineering_applicability'], $readback['answer']['numeric_parameters_confirmed']]);
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        self::assertSame($originalNative, get_object_vars(app(ProjectModelRepository::class)->factsByIds(...[...$scope, [$native->id]])[0]));
        $row = $this->installation($session->fresh());
        self::assertSame(['3', 'count', null, null], [$row['quantity'], $row['unit'], $row['unit_price'], $row['total_cost']]);
        self::assertFalse(isset($row['normative_match']['norm_id']));
        self::assertSame($money, $this->modelMoneySnapshot($session));
        $this->postJson($url.'/documents/'.$document->id.'/ignore', ['state_version' => $session->fresh()->state_version,
            'reason' => 'Источник исключён: связанное с ним качественное допущение больше не является текущим.'], $headers)->assertOk();
        $items = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
        self::assertSame([], array_values(array_filter($items, static fn (array $item): bool =>
            ($item['code'] ?? null) === $question['code'])));
        $this->postJson($answerUrl, [...$input, 'idempotency_key' => (string) Str::uuid()], $headers)->assertConflict();
        self::assertCount(1, app(ProjectModelRepository::class)->decisions(...[...$scope, [$receipt['decision_id']]]));
        $this->assertNoExtraModelWork($session);
        self::assertSame($money, $this->modelMoneySnapshot($session));
    }

    #[DataProvider('workTechnologyReadinessCases')]
    public function test_http_qualitative_work_technology_does_not_block_an_otherwise_complete_synthetic_estimate(bool $withAssumption): void
    {
        $clarification = ['question' => 'Как оборудование крепится к основанию согласно проекту или инструкции изготовителя?',
            'reason' => 'Ведомость сама по себе не задаёт способ крепления.'];
        [, $project, $session, , $headers, , $revision] = $this->confirmedSession($clarification, $withAssumption);
        self::assertTrue($revision['result']['can_confirm_for_apply']);
        self::assertSame([], $revision['result']['missing_requirements']);
        self::assertSame('ready_to_apply', $session->fresh()->status->value);
        $question = $this->workTechnologyQuestion($project, $session, $headers);
        self::assertSame($withAssumption ? 'answered' : 'unanswered', $question['status']);
        self::assertSame([], $question['actions']);
        if ($withAssumption) {
            self::assertSame(['user_assumption', 'unresolved', false], [$question['answer']['trust'],
                $question['answer']['engineering_applicability'], $question['answer']['numeric_parameters_confirmed']]);
        } else {
            self::assertNull($question['answer']);
        }
        $money = $this->modelMoneySnapshot($session);
        $url = $this->url($project, $session);
        $this->postJson($url.'/questions/'.$question['code'].'/answer', ['response' => 'other',
            'other' => 'Ответ не разрешён в состоянии готовности к применению.', 'idempotency_key' => (string) Str::uuid(),
            'expected_source_version' => $question['source_version'], 'answer_fingerprint' => $question['answer_fingerprint']], $headers)->assertConflict();
        $this->getJson($url.'/export?format=json', $headers)->assertOk();
        self::assertSame($money, $this->modelMoneySnapshot($session));
        $this->assertNoExtraModelWork($session);
    }

    public static function workTechnologyReadinessCases(): array
    {
        return ['pending qualitative question' => [false], 'current human assumption' => [true]];
    }

    public function test_http_excluded_work_cannot_keep_a_current_technology_card_or_accept_an_answer(): void
    {
        $clarification = ['question' => 'Как оборудование закрепляется в составе первой работы?',
            'reason' => 'Способ крепления первой работы не задан исходным описанием.'];
        [, $project, $session, , $headers] = $this->generatedSession(false, true, 'installation', $clarification, true);
        $url = $this->url($project, $session);
        $first = $this->installation($session->fresh(), self::GENERIC_WORK_KEY);
        $second = $this->installation($session->fresh(), self::SECONDARY_WORK_KEY);
        self::assertNotSame($first['key'], $second['key']);
        self::assertSame($first['metadata']['scope_entity_id'], $second['metadata']['scope_entity_id']);
        self::assertSame($first['composition_intent']['source_fact_ids'], $second['composition_intent']['source_fact_ids']);
        $question = $this->workTechnologyQuestion($project, $session, $headers);
        $answerInput = ['response' => 'other', 'other' => 'Предварительный ответ относится только к первой работе, которую пользователь затем исключает.',
            'document_ids' => [], 'idempotency_key' => (string) Str::uuid(),
            'expected_source_version' => $question['source_version'], 'answer_fingerprint' => $question['answer_fingerprint']];
        $this->postJson($url.'/questions/'.$question['code'].'/answer', $answerInput, $headers)->assertOk();
        $answered = $this->workTechnologyQuestion($project, $session, $headers);
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $historyIds = [...$first['composition_intent']['source_fact_ids'], $answered['answer']['fact_id']];
        $history = array_map(get_object_vars(...), $models->factsByIds(...[...$scope, $historyIds]));
        $decision = $models->decisions(...[...$scope, [$answered['answer']['decision_id']]]);
        self::assertCount(1, $decision);
        $money = $this->modelMoneySnapshot($session);
        $selection = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'selected_sections' => ['equipment'], 'profile_id' => 'construction', 'construction_type' => 'new_construction',
            'price_policy' => 'catalog', 'selected_work_item_keys' => [$second['key']],
            'artifact_hash' => $session->fresh()->draft_payload['artifact_hash'],
            'scope_reason' => 'В текущую смету включена только вторая работа; первая работа и её технологическое допущение исключены.'];
        $receipt = $this->postJson($url.'/evaluation/scope', $selection, $headers)->assertOk()->json('data');
        self::assertSame('0', $receipt['cost_units']);
        self::assertSame(CanonicalPipelineJson::encode($receipt), CanonicalPipelineJson::encode(
            $this->postJson($url.'/evaluation/scope', $selection, $headers)->assertOk()->json('data')));
        $evaluation = $this->postJson($url.'/evaluation/recalculate', ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version], $headers)->assertOk()->json('data');
        self::assertSame('0', $evaluation['cost_units']);
        self::assertSame([$second['key']], $evaluation['result']['selected_work_item_keys']);
        self::assertSame([$first['key']], array_column($evaluation['result']['excluded_work_items'], 'key'));
        self::assertSame([$second['key']], array_column($evaluation['result']['scenarios'][0]['positions'], 'key'));
        $questions = $this->getJson($url.'/questions', $headers)->assertOk()->json('data.items');
        self::assertSame([], array_values(array_filter($questions, static fn (array $item): bool =>
            ($item['code'] ?? null) === $question['code'])), 'An excluded work still exposes its technology answer as current.');
        $this->postJson($url.'/questions/'.$question['code'].'/answer', [...$answerInput,
            'idempotency_key' => (string) Str::uuid()], $headers)->assertConflict()->assertJsonPath('code', 'question_stale');
        self::assertSame($history, array_map(get_object_vars(...), $models->factsByIds(...[...$scope, $historyIds])));
        self::assertSame(array_map(get_object_vars(...), $decision), array_map(get_object_vars(...),
            $models->decisions(...[...$scope, [$answered['answer']['decision_id']]])));
        $included = $this->installation($session->fresh(), self::SECONDARY_WORK_KEY);
        self::assertSame([$second['key'], $second['name'], $second['metadata']['scope_entity_id'], $second['composition_intent']['source_fact_ids'], null, null, null, null],
            [$included['key'], $included['name'], $included['metadata']['scope_entity_id'], $included['composition_intent']['source_fact_ids'],
                $included['quantity'], $included['unit'], $included['unit_price'], $included['total_cost']]);
        self::assertSame($money, $this->modelMoneySnapshot($session));
        $this->assertNoExtraModelWork($session);
    }

    public function test_ignored_uploaded_source_invalidates_the_real_quantity_guard_and_denies_http_export_and_apply(): void
    {
        [, $project, $session, , $headers, $count] = $this->confirmedSession();
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $draft = $session->fresh()->draft_payload;
        app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $draft, false]);
        $document = $session->documents()->sole();
        $this->postJson($this->url($project, $session).'/documents/'.$document->id.'/ignore', [
            'state_version' => $session->fresh()->state_version, 'reason' => 'Ведомость исключена пользователем: её количество больше не является текущим основанием.'], $headers)->assertOk();
        self::assertSame('ignored', $document->fresh()->status);
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertNotContains($count->id, array_column($snapshot->facts, 'id'));
        try {
            app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $draft, false]);
            self::fail('An ignored document remained an eligible quantity source.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('draft', $error->errors());
        }
        $before = $session->fresh()->getAttributes();
        $this->getJson($this->url($project, $session).'/export?format=json', $headers)->assertUnprocessable();
        $this->postJson($this->url($project, $session).'/apply', ['state_version' => $session->fresh()->state_version,
            'artifact_hash' => $draft['artifact_hash'], 'idempotency_key' => (string) Str::uuid()], $headers)->assertUnprocessable();
        self::assertSame($before, $session->fresh()->getAttributes());
        self::assertNull($session->fresh()->applied_estimate_id);
        self::assertSame(0, Estimate::query()->where('project_id', $project->id)->count());
        $this->assertNoExtraModelWork($session);
    }

    private function assertPriorArtifactRejected(Project $project, EstimateGenerationSession $session, array $headers, array $revision, string $artifact): void
    {
        $url = $this->url($project, $session);
        $before = $session->fresh()->getAttributes();
        $this->postJson($url.'/evaluation/confirm-scenario', ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'revision_id' => $revision['revision_id'],
            'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('scenario');
        $this->getJson($url.'/export?format=json', $headers)->assertUnprocessable()->assertJsonValidationErrors('draft');
        $this->postJson($url.'/apply', ['state_version' => $session->fresh()->state_version, 'artifact_hash' => $artifact,
            'idempotency_key' => (string) Str::uuid()], $headers)->assertUnprocessable()->assertJsonValidationErrors('draft');
        self::assertSame($before, $session->fresh()->getAttributes());
        self::assertNull($session->fresh()->applied_estimate_id);
        self::assertSame(0, Estimate::query()->where('project_id', $project->id)->count());
    }

    private function confirm(Project $project, EstimateGenerationSession $session, array $headers, array $revision): void
    {
        $this->postJson($this->url($project, $session).'/evaluation/confirm-scenario', ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'revision_id' => $revision['revision_id'],
            'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true], $headers)->assertOk();
    }

    private function assertNoExtraModelWork(EstimateGenerationSession $session): void
    {
        self::assertCount(2, $this->wireHistory);
        self::assertSame(2, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(2, DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->where('status', 'completed')->count());
        Http::assertNothingSent();
    }

    private function workTechnologyQuestion(Project $project, EstimateGenerationSession $session, array $headers): array
    {
        $items = $this->getJson($this->url($project, $session).'/questions', $headers)->assertOk()->json('data.items');
        $questions = array_values(array_filter($items, static fn (array $question): bool =>
            ($question['source_locator']['kind'] ?? null) === 'composer_work_technology'));
        self::assertCount(1, $questions);

        return $questions[0];
    }

    private function answerLedgerCounts(EstimateGenerationSession $session): array
    {
        $counts = [];
        foreach (['project_model_assertions', 'project_model_corrections', 'evidence'] as $table) {
            $counts[$table] = DB::table('estimate_generation_'.$table)->where('session_id', $session->id)->count();
        }

        return $counts;
    }

    private function modelMoneySnapshot(EstimateGenerationSession $session): array
    {
        return ['calls' => count($this->wireHistory), 'balance' => app(AICreditService::class)->balance($session->organization),
            'usage' => DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->orderBy('attempt_id')->get()->toJson(),
            'runs' => DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)->orderBy('id')->get()->toJson(),
            'operations' => DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->orderBy('operation_id')->get()->toJson()];
    }

    private function confirmedSession(?array $technologyClarification = null, bool $withTechnologyAssumption = false): array
    {
        [$rights, $project, $session, $catalog, $headers, $count] = $this->generatedSession(technologyClarification: $technologyClarification);
        $url = $this->url($project, $session);
        if ($withTechnologyAssumption) {
            $question = $this->workTechnologyQuestion($project, $session, $headers);
            $this->postJson($url.'/questions/'.$question['code'].'/answer', ['response' => 'other',
                'other' => 'Пользователь предполагает крепление по инструкции; инженерная применимость отдельно не подтверждена.',
                'document_ids' => [], 'idempotency_key' => (string) Str::uuid(), 'expected_source_version' => $question['source_version'],
                'answer_fingerprint' => $question['answer_fingerprint']], $headers)->assertOk();
        }
        $item = $this->installation($session);
        self::assertSame('3', $item['quantity']);
        self::assertSame('count', $item['unit']);
        self::assertSame($count->entityId, $item['metadata']['scope_entity_id']);
        self::assertSame($count->id, $item['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        $this->postJson($url.'/evaluation/scope', ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'selected_sections' => ['equipment'], 'profile_id' => 'construction', 'construction_type' => 'new_construction',
            'price_policy' => 'normative', 'selected_work_item_keys' => [$item['key']], 'artifact_hash' => $session->draft_payload['artifact_hash'],
            'scope_reason' => 'Синтетическая приёмка только монтажа оборудования заказчика, без поставки и наладки.'], $headers)->assertOk();
        $this->postJson($url.'/normative-candidate', ['state_version' => $session->fresh()->state_version,
            'work_item_key' => $item['key'], 'norm_id' => $catalog['norm_id'], 'selection_source' => 'catalog_search'], $headers)->assertOk();
        $target = ['entity_id' => $count->entityId, 'section' => 'equipment', 'requirement' => 'equipment_specification',
            'state_version' => $session->fresh()->state_version];
        $sources = $this->getJson($url.'/evaluation/requirement-sources?'.http_build_query($target), $headers)->assertOk()->json('data.sources');
        self::assertCount(1, $sources);
        $this->postJson($url.'/evaluation/requirement', [...$target, 'request_id' => (string) Str::uuid(),
            'disposition' => 'document_verified', 'document_fact_id' => $sources[0]['fact_id'],
            'document_applicability_confirmed' => true, 'document_fact_version' => $sources[0]['fact_version'],
            'document_value_hash' => $sources[0]['value_hash'], 'reason' => 'Проверена текущая спецификация и её применимость к выбранному монтажу.'], $headers)->assertOk();
        foreach (['equipment_purchase', 'delivery', 'commissioning'] as $requirement) {
            $this->postJson($url.'/evaluation/requirement', ['request_id' => (string) Str::uuid(),
                'state_version' => $session->fresh()->state_version, 'entity_id' => $count->entityId,
                'section' => 'equipment', 'requirement' => $requirement, 'disposition' => 'not_applicable',
                'reason' => 'Оборудование предоставляет заказчик; этот этап не входит в выбранную смету.'], $headers)->assertOk();
        }
        $revision = $this->postJson($url.'/evaluation/recalculate', ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version], $headers)->assertOk()->json('data');
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode([
            'missing' => $revision['result']['missing_requirements'],
            'row' => array_intersect_key($this->installation($session->fresh()), array_flip([
                'key', 'quantity', 'unit', 'metadata', 'composition_intent', 'labor', 'total_cost',
            ])),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame('30.00', $revision['result']['scenarios'][0]['known_subtotals']['RUB']);
        self::assertSame('36.60', $revision['result']['scenarios'][0]['gross_subtotals']['RUB']);
        $row = $this->installation($session->fresh());
        self::assertSame('100 шт', $row['normative_match']['unit']);
        self::assertSame('0.3', $row['labor'][0]['quantity']);
        self::assertSame('10', $row['labor'][0]['quantity_per_unit']);
        self::assertSame('30.00', $row['total_cost']);
        self::assertSame([], $row['materials']);
        self::assertSame([], $row['machinery']);
        $this->postJson($url.'/evaluation/confirm-scenario', ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'revision_id' => $revision['revision_id'],
            'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true], $headers)->assertOk();

        return [$rights, $project, $session, $catalog, $headers, $count, $revision];
    }

    private function generatedSession(bool $withNormativeContext = true, bool $descriptionOnly = false, ?string $requirementKey = null, ?array $technologyClarification = null, bool $withSecondaryWork = false, bool $withForeignSection = false): array
    {
        $this->app->instance(NormativePinClock::class, new class implements NormativePinClock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-01');
            }
        });
        config(['cache.default' => 'array', 'estimate-generation.universal_enabled' => true, 'estimate-generation.credits.enabled' => true,
            'ai-assistant-credits.enforce' => false, 'web_auth.origins.admin' => ['https://admin.fixture.test'],
            'ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        (new InMemoryS3HttpStorage)->install();
        $this->settings();
        $rights = AssistantRealAuthorizationFixture::create(['working-entry']);
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.create', 'estimate_generation.generate',
            'estimate_generation.view', 'estimate_generation.review', 'estimate_generation.upload_documents',
            'estimate_generation.select_normative', 'estimate_generation.export', 'estimate_generation.apply']], 'system_permissions' => ['admin.access']]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false, 'address' => null]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        foreach ([['Штука', 'шт', 'piece'], ['Час', 'ч', 'time']] as [$name, $unit, $type]) {
            MeasurementUnit::query()->create(['organization_id' => $rights->organization->id, 'name' => $name, 'short_name' => $unit, 'type' => $type]);
        }
        $catalog = $withNormativeContext ? $this->catalog() : [];
        $headers = $this->headers($rights->member);
        $created = $this->postJson('/api/v1/admin/projects/'.$project->id.'/estimate-generation/sessions', [
            'request_id' => (string) Str::uuid(), 'description' => $descriptionOnly
                ? 'Подготовить демонстрационную экспозицию из имущества заказчика; состав и технология требуют уточнения.'.($withForeignSection ? ' Подготовить поверхность в зоне размещения экспозиции; способ подготовки требует уточнения.' : '')
                : 'Монтаж оборудования по ведомости на промышленной площадке. Технология требует отдельной проверки.',
            'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal', 'profile_id' => 'construction',
            'construction_type' => 'new_construction', 'price_policy' => $withNormativeContext ? 'normative' : 'catalog', 'selected_sections' => $withForeignSection ? ['equipment', 'finishing'] : ['equipment'],
            ...($withNormativeContext ? ['estimate_regional_price_version_id' => $catalog['version_id'], 'normative_dataset_version' => $catalog['dataset_key']] : []),
        ], $headers);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $created = $created->json('data');
        $session = EstimateGenerationSession::query()->findOrFail($created['id']);
        $url = $this->url($project, $session);
        if ($withNormativeContext) {
            $this->postJson($url.'/evaluation/pricing-source', ['request_id' => (string) Str::uuid(), 'state_version' => $session->state_version,
                'estimate_regional_price_version_id' => $catalog['version_id'], 'conditions' => ['as_of_date' => '2026-10-01',
                    'vat_mode' => 'excluded', 'vat_rate' => '22', 'delivery' => 'outside_scope', 'basis' => 'Синтетическая цена для сквозной проверки расчёта; поставка вне сметы.']], $headers)->assertOk();
        }
        $count = null;
        if (! $descriptionOnly) {
            $file = $this->workbook();
            $digest = hash_file('sha256', $file->getPathname());
            $this->post($url.'/documents', ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'files' => [$file]], $headers)->assertOk();
            $document = $session->documents()->sole();
            self::assertSame($digest, $document->checksum_sha256);
            self::assertSame('sha256:'.$digest, $document->source_version);
            self::assertSame($digest, hash('sha256', Storage::disk('s3')->get($document->storage_path)));
            $this->app->call([Queue::pushed(ProcessEstimateGenerationDocumentJob::class)->sole(), 'handle']);
            $this->app->call([Queue::pushed(ProcessEstimateGenerationUnitJob::class)->sole(), 'handle']);
            $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id, (int) $project->id, (int) $session->id, 10001)['snapshot'];
            $counts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'equipment_count'));
            self::assertCount(1, $counts);
            $count = $counts[0];
            self::assertSame('3', $count->value);
            self::assertSame('шт', $count->unit);
            self::assertSame('document', $count->origin);
            self::assertSame('confirmed', $count->status);
            self::assertSame([(int) $session->organization_id, (int) $project->id, (int) $session->id, $document->source_version],
                [$count->organizationId, $count->projectId, $count->sessionId, $count->sourceVersion]);
            $evidence = array_values(array_filter($snapshot->evidence, static fn ($node): bool => in_array($node->id, $count->evidenceIds, true)));
            self::assertCount(1, $evidence);
            self::assertSame('document:'.$document->id, $evidence[0]->sourceArtifactId);
            self::assertSame('xlsx:sheet:Оборудование!C3', $evidence[0]->nativeReference);
            self::assertTrue((new NativeNumericFactProof)->matches($count, $evidence[0]));
            $entities = array_column($snapshot->entities, null, 'id');
            self::assertSame('equipment:pump', $entities[$count->entityId]->attributes['properties']['source_entity_key']);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame($descriptionOnly ? 'draft' : 'input_review_required', $session->fresh()->status->value);
        if (! $descriptionOnly) {
            $this->postJson($url.'/confirm-input', ['state_version' => $session->fresh()->state_version], $headers)->assertOk();
            self::assertSame('ready_to_generate', $session->fresh()->status->value);
        } else {
            self::assertFalse($session->fresh()->input_payload['input_confirmed'] ?? false);
        }
        $this->transport($session, descriptionOnly: $descriptionOnly, requirementKey: $requirementKey, technologyClarification: $technologyClarification,
            withSecondaryWork: $withSecondaryWork, withForeignSection: $withForeignSection);
        app(AICreditService::class)->grant($rights->organization, 1000000, 'purchase', null, 'test:http-money-acceptance');
        $operation = (string) Str::uuid();
        $quote = $this->postJson($url.'/operations/quote', ['request_id' => $operation, 'state_version' => $session->fresh()->state_version,
            'operation_kind' => 'generation', 'operation_parameters' => ['max_work_items' => 4]], $headers)->assertOk()->json('data');
        $generated = $this->postJson($url.'/generate', ['request_id' => $operation, 'quote_id' => $quote['quote_id'],
            'state_version' => $session->fresh()->state_version, 'generation_mode' => 'ai_assisted'], $headers);
        self::assertTrue($generated->isSuccessful(), $generated->getContent());
        $job = Queue::pushed(GenerateEstimateDraftJob::class)->first();
        self::assertInstanceOf(GenerateEstimateDraftJob::class, $job);
        foreach (ProcessingStage::cases() as $stage) {
            try {
                $job->handle(app(RunEstimateGenerationDraft::class));
            } catch (\Throwable $error) {
                throw $this->recordingFailure ?? $error;
            }
        }
        $session->refresh();
        self::assertSame(9, DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('generation_attempt_id', $operation)->where('status', 'completed')->count());
        if (! $descriptionOnly) {
            self::assertCount(2, $this->wireHistory);
        }

        return [$rights, $project, $session, $catalog, $headers, $count];
    }

    private function transport(EstimateGenerationSession $session, ?array $directQuantity = null, bool $descriptionOnly = false, ?string $requirementKey = null, ?array $technologyClarification = null, ?array $workOverride = null, bool $withSecondaryWork = false, bool $withForeignSection = false, ?string $onlyWorkSection = null): void
    {
        $descriptor = json_decode(file_get_contents(base_path('tests/Fixtures/EstimateGeneration/universal-replay/xlsx-floor-finishing-v1.json')), true, 64, JSON_THROW_ON_ERROR);
        $responses = $descriptor['recordings'];
        $replies = [function (RequestInterface $request) use ($responses, $session, $directQuantity, $descriptionOnly, $requirementKey, $technologyClarification, $workOverride, $withSecondaryWork, $withForeignSection, $onlyWorkSection): Response {
            try {
                $wire = json_decode((string) $request->getBody(), true, 128, JSON_THROW_ON_ERROR);
                $project = null;
                foreach ($wire['messages'] as $message) {
                    if ($message['role'] === 'user') {
                        $project = json_decode($message['content'], true, 128, JSON_THROW_ON_ERROR)['project'] ?? null;
                    }
                }
                self::assertIsArray($project);
                $this->composerProjects[] = $project;
                self::assertSame((int) $session->id, $project['scope']['session_id']);
                if ($descriptionOnly) {
                    $descriptions = array_values(array_filter($project['facts'], static fn (array $fact): bool =>
                        ($fact['context_kind'] ?? null) === 'initial_description'));
                    self::assertCount(1, $descriptions);
                    self::assertSame($session->input_payload['description'], $descriptions[0]['value']['description']);
                    self::assertSame(false, $descriptions[0]['value']['numeric_parameters_confirmed']);
                    self::assertSame($withForeignSection ? ['equipment', 'finishing'] : ['equipment'], $descriptions[0]['declared_work_sections']);
                    self::assertSame([], array_values(array_filter($project['facts'], static fn (array $fact): bool => $fact['type'] === 'equipment_count')));
                    $entityId = $descriptions[0]['entity_id'];
                    $contextId = $sourceId = $descriptions[0]['id'];
                    $quantities = [];
                } else {
                    $counts = array_values(array_filter($project['facts'], static fn (array $fact): bool => $fact['type'] === 'equipment_count'));
                    $specifications = array_values(array_filter($project['facts'], static fn (array $fact): bool => $fact['type'] === 'equipment_specification'));
                    self::assertCount(1, $counts);
                    self::assertCount(1, $specifications);
                    $quantities = array_values(array_filter($project['derived_quantities'], static fn (array $quantity): bool =>
                        ($quantity['formula_key'] ?? null) === 'equipment_count' && ($quantity['entity_id'] ?? null) === $counts[0]['entity_id']
                        && ($quantity['operand_fact_ids'] ?? []) === [$counts[0]['id']] && ($quantity['review_blockers'] ?? []) === []));
                    $sourceId = $counts[0]['id'];
                    $entityId = $counts[0]['entity_id'];
                    $contextId = $specifications[0]['id'];
                }
                if ($directQuantity !== null) {
                    $facts = array_column($project['facts'], null, 'id');
                    $binding = $facts[$directQuantity['fact_id']] ?? null;
                    self::assertIsArray($binding, 'The current direct-quantity binding is absent from the real Composer input.');
                    self::assertSame('evaluation_answer', $binding['type']);
                    self::assertSame('direct_quantity', $binding['value']['answer_kind']);
                    self::assertSame($directQuantity['work_item_key'], $binding['value']['work_item_key']);
                    self::assertSame($directQuantity['quantity_key'], $binding['value']['quantity_key']);
                    self::assertSame($directQuantity['source_fact_id'], $binding['value']['source_fact_id']);
                    self::assertSame($directQuantity['decision_id'], $binding['value']['decision_id']);
                    self::assertSame($directQuantity['target_entity_id'], $binding['value']['source_entity_id']);
                    $sourceId = $binding['value']['source_fact_id'];
                    self::assertSame($directQuantity['target_entity_id'], $facts[$sourceId]['entity_id']);
                    $decisions = array_column($project['decisions'], null, 'id');
                    self::assertSame($directQuantity['fact_id'], $decisions[$directQuantity['decision_id']]['selected_fact_id']);
                    $quantities = array_values(array_filter($project['derived_quantities'], static fn (array $quantity): bool =>
                        ($quantity['formula_key'] ?? null) === 'direct_quantity'
                        && ($quantity['entity_id'] ?? null) === $directQuantity['target_entity_id']
                        && ($quantity['operand_fact_ids'] ?? []) === [$sourceId] && ($quantity['review_blockers'] ?? []) === []));
                }
                if (! $descriptionOnly || $directQuantity !== null) {
                    self::assertCount(1, $quantities);
                }
                if ($directQuantity !== null) {
                    self::assertSame([$descriptionOnly ? '3' : '4', 'count'], [$quantities[0]['value'], $quantities[0]['unit']]);
                    self::assertSame($directQuantity['quantity_key'], $quantities[0]['quantity_key']);
                    if ($descriptionOnly) {
                        self::assertSame('estimated', $quantities[0]['source']);
                        self::assertSame([$sourceId], $quantities[0]['quantity_basis']['assumptions']);
                    }
                    $binding = $quantities[0]['quantity_basis']['formula_inputs']['operands'][0]['quantity_binding'];
                    self::assertSame([$directQuantity['fact_id'], $directQuantity['decision_id'], $directQuantity['work_item_key']],
                        [$binding['fact_id'], $binding['decision_id'], $binding['work_item_key']]);
                }
                $intent = ['kind' => 'supplementary', 'work_key' => $workOverride['work_key'] ?? ($descriptionOnly ? self::GENERIC_WORK_KEY : 'equipment.install'),
                    'name' => $workOverride['name'] ?? ($descriptionOnly ? 'Подготовка экспозиции из имущества заказчика' : 'Монтаж насоса по ведомости'),
                    'derived_quantity_id' => $quantities[0]['id'] ?? null, 'source_fact_ids' => array_values(array_unique([$contextId, $sourceId])),
                    'entity_id' => $entityId, 'technology_package_candidate' => null, 'unit' => 'count', 'work_section' => 'equipment',
                    ...($requirementKey !== null ? ['requirement_key' => $requirementKey] : []),
                    ...($technologyClarification !== null ? ['technology_clarification' => $technologyClarification] : []),
                    'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
                $intents = [$intent];
                if ($withSecondaryWork || $withForeignSection) {
                    $secondary = [...$intent, 'work_key' => self::SECONDARY_WORK_KEY, 'name' => 'Размещение экспозиционного оборудования в выбранной зоне'];
                    unset($secondary['technology_clarification']);
                    if ($withForeignSection) {
                        $secondary['work_section'] = 'finishing';
                        $secondary['name'] = 'Подготовка поверхности в зоне размещения экспозиции';
                        $secondary['technology_clarification'] = ['question' => 'Как подготавливается поверхность в зоне экспозиции?',
                            'reason' => 'Описание не задаёт порядок подготовки поверхности.'];
                    }
                    $intents[] = $secondary;
                }
                if ($onlyWorkSection !== null) {
                    $intents = array_values(array_filter($intents, static fn (array $item): bool => $item['work_section'] === $onlyWorkSection));
                }
                $response = $responses[0]['response'];
                $response['choices'][0]['message']['content'] = json_encode(['work_intents' => $intents], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($response, JSON_THROW_ON_ERROR));
            } catch (\Throwable $error) {
                $this->recordingFailure = $error;
                throw $error;
            }
        }, new Response(200, ['Content-Type' => 'application/json'], json_encode($responses[1]['response'], JSON_THROW_ON_ERROR))];
        if ($this->wireHandler !== null) {
            self::assertSame(0, $this->wireHandler->count());
            $this->wireHandler->append(...$replies);

            return;
        }
        $handler = $this->wireHandler = new MockHandler($replies);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->wireHistory));
        $this->app->instance(RerankWireClient::class, new TimewebRerankWireClient(
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
            httpClient: new Client(['handler' => $stack])));
    }

    private function workbook(): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Оборудование')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit'],
            ['equipment:pump', 'equipment_specification', 'Насос водяной по ведомости изготовителя', ''],
            ['equipment:pump', 'equipment_count', 3, 'шт'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'most-http-money-xlsx-');
        try {
            (new Xlsx($book))->save($path);

            return UploadedFile::fake()->createWithContent('equipment.xlsx', file_get_contents($path));
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    private function headers(User $actor): array
    {
        $uuid = (string) Str::uuid();
        $session = UserAuthSession::query()->create(['user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $uuid, 'device_fingerprint' => hash('sha256', $uuid), 'device_name' => 'MOST HTTP money acceptance',
            'ip_address' => '127.0.0.1', 'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $session->session_uuid, (int) $actor->current_organization_id, false);

        return ['Accept' => 'application/json', 'Origin' => 'https://admin.fixture.test', 'Authorization' => 'Bearer '.$tokens->accessToken];
    }

    private function url(Project $project, EstimateGenerationSession $session): string
    {
        return '/api/v1/admin/projects/'.$project->id.'/estimate-generation/sessions/'.$session->id;
    }

    private function installation(EstimateGenerationSession $session, string $workKey = 'equipment.install'): array
    {
        $rows = [];
        foreach ($session->draft_payload['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (($item['composition_intent']['work_key'] ?? null) === $workKey) {
                        $rows[] = $item;
                    }
                }
            }
        }
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function settings(): void
    {
        if (app(EstimateGenerationSettingsService::class)->currentSnapshot('global', null) !== null) {
            return;
        }
        app(EstimateGenerationSettingsService::class)->change((int) SystemAdmin::factory()->create()->id, EstimateGenerationSettingsData::fromArray([
            'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['xlsx'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB'],
        ]));
    }

    private function catalog(): array
    {
        $now = now();
        $key = 'synthetic-http-money-'.strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'rows_imported' => 1, 'errors_count' => 0,
            'finished_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices',
            'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => $key,
            'name' => 'Synthetic HTTP numeric fixture', 'norm_type' => 'gesn', 'source_file' => 'synthetic-http-money', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '20', 'name' => 'Synthetic equipment',
            'path' => '20/synthetic', 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '20-99-999-01',
            'section_code' => '20', 'section_name' => 'Synthetic equipment', 'section_id' => $section,
            'name' => 'Монтаж оборудования по требованиям изготовителя',
            'work_composition' => json_encode(['Установка и крепление оборудования по согласованной спецификации'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'unit' => '100 шт', 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now]);
        $resourceCode = 'SYNTHETIC-HTTP-LABOR-'.$dataset;
        $resource = DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $norm, 'resource_code' => $resourceCode,
            'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '10', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'HTTP-'.$dataset, 'name' => 'Synthetic region',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Synthetic zone',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2026)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => 'Synthetic 2026 Q4',
                'year' => 2026, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $price = DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $prices, 'regional_price_version_id' => $version,
            'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $resourceCode,
            'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => '100', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'resource_id' => $resource, 'price_id' => $price, 'version_id' => $version, 'dataset_key' => $key,
            'price_dataset_id' => $prices, 'resource_code' => $resourceCode, 'region_id' => $region, 'zone_id' => $zone, 'period_id' => $period];
    }

    private function anotherRegionalPriceVersion(array $catalog, bool $withMatchingPrice): array
    {
        $now = now();
        $key = 'synthetic-http-money-v2-'.strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $catalog['region_id'],
            'price_zone_id' => $catalog['zone_id'], 'period_id' => $catalog['period_id'], 'version_key' => $key,
            'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $price = $withMatchingPrice ? DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $dataset,
            'regional_price_version_id' => $version, 'region_id' => $catalog['region_id'], 'price_zone_id' => $catalog['zone_id'],
            'period_id' => $catalog['period_id'], 'resource_code' => $catalog['resource_code'], 'resource_name' => 'Труд рабочих',
            'unit' => 'h', 'base_price' => '200.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]) : null;
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['version_id' => $version, 'price_id' => $price];
    }
}
