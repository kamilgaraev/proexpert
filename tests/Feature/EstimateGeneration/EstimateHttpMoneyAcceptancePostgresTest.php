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
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativePinClock;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
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
    private array $wireHistory = [];

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

    private function confirmedSession(): array
    {
        [$rights, $project, $session, $catalog, $headers, $count] = $this->generatedSession();
        $url = $this->url($project, $session);
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

    private function generatedSession(): array
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
        $catalog = $this->catalog();
        $headers = $this->headers($rights->member);
        $created = $this->postJson('/api/v1/admin/projects/'.$project->id.'/estimate-generation/sessions', [
            'request_id' => (string) Str::uuid(), 'description' => 'Монтаж оборудования по ведомости на промышленной площадке. Технология требует отдельной проверки.',
            'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal', 'profile_id' => 'construction',
            'construction_type' => 'new_construction', 'price_policy' => 'normative', 'selected_sections' => ['equipment'],
            'estimate_regional_price_version_id' => $catalog['version_id'], 'normative_dataset_version' => $catalog['dataset_key'],
        ], $headers);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $created = $created->json('data');
        $session = EstimateGenerationSession::query()->findOrFail($created['id']);
        $url = $this->url($project, $session);
        $this->postJson($url.'/evaluation/pricing-source', ['request_id' => (string) Str::uuid(), 'state_version' => $session->state_version,
            'estimate_regional_price_version_id' => $catalog['version_id'], 'conditions' => ['as_of_date' => '2026-10-01',
                'vat_mode' => 'excluded', 'vat_rate' => '22', 'delivery' => 'outside_scope', 'basis' => 'Синтетическая цена для сквозной проверки расчёта; поставка вне сметы.']], $headers)->assertOk();
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
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame('input_review_required', $session->fresh()->status->value);
        $this->postJson($url.'/confirm-input', ['state_version' => $session->fresh()->state_version], $headers)->assertOk();
        self::assertSame('ready_to_generate', $session->fresh()->status->value);
        $this->transport($session);
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
            $job->handle(app(RunEstimateGenerationDraft::class));
        }
        $session->refresh();
        self::assertSame(9, DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)
            ->where('generation_attempt_id', $operation)->where('status', 'completed')->count());
        self::assertCount(2, $this->wireHistory);

        return [$rights, $project, $session, $catalog, $headers, $count];
    }

    private function transport(EstimateGenerationSession $session): void
    {
        $descriptor = json_decode(file_get_contents(base_path('tests/Fixtures/EstimateGeneration/universal-replay/xlsx-floor-finishing-v1.json')), true, 64, JSON_THROW_ON_ERROR);
        $responses = $descriptor['recordings'];
        $handler = new MockHandler([function (RequestInterface $request) use ($responses, $session): Response {
            $wire = json_decode((string) $request->getBody(), true, 128, JSON_THROW_ON_ERROR);
            $project = null;
            foreach ($wire['messages'] as $message) {
                if ($message['role'] === 'user') {
                    $project = json_decode($message['content'], true, 128, JSON_THROW_ON_ERROR)['project'] ?? null;
                }
            }
            self::assertIsArray($project);
            self::assertSame((int) $session->id, $project['scope']['session_id']);
            $counts = array_values(array_filter($project['facts'], static fn (array $fact): bool => $fact['type'] === 'equipment_count'));
            $specifications = array_values(array_filter($project['facts'], static fn (array $fact): bool => $fact['type'] === 'equipment_specification'));
            self::assertCount(1, $counts);
            self::assertCount(1, $specifications);
            $quantities = array_values(array_filter($project['derived_quantities'], static fn (array $quantity): bool =>
                ($quantity['formula_key'] ?? null) === 'equipment_count' && ($quantity['entity_id'] ?? null) === $counts[0]['entity_id']
                && ($quantity['operand_fact_ids'] ?? []) === [$counts[0]['id']] && ($quantity['review_blockers'] ?? []) === []));
            self::assertCount(1, $quantities);
            $intent = ['kind' => 'supplementary', 'work_key' => 'equipment.install', 'name' => 'Монтаж насоса по ведомости',
                'derived_quantity_id' => $quantities[0]['id'], 'source_fact_ids' => [$specifications[0]['id'], $counts[0]['id']],
                'entity_id' => $counts[0]['entity_id'], 'technology_package_candidate' => null, 'unit' => 'count', 'work_section' => 'equipment',
                'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
            $response = $responses[0]['response'];
            $response['choices'][0]['message']['content'] = json_encode(['work_intents' => [$intent]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($response, JSON_THROW_ON_ERROR));
        }, new Response(200, ['Content-Type' => 'application/json'], json_encode($responses[1]['response'], JSON_THROW_ON_ERROR))]);
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

    private function installation(EstimateGenerationSession $session): array
    {
        $rows = [];
        foreach ($session->draft_payload['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (($item['composition_intent']['work_key'] ?? null) === 'equipment.install') {
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
