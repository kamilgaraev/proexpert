<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\EstimateContingencyCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateBudgetScope;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateScopeMetadataProjector;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LaravelGeneratedEstimateWriter implements GeneratedEstimateWriter
{
    private const ESTIMATE_NAME_MAX_LENGTH = 255;

    private const ESTIMATE_ITEM_RESOURCE_NAME_MAX_LENGTH = 255;

    private const NUMBER_CREATE_ATTEMPTS = 4;

    /** @var array<string, int|null> */
    private array $measurementUnitIdCache = [];

    /** @var array<int, list<array{id: int, organization_id: int|null, name: string, short_name: string}>> */
    private array $measurementUnitsCache = [];

    public function __construct(
        private EstimateDraftPersistenceService $draftService,
        private GeneratedEstimateNumberAllocator $numberAllocator,
        private GeneratedEstimateItemMetadataFactory $itemMetadata = new GeneratedEstimateItemMetadataFactory,
        private FinalizedPackageDraftProjector $finalizedDraftProjector = new FinalizedPackageDraftProjector,
        private EstimateBudgetScope $budgetScope = new EstimateBudgetScope,
        private EstimateScopeMetadataProjector $scopeMetadata = new EstimateScopeMetadataProjector,
        private BuildMostEstimateDraft $stage6DraftBuilder = new BuildMostEstimateDraft,
        private ?ProjectModelRepository $projectModels = null,
        private ?CurrentDraftQuantityProofGuard $quantityProof = null,
    ) {}

    public function createFromSession(
        EstimateGenerationSession $session,
        ApplyGeneratedEstimateCommand $command,
    ): int {
        if (DB::connection()->transactionLevel() === 0) {
            return DB::transaction(fn (): int => $this->createFromSession($session, $command));
        }
        $universal = ($session->draft_payload['generation_contract'] ?? null) === \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT;
        $sourceDraft = $universal ? ($session->draft_payload ?? []) : $this->draftService->validatedDraft($session);
        if ($universal && ! $this->stage6DraftBuilder->verifyArtifact($sourceDraft)) {
            throw ValidationException::withMessages(['draft' => trans_message('estimate_generation.stage6_artifact_invalid')]);
        }
        if (($sourceDraft['generation_contract'] ?? null) === 'most_ordinary_estimate:v1'
            && ! $this->stage6DraftBuilder->verifyArtifact($sourceDraft)) {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.stage6_artifact_invalid')],
            ]);
        }
        if ($command->artifactHash !== null
            && ! hash_equals($command->artifactHash, (string) ($sourceDraft['artifact_hash'] ?? ''))) {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.stage6_artifact_invalid')],
            ]);
        }
        if (($sourceDraft['generation_contract'] ?? null) === 'most_ordinary_estimate:v1'
            && (! $this->projectModels instanceof ProjectModelRepository
                || ! is_string($sourceDraft['input_snapshot_hash'] ?? null))) {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.stage6_snapshot_stale')],
            ]);
        }
        if (! $universal && $this->projectModels !== null && is_string($sourceDraft['input_snapshot_hash'] ?? null)) {
            $capture = $this->projectModels->snapshotForPlanning(
                (int) $session->organization_id,
                (int) $session->project_id,
                (int) $session->getKey(),
                10001,
            );
            $technology = $this->projectModels->currentTechnologyRecommendations(
                (int) $session->organization_id,
                (int) $session->project_id,
                (int) $session->getKey(),
            );
            $completeness = $this->projectModels->currentCompleteness(
                (int) $session->organization_id,
                (int) $session->project_id,
                (int) $session->getKey(),
            );
            if (! hash_equals($sourceDraft['input_snapshot_hash'], $capture['token'])
                || ! $this->stageFiveProjectionIsCurrent($sourceDraft, $technology, $completeness)) {
                throw ValidationException::withMessages([
                    'draft' => [trans_message('estimate_generation.stage6_snapshot_stale')],
                ]);
            }
        }
        $draft = $universal ? app(ConfirmedEvaluationDraftProjector::class)->project($session, $command)
            : $this->stage6DraftBuilder->build($this->finalizedDraftProjector->project($session, $sourceDraft));
        $blocker = $this->draftService->findApplyBlocker($draft);
        if ($blocker !== null) {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.stage6_draft_incomplete')],
            ]);
        }
        $regionalContext = $draft['regional_context'] ?? $session->input_payload['regional_context'] ?? [];
        if ($this->quantityProof === null) {
            throw ValidationException::withMessages(['draft' => [trans_message('estimate_generation.stage6_snapshot_stale')]]);
        }
        $this->quantityProof->assertCurrent((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $draft);
        $total = $this->draftService->persistableDraftTotal($draft);
        $draft['contingency_amount'] = (new EstimateContingencyCalculator)->amount($draft, (string) $total);
        $total = (string) BigDecimal::of((string) $total)->plus($draft['contingency_amount']);
        $estimate = $this->createEstimate($session, $command, $draft, $regionalContext, $total);

        $this->persistSections($session, $estimate, $draft);
        $this->finalizeGeneratedTotals($estimate, (string) $total);
        if ($universal) {
            $gross = (new \App\BusinessModules\Features\BudgetEstimates\Services\EstimateSourceVatCalculator)->gross($estimate, BigDecimal::of((string) $total));
            if (! $gross->isEqualTo($draft['accepted_payable_total'])) {
                throw new \DomainException('generated_evaluation_payable_total_mismatch');
            }
            $estimate->update(['total_amount_with_vat' => OrdinaryEstimateDecimal::money($gross)]);
        }

        return (int) $estimate->id;
    }

    protected function stageFiveProjectionIsCurrent(array $draft, ?array $technology, ?array $completeness): bool
    {
        if (! is_array($technology) || ! is_array($completeness)
            || ($technology['is_current'] ?? false) !== true
            || ($completeness['is_current'] ?? false) !== true) {
            return false;
        }
        $snapshotHash = (string) ($draft['input_snapshot_hash'] ?? '');
        $sourceVersion = (string) ($draft['scope_identity']['source_version'] ?? '');

        return hash_equals($snapshotHash, (string) ($technology['input_fingerprint'] ?? ''))
            && hash_equals($snapshotHash, (string) ($completeness['input_fingerprint'] ?? ''))
            && hash_equals($sourceVersion, (string) ($technology['source_version'] ?? ''))
            && hash_equals($sourceVersion, (string) ($completeness['source_version'] ?? ''))
            && ($draft['technology_identity']['version'] ?? null) === ($technology['catalog_version'] ?? null)
            && ($draft['technology_identity']['hash'] ?? null) === ($technology['catalog_hash'] ?? null)
            && ($draft['technology_identity']['run_id'] ?? null) === ($technology['run_id'] ?? null)
            && ($draft['rule_identity']['version'] ?? null) === ($completeness['rule_catalog_version'] ?? null)
            && ($draft['rule_identity']['hash'] ?? null) === ($completeness['rule_catalog_hash'] ?? null)
            && ($draft['rule_identity']['run_id'] ?? null) === ($completeness['run_id'] ?? null);
    }

    public function publishedMetadata(
        int $estimateId,
        int $organizationId,
        int $projectId,
    ): ?array {
        $estimate = Estimate::query()
            ->whereKey($estimateId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->first(['metadata']);

        return $estimate !== null && is_array($estimate->metadata)
            ? $estimate->metadata
            : null;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $regionalContext
     */
    protected function createEstimate(
        EstimateGenerationSession $session,
        ApplyGeneratedEstimateCommand $command,
        array $draft,
        array $regionalContext,
        string|int|float $total,
    ): Estimate {
        $total = OrdinaryEstimateDecimal::money($total);
        $vatRate = BigDecimal::of((string) ($draft['vat_rate'] ?? '0'));
        if ($vatRate->isLessThan(0) || $vatRate->isGreaterThan(100)) {
            throw new \DomainException('generated_estimate_vat_rate_invalid');
        }
        $budgetScope = $this->budgetScope->project($draft, $total);

        for ($attempt = 0; $attempt < self::NUMBER_CREATE_ATTEMPTS; $attempt++) {
            try {
                $attributes = [
                    'organization_id' => $session->organization_id,
                    'project_id' => $session->project_id,
                    'number' => $this->numberAllocator->allocate($session, $attempt),
                    'name' => $this->resolveEstimateName($session, $draft, $command->name),
                    'description' => $session->input_payload['description'] ?? null,
                    'type' => $command->type ?? 'local',
                    'status' => 'draft',
                    'estimate_date' => $command->estimateDate ?? now()->toDateString(),
                    'calculation_method' => in_array($draft['evaluation_policy']['price_policy'] ?? 'normative', ['catalog', 'mixed'], true) ? 'analog' : 'resource',
                    'vat_rate' => (string) $vatRate,
                    'overhead_rate' => $draft['overhead_rate'] ?? 0,
                    'profit_rate' => $draft['profit_rate'] ?? 0,
                    'estimate_regional_price_version_id' => $regionalContext['estimate_regional_price_version_id'] ?? null,
                    'regional_price_snapshot' => $regionalContext !== [] ? $regionalContext : null,
                    'metadata' => [
                        'is_ai_generated' => true,
                        'calculation_version' => 'generated-decimal:v1',
                        ...(($draft['generation_contract'] ?? null) === ConfirmedEvaluationDraftProjector::CONTRACT
                            ? ['vat_calculation_method' => \App\BusinessModules\Features\BudgetEstimates\Services\EstimateSourceVatCalculator::METHOD,
                                'source_money_work_keys' => array_merge(...array_map(static fn (array $local): array => array_merge(...array_map(
                                    static fn (array $section): array => array_column($section['work_items'], 'key'), $local['sections'])), $draft['local_estimates'])),
                                'confirmed_evaluation' => $draft['confirmed_evaluation'],
                                ...(isset($draft['source_contingency_money']) ? ['source_contingency_money' => $draft['source_contingency_money'],
                                    'confirmed_contingency' => $draft['confirmed_contingency']] : [])] : []),
                        'generation_session_id' => $session->getKey(),
                        'draft_traceability' => $draft['traceability'] ?? [],
                        'quality_summary' => $draft['quality_summary'] ?? null,
                        'regional_context' => $regionalContext,
                        'generation_contract' => $draft['generation_contract'] ?? null,
                        'generation_artifact_hash' => $draft['artifact_hash'] ?? null,
                        'generation_source_artifact_hash' => $command->artifactHash
                            ?? $draft['artifact_hash']
                            ?? null,
                        'generation_input_snapshot_hash' => $draft['input_snapshot_hash'] ?? null,
                        'generation_idempotency_key' => $command->idempotencyKey
                            ?? 'estimate-generation:'.$session->getKey().':'.($draft['artifact_hash'] ?? ''),
                        'ai_scope' => $this->scopeMetadata->project($draft, $budgetScope),
                    ],
                    'total_direct_costs' => (string) BigDecimal::of((string) $total)->minus($draft['contingency_amount'] ?? '0'),
                    'contingency_amount' => $draft['contingency_amount'] ?? '0.00',
                    'total_amount' => $total,
                    'total_amount_with_vat' => OrdinaryEstimateDecimal::money(BigDecimal::of($total)
                        ->multipliedBy(BigDecimal::one()->plus($vatRate->withPointMovedLeft(2)))),
                ];

                return $this->transactionAttempt(fn (): Estimate => $this->createEstimateAttempt($attributes));
            } catch (QueryException $exception) {
                if (! $this->isEstimateNumberCollision($exception) || $attempt === self::NUMBER_CREATE_ATTEMPTS - 1) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Estimate number allocation attempts exhausted.');
    }

    /** @param array<string, mixed> $attributes */
    protected function createEstimateAttempt(array $attributes): Estimate
    {
        return Estimate::create($attributes);
    }

    private function finalizeGeneratedTotals(Estimate $estimate, string $acceptedTotal): void
    {
        $calculator = new \App\BusinessModules\Features\BudgetEstimates\Services\GeneratedEstimateDecimalCalculation;
        foreach (EstimateItem::query()->where('estimate_id', $estimate->id)->whereNull('parent_work_id')->get() as $item) {
            $calculator->calculate($item, $estimate);
        }
        $totals = EstimateItem::query()->where('estimate_id', $estimate->id)->whereNull('parent_work_id')->where('is_not_accounted', false)
            ->selectRaw('COALESCE(SUM(total_amount),0) AS amount, COALESCE(SUM(equipment_cost),0) AS equipment,
                COALESCE(SUM(overhead_amount),0) AS overhead, COALESCE(SUM(profit_amount),0) AS profit, COALESCE(SUM(other_cost),0) AS other')->first();
        $base = BigDecimal::of((string) $totals->amount);
        if (! $base->plus((string) $estimate->contingency_amount)->isEqualTo($acceptedTotal)) {
            throw new \DomainException('generated_estimate_publication_total_mismatch');
        }
        $equipment = BigDecimal::of((string) $totals->equipment);
        $overhead = BigDecimal::of((string) $totals->overhead);
        $profit = BigDecimal::of((string) $totals->profit);
        $estimate->update(['total_direct_costs' => OrdinaryEstimateDecimal::money($base->minus($equipment)->minus($overhead)->minus($profit)),
            'total_equipment_costs' => OrdinaryEstimateDecimal::money($equipment), 'total_overhead_costs' => OrdinaryEstimateDecimal::money($overhead),
            'total_estimated_profit' => OrdinaryEstimateDecimal::money($profit), 'total_other_costs' => OrdinaryEstimateDecimal::money((string) $totals->other)]);
    }

    protected function transactionAttempt(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    protected function isEstimateNumberCollision(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $constraint = $this->constraintName($exception);

        return $sqlState === '23505'
            && $constraint !== null
            && hash_equals('estimates_organization_id_number_unique', $constraint);
    }

    private function constraintName(QueryException $exception): ?string
    {
        foreach (['constraint', 'constraint_name'] as $key) {
            $value = $exception->errorInfo[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $diagnostics = array_filter(
            [...$exception->errorInfo, (string) $exception->getCode()],
            static fn (mixed $value): bool => is_string($value),
        );

        foreach ($diagnostics as $diagnostic) {
            if (preg_match(
                '/(?:constraint|ограничени(?:е|я)(?:\s+уникальности)?)\s+["«]([^"»]+)["»]/iu',
                $diagnostic,
                $matches,
            ) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $draft */
    private function persistSections(
        EstimateGenerationSession $session,
        Estimate $estimate,
        array $draft,
    ): void {
        $localIndex = 0;
        foreach ($draft['local_estimates'] as $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            $localTotal = $this->draftService->persistableLocalEstimateTotal(
                $localEstimate,
                in_array($draft['generation_contract'] ?? null, ['most_ordinary_estimate:v1', ConfirmedEvaluationDraftProjector::CONTRACT], true),
            );
            if (BigDecimal::of($localTotal)->isLessThanOrEqualTo(BigDecimal::zero())) {
                continue;
            }

            $localIndex++;
            $rootSection = EstimateSection::create([
                'estimate_id' => $estimate->id,
                'section_number' => (string) $localIndex,
                'full_section_number' => (string) $localIndex,
                'name' => $localEstimate['title'],
                'description' => implode('; ', $localEstimate['assumptions'] ?? []),
                'sort_order' => $localIndex - 1,
                'section_total_amount' => $localTotal,
            ]);

            $sectionIndex = 0;
            foreach ($localEstimate['sections'] ?? [] as $section) {
                if (! is_array($section)) {
                    continue;
                }

                $workItems = $this->draftService->persistableWorkItems($section['work_items'] ?? []);
                if ($workItems === []) {
                    continue;
                }

                $sectionIndex++;
                $sectionModel = EstimateSection::create([
                    'estimate_id' => $estimate->id,
                    'parent_section_id' => $rootSection->id,
                    'section_number' => (string) $sectionIndex,
                    'full_section_number' => $localIndex.'.'.$sectionIndex,
                    'name' => $section['title'],
                    'description' => $section['construction_part'] ?? null,
                    'sort_order' => $sectionIndex - 1,
                    'section_total_amount' => $this->draftService->workItemsTotal(
                        $workItems,
                        in_array($draft['generation_contract'] ?? null, ['most_ordinary_estimate:v1', ConfirmedEvaluationDraftProjector::CONTRACT], true),
                    ),
                ]);

                foreach ($workItems as $workIndex => $workItem) {
                    $work = $this->createWorkItem(
                        $session,
                        (int) $estimate->id,
                        (int) $sectionModel->id,
                        $localIndex.'.'.$sectionIndex.'.'.($workIndex + 1),
                        $workItem,
                    );
                    $this->persistResources($session, $work, $workItem['materials'] ?? [], EstimatePositionItemType::MATERIAL->value);
                    $this->persistResources($session, $work, $workItem['labor'] ?? [], EstimatePositionItemType::LABOR->value);
                    $this->persistResources($session, $work, $workItem['machinery'] ?? [], EstimatePositionItemType::MACHINERY->value);
                    $this->persistResources($session, $work, $workItem['other_resources'] ?? [], EstimatePositionItemType::OTHER->value);
                    $this->persistResources($session, $work, $workItem['equipment'] ?? [], EstimatePositionItemType::EQUIPMENT->value);
                }
            }
        }

    }

    /** @param array<string, mixed> $workItem */
    private function createWorkItem(
        EstimateGenerationSession $session,
        int $estimateId,
        int $sectionId,
        string $position,
        array $workItem,
    ): EstimateItem {
        $measurementUnitId = $this->resolveMeasurementUnitId((int) $session->organization_id, $workItem['unit']);
        $unitPrice = OrdinaryEstimateDecimal::unitPrice(BigDecimal::of((string) $workItem['total_cost'])
            ->dividedBy(BigDecimal::of((string) $workItem['quantity']), 12, RoundingMode::HalfUp));
        $metadata = $this->itemMetadata->make($workItem);
        if (($session->input_payload['evaluation_mode'] ?? null) === 'universal'
            && (ConfirmedEvaluationDraftProjector::isPersistableCommercialItem($workItem)
                || ConfirmedEvaluationDraftProjector::isPersistableNormativeItem($workItem))) {
            $source = $workItem['accepted_evaluation_price_snapshot'] ?? $workItem['commercial_price_snapshot'];
            $metadata['source_work_item_key'] = $workItem['key'];
            $metadata['generated_source_money'] = (new \App\BusinessModules\Features\BudgetEstimates\Services\SourceMoneyVatBasis)->seal([
                'organization_id' => (int) $session->organization_id, 'project_id' => (int) $session->project_id,
                'session_id' => (int) $session->id, 'work_item_key' => $workItem['key'], 'measurement_unit_id' => $measurementUnitId,
                'quantity' => OrdinaryEstimateDecimal::quantity($workItem['quantity']), 'stored_unit_price' => $unitPrice,
                'currency' => 'RUB', 'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash'],
                'confirmation' => $workItem['metadata']['evaluation_confirmation'], 'price_snapshot' => $source,
            ], $workItem['accepted_source_money']);
        }

        return EstimateItem::create([
            'estimate_id' => $estimateId,
            'estimate_section_id' => $sectionId,
            'item_type' => in_array($workItem['metadata']['ordinary_item_type'] ?? 'work', ['work', 'material', 'equipment', 'other'], true)
                ? ($workItem['metadata']['ordinary_item_type'] ?? 'work') : 'work',
            'position_number' => $position,
            'name' => $workItem['name'],
            'description' => $workItem['description'],
            'normative_rate_id' => data_get($workItem, 'normative_match.catalog_source') === 'normative_rates'
                ? data_get($workItem, 'normative_match.normative_rate_id')
                : null,
            'normative_rate_code' => $this->draftService->normativeRateCode($workItem),
            'measurement_unit_id' => $measurementUnitId,
            'quantity' => OrdinaryEstimateDecimal::quantity($workItem['quantity']),
            'quantity_total' => OrdinaryEstimateDecimal::quantity($workItem['quantity']),
            'unit_price' => $unitPrice,
            'materials_cost' => $workItem['materials_cost'],
            'machinery_cost' => $workItem['machinery_cost'],
            'labor_cost' => $workItem['labor_cost'],
            'other_cost' => OrdinaryEstimateDecimal::money($workItem['other_cost'] ?? '0'),
            'labor_hours' => $workItem['labor_hours'],
            'machinery_hours' => $workItem['machinery_hours'],
            'direct_costs' => OrdinaryEstimateDecimal::money($workItem['total_cost']),
            'total_amount' => OrdinaryEstimateDecimal::money($workItem['total_cost']),
            'current_total_amount' => OrdinaryEstimateDecimal::money($workItem['total_cost']),
            'justification' => $workItem['quantity_basis'],
            'is_manual' => true,
            'metadata' => $metadata,
        ]);
    }

    /** @param array<int, array<string, mixed>> $resources */
    private function persistResources(
        EstimateGenerationSession $session,
        EstimateItem $parent,
        array $resources,
        string $itemType,
    ): void {
        foreach ($resources as $resourceIndex => $resource) {
            $measurementUnitId = $this->resolveMeasurementUnitId((int) $session->organization_id, $resource['unit']);
            $child = EstimateItem::create([
                'estimate_id' => $parent->estimate_id,
                'estimate_section_id' => $parent->estimate_section_id,
                'parent_work_id' => $parent->id,
                'item_type' => $itemType,
                'position_number' => $parent->position_number.'.'.($resourceIndex + 1),
                'name' => $resource['name'],
                'description' => $resource['source'] ?? null,
                'normative_rate_code' => $resource['normative_ref']['resource_code'] ?? null,
                'measurement_unit_id' => $measurementUnitId,
                'quantity' => OrdinaryEstimateDecimal::quantity($resource['quantity']),
                'quantity_total' => OrdinaryEstimateDecimal::quantity($resource['quantity']),
                'unit_price' => OrdinaryEstimateDecimal::unitPrice($resource['unit_price']),
                'direct_costs' => OrdinaryEstimateDecimal::money($resource['total_price']),
                'materials_cost' => $itemType === EstimatePositionItemType::MATERIAL->value ? OrdinaryEstimateDecimal::money($resource['total_price']) : '0.00',
                'machinery_cost' => $itemType === EstimatePositionItemType::MACHINERY->value ? OrdinaryEstimateDecimal::money($resource['total_price']) : '0.00',
                'labor_cost' => $itemType === EstimatePositionItemType::LABOR->value ? OrdinaryEstimateDecimal::money($resource['total_price']) : '0.00',
                'other_cost' => $itemType === EstimatePositionItemType::OTHER->value ? OrdinaryEstimateDecimal::money($resource['total_price']) : '0.00',
                'equipment_cost' => $itemType === EstimatePositionItemType::EQUIPMENT->value ? OrdinaryEstimateDecimal::money($resource['total_price']) : '0.00',
                'total_amount' => OrdinaryEstimateDecimal::money($resource['total_price']),
                'current_total_amount' => OrdinaryEstimateDecimal::money($resource['total_price']),
                'labor_hours' => $itemType === EstimatePositionItemType::LABOR->value
                    ? $resource['quantity']
                    : 0,
                'machinery_hours' => $itemType === EstimatePositionItemType::MACHINERY->value
                    ? $resource['quantity']
                    : 0,
                'is_manual' => true,
                'metadata' => [
                    'confidence' => $resource['confidence'] ?? null,
                    'quantity_basis' => $resource['quantity_basis'] ?? null,
                    'quantity_per_unit' => $resource['quantity_per_work_unit'] ?? $resource['quantity_per_unit'] ?? null,
                    'precise_unit_price' => (string) ($resource['precise_unit_price'] ?? $resource['unit_price']),
                    'precise_stored_unit_price' => OrdinaryEstimateDecimal::unitPrice($resource['unit_price']),
                    'normative_ref' => $resource['normative_ref'] ?? null,
                    'source' => $resource['source'] ?? null,
                    'price_source' => $resource['price_source'] ?? null,
                    'price_source_version' => $resource['price_source_version'] ?? null,
                    'rounding_adjustment' => $resource['rounding_adjustment'] ?? null,
                    'project_resource_selection' => $resource['project_resource_selection'] ?? null,
                    'project_material_selection' => $resource['project_material_selection'] ?? null,
                    'machine_price_breakdown' => $resource['machine_price_breakdown'] ?? null,
                ],
            ]);

            EstimateItemResource::create([
                'estimate_item_id' => $parent->id,
                'resource_type' => $itemType,
                'finance_representation' => 'child',
                'represented_by_item_id' => $child->id,
                'name' => $this->estimateItemResourceName((string) $resource['name']),
                'description' => $resource['normative_ref']['resource_code'] ?? ($resource['source'] ?? null),
                'measurement_unit_id' => $measurementUnitId,
                'quantity_per_unit' => OrdinaryEstimateDecimal::resourceQuantity($resource['quantity_per_work_unit'] ?? $resource['quantity_per_unit'] ?? 1),
                'total_quantity' => OrdinaryEstimateDecimal::resourceQuantity($resource['precise_quantity'] ?? $resource['quantity']),
                'unit_price' => OrdinaryEstimateDecimal::resourceUnitPrice($resource['precise_unit_price'] ?? $resource['unit_price']),
                'total_amount' => OrdinaryEstimateDecimal::money($resource['total_price']),
            ]);
        }
    }

    protected function estimateItemResourceName(string $name): string
    {
        return mb_substr($name, 0, self::ESTIMATE_ITEM_RESOURCE_NAME_MAX_LENGTH);
    }

    protected function resolveMeasurementUnitId(int $organizationId, string $unit): ?int
    {
        $normalized = mb_strtolower(trim($unit));
        $cacheKey = $organizationId.'|'.$normalized;

        if (array_key_exists($cacheKey, $this->measurementUnitIdCache)) {
            return $this->measurementUnitIdCache[$cacheKey];
        }

        $units = $this->measurementUnits($organizationId);
        foreach ($units as $candidate) {
            if (in_array($normalized, [
                mb_strtolower(trim($candidate['short_name'])),
                mb_strtolower(trim($candidate['name'])),
            ], true)) {
                return $this->measurementUnitIdCache[$cacheKey] = $candidate['id'];
            }
        }

        $target = NormativeUnitNormalizer::parseDetailed($unit);
        if ($target->baseUnit === '') {
            return $this->measurementUnitIdCache[$cacheKey] = null;
        }

        foreach ($units as $candidate) {
            foreach ([$candidate['short_name'], $candidate['name']] as $candidateUnit) {
                $parsed = NormativeUnitNormalizer::parseDetailed($candidateUnit);
                if (
                    $parsed->baseUnit === $target->baseUnit
                    && $parsed->dimension === $target->dimension
                    && abs($parsed->multiplier - $target->multiplier) < 0.000001
                ) {
                    return $this->measurementUnitIdCache[$cacheKey] = $candidate['id'];
                }
            }
        }

        return $this->measurementUnitIdCache[$cacheKey] = null;
    }

    /** @return list<array{id: int, organization_id: int|null, name: string, short_name: string}> */
    protected function measurementUnits(int $organizationId): array
    {
        return $this->measurementUnitsCache[$organizationId] ??= MeasurementUnit::query()
            ->where(fn ($query) => $query->where('organization_id', $organizationId)->orWhereNull('organization_id'))
            ->orderByRaw('CASE WHEN organization_id = ? THEN 0 ELSE 1 END', [$organizationId])
            ->orderBy('id')
            ->get(['id', 'organization_id', 'name', 'short_name'])
            ->map(static fn (MeasurementUnit $measurementUnit): array => [
                'id' => (int) $measurementUnit->id,
                'organization_id' => $measurementUnit->organization_id !== null
                    ? (int) $measurementUnit->organization_id
                    : null,
                'name' => (string) $measurementUnit->name,
                'short_name' => (string) $measurementUnit->short_name,
            ])
            ->all();
    }

    /** @param array<string, mixed> $draft */
    private function resolveEstimateName(
        EstimateGenerationSession $session,
        array $draft,
        ?string $requestedName,
    ): string {
        $requestedName = trim((string) $requestedName);
        if ($requestedName !== '' && mb_strlen($requestedName) <= self::ESTIMATE_NAME_MAX_LENGTH) {
            return $requestedName;
        }

        $draftTitle = trim((string) ($draft['title'] ?? ''));
        if ($draftTitle !== '' && mb_strlen($draftTitle) <= self::ESTIMATE_NAME_MAX_LENGTH) {
            return $draftTitle;
        }

        $input = $session->input_payload ?? [];
        $parts = ['AI-смета'];
        $buildingType = trim((string) ($input['building_type'] ?? ''));
        if ($buildingType !== '') {
            $parts[] = $buildingType;
        }

        if (($input['area'] ?? null) !== null && $input['area'] !== '') {
            try {
                $parts[] = BigDecimal::of((string) $input['area'])->strippedOfTrailingZeros().' м²';
            } catch (\Throwable) {
            }
        }

        $region = trim((string) ($input['region'] ?? ($input['regional_context']['region_name'] ?? '')));
        if ($region !== '') {
            $parts[] = $region;
        }

        return mb_substr(implode(' • ', $parts), 0, self::ESTIMATE_NAME_MAX_LENGTH);
    }
}
