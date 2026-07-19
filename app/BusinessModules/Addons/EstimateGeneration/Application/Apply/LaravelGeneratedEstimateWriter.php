<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LaravelGeneratedEstimateWriter implements GeneratedEstimateWriter
{
    private const ESTIMATE_NAME_MAX_LENGTH = 255;

    private const NUMBER_CREATE_ATTEMPTS = 4;

    /** @var array<string, int|null> */
    private array $measurementUnitIdCache = [];

    /** @var array<int, list<array{id: int, organization_id: int|null, name: string, short_name: string}>> */
    private array $measurementUnitsCache = [];

    public function __construct(
        private EstimateDraftPersistenceService $draftService,
        private GeneratedEstimateNumberAllocator $numberAllocator,
        private GeneratedEstimateItemMetadataFactory $itemMetadata = new GeneratedEstimateItemMetadataFactory,
    ) {}

    public function createFromSession(
        EstimateGenerationSession $session,
        ApplyGeneratedEstimateCommand $command,
    ): int {
        $draft = $this->draftService->validatedDraft($session);
        $regionalContext = $draft['regional_context'] ?? $session->input_payload['regional_context'] ?? [];
        $total = $this->draftService->persistableDraftTotal($draft);
        $estimate = $this->createEstimate($session, $command, $draft, $regionalContext, $total);

        $this->persistSections($session, $estimate, $draft);

        return (int) $estimate->id;
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
        float $total,
    ): Estimate {
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
                    'calculation_method' => 'resource',
                    'estimate_regional_price_version_id' => $regionalContext['estimate_regional_price_version_id'] ?? null,
                    'regional_price_snapshot' => $regionalContext !== [] ? $regionalContext : null,
                    'metadata' => [
                        'is_ai_generated' => true,
                        'generation_session_id' => $session->getKey(),
                        'draft_traceability' => $draft['traceability'] ?? [],
                        'quality_summary' => $draft['quality_summary'] ?? null,
                        'regional_context' => $regionalContext,
                    ],
                    'total_direct_costs' => $total,
                    'total_amount' => $total,
                    'total_amount_with_vat' => $total,
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

            $localTotal = $this->draftService->persistableLocalEstimateTotal($localEstimate);
            if ($localTotal <= 0) {
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
                    'section_total_amount' => $this->draftService->workItemsTotal($workItems),
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
        return EstimateItem::create([
            'estimate_id' => $estimateId,
            'estimate_section_id' => $sectionId,
            'item_type' => EstimatePositionItemType::WORK->value,
            'position_number' => $position,
            'name' => $workItem['name'],
            'description' => $workItem['description'],
            'normative_rate_id' => data_get($workItem, 'normative_match.catalog_source') === 'normative_rates'
                ? data_get($workItem, 'normative_match.normative_rate_id')
                : null,
            'normative_rate_code' => $this->draftService->normativeRateCode($workItem),
            'measurement_unit_id' => $this->resolveMeasurementUnitId((int) $session->organization_id, $workItem['unit']),
            'quantity' => $workItem['quantity'],
            'quantity_total' => $workItem['quantity'],
            'unit_price' => $workItem['quantity'] > 0 ? round($workItem['total_cost'] / $workItem['quantity'], 4) : 0,
            'materials_cost' => $workItem['materials_cost'],
            'machinery_cost' => $workItem['machinery_cost'],
            'labor_cost' => $workItem['labor_cost'],
            'direct_costs' => $workItem['total_cost'],
            'total_amount' => $workItem['total_cost'],
            'current_total_amount' => $workItem['total_cost'],
            'justification' => $workItem['quantity_basis'],
            'is_manual' => true,
            'metadata' => $this->itemMetadata->make($workItem),
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
            EstimateItem::create([
                'estimate_id' => $parent->estimate_id,
                'estimate_section_id' => $parent->estimate_section_id,
                'parent_work_id' => $parent->id,
                'item_type' => $itemType,
                'position_number' => $parent->position_number.'.'.($resourceIndex + 1),
                'name' => $resource['name'],
                'description' => $resource['source'] ?? null,
                'normative_rate_code' => $resource['normative_ref']['resource_code'] ?? null,
                'measurement_unit_id' => $measurementUnitId,
                'quantity' => $resource['quantity'],
                'quantity_total' => $resource['quantity'],
                'unit_price' => $resource['unit_price'],
                'total_amount' => $resource['total_price'],
                'current_total_amount' => $resource['total_price'],
                'is_manual' => true,
                'metadata' => [
                    'confidence' => $resource['confidence'] ?? null,
                    'quantity_basis' => $resource['quantity_basis'] ?? null,
                    'quantity_per_unit' => $resource['quantity_per_unit'] ?? null,
                    'normative_ref' => $resource['normative_ref'] ?? null,
                    'source' => $resource['source'] ?? null,
                ],
            ]);

            EstimateItemResource::create([
                'estimate_item_id' => $parent->id,
                'resource_type' => $itemType === EstimatePositionItemType::MACHINERY->value ? 'equipment' : $itemType,
                'name' => $resource['name'],
                'description' => $resource['normative_ref']['resource_code'] ?? ($resource['source'] ?? null),
                'measurement_unit_id' => $measurementUnitId,
                'quantity_per_unit' => $resource['quantity_per_unit'] ?? 1,
                'total_quantity' => $resource['quantity'],
                'unit_price' => $resource['unit_price'],
                'total_amount' => $resource['total_price'],
            ]);
        }
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
        if ($requestedName !== '') {
            return mb_substr($requestedName, 0, self::ESTIMATE_NAME_MAX_LENGTH);
        }

        $draftTitle = trim((string) ($draft['title'] ?? ''));
        if ($draftTitle !== '') {
            return mb_substr($draftTitle, 0, self::ESTIMATE_NAME_MAX_LENGTH);
        }

        $input = $session->input_payload ?? [];
        $parts = ['AI-смета'];
        $buildingType = trim((string) ($input['building_type'] ?? ''));
        if ($buildingType !== '') {
            $parts[] = $buildingType;
        }

        $region = trim((string) ($input['region'] ?? ($input['regional_context']['region_name'] ?? '')));
        if ($region !== '') {
            $parts[] = $region;
        }

        if (($input['area'] ?? null) !== null && $input['area'] !== '') {
            $parts[] = ((float) $input['area']).' м²';
        }

        return mb_substr(implode(' • ', $parts), 0, self::ESTIMATE_NAME_MAX_LENGTH);
    }
}
