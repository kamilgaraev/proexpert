<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SeekableDocumentSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StoredDocumentArtifact;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Export\EstimateGenerationCsvExporter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Export\ReadConfirmedEvaluationExport;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationRequirementDecision;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CanonicalWorkItemQuantityBinder;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class NativeEquipmentMoneyPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_native_count_scaled_norm_and_resource_money_survive_confirmation_apply_and_ordinary_recalculation(): void
    {
        [$actor, $session, $catalog, $source] = $this->fixture();
        $workKey = $this->installation($session)['key'];
        app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
            $actor, $session->fresh()->state_version, $workKey, $catalog['norm_id'], true);
        $revision = $this->prepareInstallationScope($actor, $session, $source, $workKey);
        $item = $this->installation($session);
        self::assertSame('3', $item['quantity']);
        self::assertSame('count', $item['unit']);
        self::assertSame('100 шт', $item['normative_match']['unit']);
        $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal($item['unit'], $item['normative_match']['unit']);
        self::assertSame('0.01', $factor);
        self::assertSame('0.03', (string) BigDecimal::of($item['quantity'])->multipliedBy($factor)->strippedOfTrailingZeros());
        self::assertSame($source->id, $item['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        self::assertSame('0.3', $item['labor'][0]['quantity']);
        self::assertSame('10', $item['labor'][0]['quantity_per_unit']);
        self::assertSame('0.1', (string) BigDecimal::of($item['labor'][0]['quantity_per_work_unit'])->strippedOfTrailingZeros());
        self::assertSame([], $item['materials']);
        self::assertSame([], $item['machinery']);
        self::assertSame('30.00', $item['total_cost'], json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertSame('30.00', $revision['result']['scenarios'][0]['known_subtotals']['RUB']);
        self::assertSame('36.60', $revision['result']['scenarios'][0]['gross_subtotals']['RUB']);
        self::assertSame($catalog['norm_id'], $item['normative_match']['norm_id']);
        self::assertSame($catalog['context']['normative_dataset_version'], $item['normative_match']['dataset_version']['version_key']);
        self::assertSame($catalog['resource_id'], $item['labor'][0]['normative_ref']['norm_resource_id']);
        self::assertSame($catalog['price_id'], $item['labor'][0]['normative_ref']['price_id']);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode($revision['result']['missing_requirements'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $this->confirmationInput($session, $revision));
        $published = app(ApplyGeneratedEstimate::class)->handle(new ApplyGeneratedEstimateCommand((int) $session->id,
            (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor));
        $estimate = Estimate::query()->findOrFail($published->estimateId);
        $this->assertPersistedMoney($estimate, $source, $catalog);
        app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
        $this->assertPersistedMoney($estimate->fresh(), $source, $catalog);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public function test_scaled_fractional_labor_and_four_decimal_price_keep_money_through_export_apply_and_recalculation(): void
    {
        [$actor, $session, $catalog, $source] = $this->fixture(counts: ['equipment:boiler' => '10000'],
            laborRate: '1.111111', laborPrice: '123456.7891');
        $workKey = $this->installation($session)['key'];
        app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
            $actor, $session->fresh()->state_version, $workKey, $catalog['norm_id'], true);
        $revision = $this->prepareInstallationScope($actor, $session, $source, $workKey);
        $item = $this->installation($session);
        self::assertSame('10000', $item['quantity']);
        self::assertSame('count', $item['unit']);
        self::assertSame('100 шт', $item['normative_match']['unit']);
        self::assertSame('0.01', NormativeUnitNormalizer::safeQuantityFactorDecimal($item['unit'], $item['normative_match']['unit']));
        self::assertSame('100', (string) BigDecimal::of($item['quantity'])->multipliedBy('0.01')->strippedOfTrailingZeros());
        self::assertSame($source->id, $item['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        self::assertSame('1.111111', (string) BigDecimal::of($item['labor'][0]['quantity_per_unit'])->strippedOfTrailingZeros());
        self::assertSame('0.01111111', (string) BigDecimal::of($item['labor'][0]['quantity_per_work_unit'])->strippedOfTrailingZeros());
        self::assertSame('111.1111', (string) BigDecimal::of($item['labor'][0]['quantity'])->strippedOfTrailingZeros());
        self::assertSame('123456.7891', (string) BigDecimal::of($item['labor'][0]['unit_price'])->strippedOfTrailingZeros());
        self::assertSame('13717419.64', $item['total_cost']);
        self::assertSame('13717419.64', $revision['result']['scenarios'][0]['known_subtotals']['RUB']);
        self::assertSame('16735251.96', $revision['result']['scenarios'][0]['gross_subtotals']['RUB']);
        self::assertTrue($revision['result']['can_confirm_for_apply']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $this->confirmationInput($session, $revision));
        $export = app(ReadConfirmedEvaluationExport::class)->read($actor, (int) $session->project_id, (int) $session->id);
        $json = json_decode(json_encode($export->draft_payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $exportItems = $json['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(1, $exportItems);
        self::assertSame('10000', $exportItems[0]['quantity']);
        self::assertSame('13717419.64', $exportItems[0]['total_cost']);
        self::assertCount(1, $exportItems[0]['labor']);
        $exportLabor = $exportItems[0]['labor'][0];
        self::assertSame('1.111111', (string) BigDecimal::of($exportLabor['quantity_per_unit'])->strippedOfTrailingZeros());
        self::assertSame('0.01111111', (string) BigDecimal::of($exportLabor['quantity_per_work_unit'])->strippedOfTrailingZeros());
        self::assertSame('111.1111', (string) BigDecimal::of($exportLabor['quantity'])->strippedOfTrailingZeros());
        self::assertSame('123456.7891', (string) BigDecimal::of($exportLabor['unit_price'])->strippedOfTrailingZeros());
        self::assertSame('h', $exportLabor['unit']);
        self::assertSame($catalog['norm_id'], $exportLabor['normative_ref']['norm_id']);
        self::assertSame($catalog['resource_id'], $exportLabor['normative_ref']['norm_resource_id']);
        self::assertSame($catalog['price_id'], $exportLabor['normative_ref']['price_id']);
        self::assertSame($source->id, $exportItems[0]['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        ob_start();
        try {
            app(EstimateGenerationCsvExporter::class)->stream($export->draft_payload);
            $content = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $csv = fopen('php://temp', 'w+');
        try {
            fwrite($csv, $content);
            rewind($csv);
            self::assertCount(7, fgetcsv($csv, escape: ''));
            $row = fgetcsv($csv, escape: '');
            self::assertIsArray($row);
            self::assertCount(7, $row);
            self::assertSame('10000', $row[4]);
            self::assertSame('13717419.64', $row[5]);
            self::assertFalse(fgetcsv($csv, escape: ''));
        } finally {
            fclose($csv);
        }
        $published = app(ApplyGeneratedEstimate::class)->handle(new ApplyGeneratedEstimateCommand((int) $session->id,
            (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor));
        $estimate = Estimate::query()->findOrFail($published->estimateId);
        $assertPersisted = static function (Estimate $estimate) use ($source, $catalog): array {
            self::assertSame('13717419.64', $estimate->total_amount);
            self::assertSame('16735251.96', $estimate->total_amount_with_vat);
            $parent = $estimate->items()->whereNull('parent_work_id')->sole();
            $child = $estimate->items()->where('parent_work_id', $parent->id)->sole();
            $resource = $parent->resources()->sole();
            self::assertSame('10000', (string) BigDecimal::of($parent->quantity)->strippedOfTrailingZeros());
            self::assertSame('111.1111', (string) BigDecimal::of($parent->labor_hours)->strippedOfTrailingZeros());
            self::assertSame('0', (string) BigDecimal::of($parent->machinery_hours)->strippedOfTrailingZeros());
            self::assertSame('13717419.64', $parent->total_amount);
            self::assertSame('111.1111', (string) BigDecimal::of($child->quantity)->strippedOfTrailingZeros());
            self::assertSame('13717419.64', $child->total_amount);
            self::assertSame('0.01111111', (string) BigDecimal::of($child->metadata['quantity_per_unit'])->strippedOfTrailingZeros());
            self::assertSame('0.01111111', (string) BigDecimal::of($resource->quantity_per_unit)->strippedOfTrailingZeros());
            self::assertSame('111.1111', (string) BigDecimal::of($resource->total_quantity)->strippedOfTrailingZeros());
            self::assertSame('123456.7891', (string) BigDecimal::of($resource->unit_price)->strippedOfTrailingZeros());
            self::assertSame('13717419.64', $resource->total_amount);
            self::assertSame($catalog['norm_id'], $child->metadata['normative_ref']['norm_id']);
            self::assertSame($catalog['resource_id'], $child->metadata['normative_ref']['norm_resource_id']);
            self::assertSame($catalog['price_id'], $child->metadata['normative_ref']['price_id']);
            self::assertSame('3017832.32', $parent->metadata['generated_source_money']['money']['vat_amount']);
            self::assertSame($source->id, $parent->metadata['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);

            return [$parent->id, $child->id, $resource->id];
        };
        $identities = $assertPersisted($estimate);
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            self::assertSame($identities, $assertPersisted($estimate->fresh()));
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public function test_three_identically_named_entities_keep_distinct_native_counts_and_money_through_apply_and_recalculation(): void
    {
        [$actor, $session, $catalog, , $sources] = $this->fixture(counts: [
            'equipment:boiler-a' => '3', 'equipment:boiler-b' => '7', 'equipment:boiler-c' => '3',
        ]);
        $rows = $this->installations($session);
        self::assertCount(3, $rows);
        self::assertCount(3, array_unique(array_column($rows, 'key')));
        self::assertCount(1, array_unique(array_column($rows, 'name')));
        $workKeys = array_column($rows, 'key');
        foreach ($workKeys as $workKey) {
            app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                $actor, $session->fresh()->state_version, $workKey, $catalog['norm_id'], true);
        }
        $revision = $this->prepareInstallationScope($actor, $session, $sources, $workKeys);
        $rows = $this->installations($session);
        self::assertCount(3, $rows);
        self::assertEqualsCanonicalizing($workKeys, array_column($rows, 'key'));
        $byEntity = [];
        foreach ($rows as $row) {
            $byEntity[$row['metadata']['scope_entity_id']] = $row;
        }
        $oracle = [
            ['3', '0.03', '0.3', '30.00', '6.60'],
            ['7', '0.07', '0.7', '70.00', '15.40'],
            ['3', '0.03', '0.3', '30.00', '6.60'],
        ];
        foreach ($sources as $index => $source) {
            [$count, $normQuantity, $hours, $net] = $oracle[$index];
            $item = $byEntity[$source->entityId];
            self::assertSame($count, $item['quantity']);
            self::assertSame('count', $item['unit']);
            self::assertSame('100 шт', $item['normative_match']['unit']);
            self::assertSame('0.01', NormativeUnitNormalizer::safeQuantityFactorDecimal($item['unit'], $item['normative_match']['unit']));
            self::assertSame($normQuantity, (string) BigDecimal::of($item['quantity'])->multipliedBy('0.01')->strippedOfTrailingZeros());
            $operand = $item['quantity_evidence']['formula_inputs']['operands'][0];
            self::assertSame($source->entityId, $item['quantity_evidence']['formula_inputs']['entity_id']);
            self::assertSame($source->id, $operand['fact_id']);
            self::assertSame($source->sourceVersion, $operand['source_version']);
            self::assertSame($source->evidenceIds, $operand['evidence_ids']);
            self::assertTrue($operand['current']);
            self::assertSame($hours, $item['labor'][0]['quantity']);
            self::assertSame('10', $item['labor'][0]['quantity_per_unit']);
            self::assertSame($net, $item['total_cost']);
            self::assertSame($catalog['norm_id'], $item['normative_match']['norm_id']);
            self::assertSame($catalog['context']['normative_dataset_version'], $item['normative_match']['dataset_version']['version_key']);
            self::assertSame([], $item['materials']);
            self::assertSame([], $item['machinery']);
        }
        self::assertSame('130.00', $revision['result']['scenarios'][0]['known_subtotals']['RUB']);
        self::assertSame('158.60', $revision['result']['scenarios'][0]['gross_subtotals']['RUB']);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode($revision['result']['missing_requirements'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $this->confirmationInput($session, $revision));
        $published = app(ApplyGeneratedEstimate::class)->handle(new ApplyGeneratedEstimateCommand((int) $session->id,
            (int) $session->organization_id, (int) $session->project_id, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor));
        $estimate = Estimate::query()->findOrFail($published->estimateId);
        $persistedIds = $this->assertPersistedEquipmentRows($estimate, $sources, $catalog, $oracle);
        app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
        self::assertSame($persistedIds, $this->assertPersistedEquipmentRows($estimate->fresh(), $sources, $catalog, $oracle));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    #[DataProvider('missingCatalogInputs')]
    public function test_missing_norm_or_price_cannot_confirm_native_count_money(bool $withNorm, bool $withPrice): void
    {
        [$actor, $session, $catalog, $source] = $this->fixture($withNorm, $withPrice);
        $workKey = $this->installation($session)['key'];
        $this->prepareInstallationScope($actor, $session, $source, $workKey);
        if ($withNorm) {
            try {
                app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                    $actor, $session->fresh()->state_version, $workKey, $catalog['norm_id'], true);
                self::fail('A norm without a priced resource was selected.');
            } catch (ValidationException) {
                self::assertNull($this->installation($session)['total_cost']);
            }
        }
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid());
        self::assertSame([], $revision['result']['coverage']['missing_requirements']);
        self::assertFalse($revision['result']['can_confirm_for_apply']);
        self::assertNull($this->installation($session)['total_cost']);
        try {
            app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                $this->confirmationInput($session, $revision));
            self::fail('Missing normative money was confirmed.');
        } catch (ValidationException) {
            self::assertArrayNotHasKey('confirmed_evaluation', $session->fresh()->input_payload);
        }
        $current = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, 100)['snapshot'];
        $original = array_values(array_filter($current->facts, static fn (Fact $fact): bool => $fact->id === $source->id));
        self::assertCount(1, $original);
        self::assertSame('3', $original[0]->value);
        self::assertSame('document', $original[0]->origin);
        self::assertSame(0, Estimate::query()->where('project_id', $session->project_id)->count());
        Http::assertNothingSent();
    }

    public static function missingCatalogInputs(): array
    {
        return ['missing norm' => [false, true], 'missing labor price' => [true, false]];
    }

    private function prepareInstallationScope(User $actor, EstimateGenerationSession $session, Fact|array $sources, string|array $workKeys): array
    {
        $sources = $sources instanceof Fact ? [$sources] : $sources;
        $workKeys = is_string($workKeys) ? [$workKeys] : $workKeys;
        app(SaveEvaluationScopeSelection::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'selected_sections' => ['equipment'], 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'normative',
                'selected_work_item_keys' => $workKeys, 'artifact_hash' => $session->fresh()->draft_payload['artifact_hash'],
                'scope_reason' => 'Проверяется только монтаж по явной спецификации оборудования.']);
        app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid());
        foreach ($sources as $source) {
            $target = ['state_version' => $session->fresh()->state_version, 'entity_id' => $source->entityId,
                'section' => 'equipment', 'requirement' => 'equipment_specification'];
            $documentSources = app(GetEvaluationRequirementSources::class)->handle($actor, (int) $session->project_id, (int) $session->id, $target);
            self::assertCount(1, $documentSources['sources']);
            app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                [...$target, 'request_id' => (string) Str::uuid(), 'disposition' => 'document_verified',
                    'document_fact_id' => $documentSources['sources'][0]['fact_id'],
                    'reason' => 'Проверена текущая спецификация котла и её применимость к выбранному монтажу.']);
            foreach (['equipment_purchase', 'delivery', 'commissioning'] as $requirement) {
                app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                    ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                        'entity_id' => $source->entityId, 'section' => 'equipment', 'requirement' => $requirement,
                        'disposition' => 'not_applicable', 'reason' => 'Проверяется только монтаж оборудования заказчика; этот этап вне состава сметы.']);
            }
        }
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            $session->fresh()->state_version, (string) Str::uuid());

        return $revision;
    }

    private function confirmationInput(EstimateGenerationSession $session, array $revision): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true];
    }

    private function assertPersistedMoney(Estimate $estimate, Fact $source, array $catalog): void
    {
        self::assertSame('30.00', $estimate->total_amount);
        self::assertSame('36.60', $estimate->total_amount_with_vat);
        self::assertSame(1, $estimate->items()->whereNull('parent_work_id')->count());
        self::assertSame(1, $estimate->items()->whereNotNull('parent_work_id')->count());
        $parent = $estimate->items()->whereNull('parent_work_id')->sole();
        $child = $estimate->items()->whereNotNull('parent_work_id')->sole();
        self::assertSame('3', (string) BigDecimal::of($parent->quantity)->strippedOfTrailingZeros());
        self::assertSame('0.3', (string) BigDecimal::of($parent->labor_hours)->strippedOfTrailingZeros());
        self::assertSame('0', (string) BigDecimal::of($parent->machinery_hours)->strippedOfTrailingZeros());
        self::assertSame('30.00', $parent->total_amount);
        self::assertSame('0.3', (string) BigDecimal::of($child->quantity)->strippedOfTrailingZeros());
        self::assertSame('30.00', $child->total_amount);
        self::assertSame('0.1', (string) BigDecimal::of($child->metadata['quantity_per_unit'])->strippedOfTrailingZeros());
        self::assertSame($catalog['norm_id'], $child->metadata['normative_ref']['norm_id']);
        self::assertSame($catalog['resource_id'], $child->metadata['normative_ref']['norm_resource_id']);
        self::assertSame($catalog['price_id'], $child->metadata['normative_ref']['price_id']);
        $resource = $parent->resources()->sole();
        self::assertSame('0.1', (string) BigDecimal::of($resource->quantity_per_unit)->strippedOfTrailingZeros());
        self::assertSame('0.3', (string) BigDecimal::of($resource->total_quantity)->strippedOfTrailingZeros());
        self::assertSame('100', (string) BigDecimal::of($resource->unit_price)->strippedOfTrailingZeros());
        self::assertSame('30.00', $resource->total_amount);
        self::assertSame('22', $parent->metadata['generated_source_money']['money']['vat_rate']);
        self::assertSame('6.60', $parent->metadata['generated_source_money']['money']['vat_amount']);
        self::assertSame($source->id, $parent->metadata['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
    }

    private function assertPersistedEquipmentRows(Estimate $estimate, array $sources, array $catalog, array $oracle): array
    {
        self::assertSame('130.00', $estimate->total_amount);
        self::assertSame('158.60', $estimate->total_amount_with_vat);
        $parents = $estimate->items()->whereNull('parent_work_id')->get();
        self::assertCount(3, $parents);
        self::assertSame(3, $estimate->items()->whereNotNull('parent_work_id')->count());
        $byFact = $parents->keyBy(static fn ($item): string => $item->metadata['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
        self::assertCount(3, $byFact);
        $totalHours = BigDecimal::zero();
        $totalVat = BigDecimal::zero();
        $identities = [];
        foreach ($sources as $index => $source) {
            [$count, , $hours, $net, $vat] = $oracle[$index];
            $parent = $byFact->get($source->id);
            self::assertNotNull($parent);
            $child = $estimate->items()->where('parent_work_id', $parent->id)->sole();
            $resource = $parent->resources()->sole();
            self::assertSame($count, (string) BigDecimal::of($parent->quantity)->strippedOfTrailingZeros());
            self::assertSame($hours, (string) BigDecimal::of($parent->labor_hours)->strippedOfTrailingZeros());
            self::assertSame('0', (string) BigDecimal::of($parent->machinery_hours)->strippedOfTrailingZeros());
            self::assertSame($net, $parent->total_amount);
            $operand = $parent->metadata['quantity_evidence']['formula_inputs']['operands'][0];
            self::assertSame($source->entityId, $parent->metadata['quantity_evidence']['formula_inputs']['entity_id']);
            self::assertSame($source->sourceVersion, $operand['source_version']);
            self::assertSame($source->evidenceIds, $operand['evidence_ids']);
            self::assertTrue($operand['current']);
            self::assertSame($hours, (string) BigDecimal::of($child->quantity)->strippedOfTrailingZeros());
            self::assertSame($net, $child->total_amount);
            self::assertSame('0.1', (string) BigDecimal::of($child->metadata['quantity_per_unit'])->strippedOfTrailingZeros());
            self::assertSame($catalog['norm_id'], $child->metadata['normative_ref']['norm_id']);
            self::assertSame($catalog['resource_id'], $child->metadata['normative_ref']['norm_resource_id']);
            self::assertSame($catalog['price_id'], $child->metadata['normative_ref']['price_id']);
            self::assertSame('0.1', (string) BigDecimal::of($resource->quantity_per_unit)->strippedOfTrailingZeros());
            self::assertSame($hours, (string) BigDecimal::of($resource->total_quantity)->strippedOfTrailingZeros());
            self::assertSame('100', (string) BigDecimal::of($resource->unit_price)->strippedOfTrailingZeros());
            self::assertSame($net, $resource->total_amount);
            self::assertSame('22', $parent->metadata['generated_source_money']['money']['vat_rate']);
            self::assertSame($vat, $parent->metadata['generated_source_money']['money']['vat_amount']);
            $totalHours = $totalHours->plus($parent->labor_hours);
            $totalVat = $totalVat->plus($vat);
            $identities[$source->id] = [$parent->id, $child->id, $resource->id];
        }
        self::assertSame('1.3', (string) $totalHours->strippedOfTrailingZeros());
        self::assertSame('28.60', (string) $totalVat->toScale(2));

        return $identities;
    }

    private function fixture(bool $withNorm = true, bool $withPrice = true, array $counts = ['equipment:boiler' => '3'],
        string $laborRate = '10.000000', string $laborPrice = '100.0000'): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array', 'estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        foreach ([['Штука', 'шт', 'piece'], ['Час', 'ч', 'time']] as [$name, $unit, $type]) {
            MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => $name, 'short_name' => $unit, 'type' => $type]);
        }
        $catalog = $this->catalog($withNorm, $withPrice, $laborRate, $laborPrice);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0,
            'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal',
                'selected_sections' => ['equipment'], 'profile_id' => 'construction', 'construction_type' => 'new_construction',
                'price_policy' => 'normative', 'regional_context' => $catalog['context']]]);
        app(SaveSessionPricingSource::class)->handle($actor, (int) $project->id, (int) $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => 0,
                'estimate_regional_price_version_id' => $catalog['context']['estimate_regional_price_version_id'],
                'conditions' => ['as_of_date' => '2026-10-01', 'vat_mode' => 'excluded', 'vat_rate' => '22',
                    'delivery' => 'outside_scope', 'basis' => 'Синтетическая проверка числового пересчёта; доставка вне выбранного состава.']]);
        $sources = $this->publishCounts($session, $counts);
        $preview = app(CurrentProjectDerivedQuantityService::class)->previewInput((int) $organization->id, (int) $project->id, (int) $session->id);
        $quantities = [];
        foreach ($sources as $source) {
            $quantity = (new WorkItemQuantityMapper)->mapForEntity('equipment.install', $preview['quantities'], $source->entityId);
            self::assertNotNull($quantity);
            self::assertSame($source->value, $quantity->amount);
            $quantities[] = $quantity->toArray();
        }
        $local = ['key' => 'equipment', 'scope_type' => 'engineering', 'title' => 'Оборудование'];
        $section = ['key' => 'equipment', 'title' => 'Оборудование', 'construction_part' => 'equipment'];
        $analysis = ['object' => ['object_type' => 'residential'], 'regional_context' => $catalog['context'],
            'evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['equipment']],
            'document_context' => ['canonical_building_quantities' => $quantities]];
        $items = app(NormativeWorkItemPlannerService::class)->build($local, $section, $analysis, projectReferences: []);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $draft = $this->emptyDraft($session, $catalog);
        $draft['local_estimates'] = [[...$local, 'sections' => [[...$section, 'work_items' => $items]]]];
        $draft = (new EquipmentWorkItemExpander)->expand($draft, $preview['quantities'], 50, EquipmentEstimateScope::select($snapshot, $decisions));
        $items = array_map(fn (array $row): array => (new CanonicalWorkItemQuantityBinder)->bind($row, $preview['quantities']),
            $draft['local_estimates'][0]['sections'][0]['work_items']);
        $draft['local_estimates'][0]['sections'][0]['work_items'] = $items;
        $installation = array_values(array_filter($items, static fn (array $row): bool => ($row['metadata']['requirement_key'] ?? null) === 'installation'));
        self::assertCount(count($sources), $installation);
        $byEntity = array_column($sources, null, 'entityId');
        foreach ($installation as $item) {
            $source = $byEntity[$item['metadata']['scope_entity_id']];
            self::assertSame($source->value, $item['quantity']);
            self::assertSame($source->entityId, $item['metadata']['scope_entity_id']);
            self::assertSame($source->id, $item['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
            self::assertArrayNotHasKey('specialization_scenario', $item);
            self::assertArrayNotHasKey('work_intent', $item);
        }
        $session->refresh()->update(['status' => 'estimate_review_required',
            'draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);

        return [$actor, $session, $catalog, $sources[0], $sources];
    }

    private function emptyDraft(EstimateGenerationSession $session, array $catalog): array
    {
        return ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'regional_context' => $catalog['context'], 'object_profile' => ['object_type' => 'residential'],
            'evaluation_policy' => ['selected_sections' => ['equipment'], 'price_policy' => 'normative']];
    }

    private function installation(EstimateGenerationSession $session): array
    {
        $installation = $this->installations($session);
        self::assertCount(1, $installation);

        return $installation[0];
    }

    private function installations(EstimateGenerationSession $session): array
    {
        $rows = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'];
        return array_values(array_filter($rows,
            static fn (array $row): bool => ($row['metadata']['requirement_key'] ?? null) === 'installation'));
    }

    private function publishCounts(EstimateGenerationSession $session, array $counts): array
    {
        $rows = [['entity_key', 'parameter', 'value', 'unit']];
        $expected = [];
        foreach ($counts as $entity => $count) {
            $rows[] = [$entity, 'equipment_specification', 'Электрический котёл отопления массой до 30 кг.', ''];
            $rows[] = [$entity, 'equipment_count', $count, 'шт'];
            $expected['xlsx:sheet:Оборудование!C'.count($rows)] = ['entity_key' => $entity, 'count' => $count];
        }
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Оборудование')->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'most-equipment-money-');
        self::assertIsString($path);
        try {
            (new Xlsx($spreadsheet))->save($path);
            $version = 'sha256:'.hash_file('sha256', $path);
            $document = EstimateGenerationDocument::query()->create(['organization_id' => $session->organization_id,
                'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $session->user_id,
                'filename' => 'synthetic-scaled-unit2026Q4.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'storage_path' => 'org-'.$session->organization_id.'/estimate-generation/native-money/source.xlsx',
                'source_version' => $version, 'checksum_sha256' => substr($version, 7)]);
            $storage = new class($path) implements DocumentSourceManifestStorage, DocumentUnitContentReader
            {
                private array $artifacts = [];

                public function __construct(private readonly string $sourcePath) {}

                public function open(EstimateGenerationDocument $document, string $sourceVersion): SeekableDocumentSource
                {
                    if ($sourceVersion !== 'sha256:'.hash_file('sha256', $this->sourcePath)) {
                        throw new \LogicException('Source digest mismatch.');
                    }

                    return new SeekableDocumentSource(fopen($this->sourcePath, 'rb'), filesize($this->sourcePath));
                }

                public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content, string $contentType = 'text/plain'): StoredDocumentArtifact
                {
                    $hash = 'sha256:'.hash('sha256', $content);
                    $key = 'org-'.$document->organization_id.'/estimate-generation/native-money/'.substr($hash, 7);
                    $this->artifacts[$key] = $content;

                    return new StoredDocumentArtifact($key, strlen($content), $hash, $contentType);
                }

                public function read(DocumentUnitExecutionContext $context): string
                {
                    $key = $context->locator['artifact_path'];
                    $content = $this->artifacts[$key];
                    if (! str_starts_with($key, 'org-'.$context->organizationId.'/')
                        || 'sha256:'.hash('sha256', $content) !== $context->locator['artifact_sha256']
                        || strlen($content) !== $context->locator['artifact_bytes']) {
                        throw new \LogicException('Artifact scope or integrity mismatch.');
                    }

                    return $content;
                }
            };
            $this->app->instance(DocumentSourceManifestStorage::class, $storage);
            $this->app->instance(DocumentUnitContentReader::class, $storage);
            $units = app(DocumentUnitDetector::class)->detect($document, $version);
            self::assertCount(1, $units);
            self::assertSame(DocumentUnitType::SpreadsheetSheet, $units[0]->type);
            $store = app(DocumentProcessingUnitStore::class);
            $unit = $store->create((int) $session->organization_id, (int) $session->project_id, (int) $session->id,
                (int) $document->id, $units[0]);
            self::assertSame(DocumentProcessingUnitClaimStatus::Acquired, app(ProcessDocumentUnit::class)->handle($unit->id, $version)->status);
            self::assertSame('completed', $store->find($unit->id)->status->value);
            $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id,
                (int) $session->project_id, (int) $session->id, 100)['snapshot'];
            $facts = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_count'));
            self::assertCount(count($counts), $facts);
            self::assertCount(count($counts), array_unique(array_column($facts, 'entityId')));
            $entities = array_column($snapshot->entities, null, 'id');
            $byReference = [];
            foreach ($facts as $source) {
                self::assertSame([(int) $session->organization_id, (int) $session->project_id, (int) $session->id, $version],
                    [$source->organizationId, $source->projectId, $source->sessionId, $source->sourceVersion]);
                self::assertSame('шт', $source->unit);
                self::assertSame('document', $source->origin);
                self::assertSame('confirmed', $source->status);
                $proofs = array_values(array_filter($snapshot->evidence, static fn ($evidence): bool => in_array($evidence->id, $source->evidenceIds, true)));
                self::assertCount(1, $proofs);
                self::assertTrue((new NativeNumericFactProof)->matches($source, $proofs[0]));
                self::assertArrayHasKey($proofs[0]->nativeReference, $expected);
                self::assertSame($expected[$proofs[0]->nativeReference]['count'], $source->value);
                self::assertSame($expected[$proofs[0]->nativeReference]['entity_key'],
                    $entities[$source->entityId]->attributes['properties']['source_entity_key']);
                $byReference[$proofs[0]->nativeReference] = $source;
            }

            return array_map(static fn (string $reference): Fact => $byReference[$reference], array_keys($expected));
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }

    private function catalog(bool $withNorm, bool $withPrice, string $laborRate, string $laborPrice): array
    {
        $now = now();
        $suffix = strtolower((string) Str::ulid());
        $key = 'synthetic-scaled-unit2026Q4-'.$suffix;
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices',
            'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => 'SYNTHETIC-'.$suffix,
            'name' => 'Synthetic scaled-unit numeric fixture 2026 Q4', 'norm_type' => 'gesn', 'source_file' => 'synthetic-scaled-unit2026Q4', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '20', 'name' => 'Synthetic equipment',
            'path' => '20/synthetic', 'created_at' => $now, 'updated_at' => $now]);
        $norm = $withNorm ? DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '20-99-999-01',
            'section_code' => '20', 'section_name' => 'Synthetic equipment', 'section_id' => $section,
            'name' => 'Монтаж оборудования по требованиям изготовителя',
            'work_composition' => json_encode(['Установка и крепление оборудования по согласованной спецификации'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'unit' => '100 шт', 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now]) : null;
        $resourceCode = 'R08-LABOR-'.$suffix;
        $resource = $withNorm ? DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $norm,
            'resource_code' => $resourceCode, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => $laborRate,
            'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]) : null;
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'R08-'.substr($suffix, -10), 'name' => 'Synthetic region',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Synthetic zone',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2026)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => 'Synthetic 2026 Q4',
                'year' => 2026, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $price = $withPrice ? DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $prices,
            'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'resource_code' => $resourceCode, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => $laborPrice,
            'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]) : null;
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'resource_id' => $resource, 'price_id' => $price, 'context' => ['region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'business_date' => '2026-10-01', 'year' => 2026, 'quarter' => 4,
            'version_key' => $key, 'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }
}
