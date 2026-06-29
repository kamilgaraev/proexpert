<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

class EstimateDraftPersistenceService
{
    private const ESTIMATE_NAME_MAX_LENGTH = 255;

    protected EstimateGenerationFinalWorkItemGuard $finalWorkItemGuard;

    protected EstimateGenerationReviewItemService $reviewItemService;

    public function __construct(
        ?EstimateGenerationFinalWorkItemGuard $finalWorkItemGuard = null,
        ?EstimateGenerationReviewItemService $reviewItemService = null,
    ) {
        $this->finalWorkItemGuard = $finalWorkItemGuard ?? new EstimateGenerationFinalWorkItemGuard();
        $this->reviewItemService = $reviewItemService ?? new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter());
    }

    public function apply(EstimateGenerationSession $session, array $payload, User $user): Estimate
    {
        $draft = $session->draft_payload ?? [];
        if (($draft['local_estimates'] ?? []) === []) {
            throw new \RuntimeException('Draft is empty.');
        }

        $this->assertNoBlockingReviewItems($session);
        $this->assertDraftCanBeApplied($draft);

        return DB::transaction(function () use ($session, $payload, $draft): Estimate {
            $regionalContext = $draft['regional_context'] ?? $session->input_payload['regional_context'] ?? [];
            $persistableDraftTotal = $this->persistableDraftTotal($draft);
            $estimate = Estimate::create([
                'organization_id' => $session->organization_id,
                'project_id' => $session->project_id,
                'number' => $this->generateEstimateNumber($session->project_id),
                'name' => $this->resolveEstimateName($session, $payload, $draft),
                'description' => $session->input_payload['description'] ?? null,
                'type' => $payload['type'] ?? 'local',
                'status' => 'draft',
                'estimate_date' => $payload['estimate_date'] ?? now()->toDateString(),
                'calculation_method' => 'resource',
                'estimate_regional_price_version_id' => $regionalContext['estimate_regional_price_version_id'] ?? null,
                'regional_price_snapshot' => $regionalContext !== [] ? $regionalContext : null,
                'metadata' => [
                    'is_ai_generated' => true,
                    'generation_session_id' => $session->id,
                    'draft_traceability' => $draft['traceability'] ?? [],
                    'quality_summary' => $draft['quality_summary'] ?? null,
                    'regional_context' => $regionalContext,
                ],
                'total_direct_costs' => $persistableDraftTotal,
                'total_amount' => $persistableDraftTotal,
                'total_amount_with_vat' => $persistableDraftTotal,
            ]);

            $persistedLocalIndex = 0;

            foreach ($draft['local_estimates'] as $localEstimate) {
                $persistableLocalTotal = $this->persistableLocalEstimateTotal($localEstimate);

                if ($persistableLocalTotal <= 0) {
                    continue;
                }

                $persistedLocalIndex++;

                $rootSection = EstimateSection::create([
                    'estimate_id' => $estimate->id,
                    'section_number' => (string) $persistedLocalIndex,
                    'full_section_number' => (string) $persistedLocalIndex,
                    'name' => $localEstimate['title'],
                    'description' => implode('; ', $localEstimate['assumptions'] ?? []),
                    'sort_order' => $persistedLocalIndex - 1,
                    'section_total_amount' => $persistableLocalTotal,
                ]);

                $persistedSectionIndex = 0;

                foreach ($localEstimate['sections'] as $section) {
                    $persistableWorkItems = $this->persistableWorkItems($section['work_items'] ?? []);

                    if ($persistableWorkItems === []) {
                        continue;
                    }

                    $persistedSectionIndex++;

                    $sectionModel = EstimateSection::create([
                        'estimate_id' => $estimate->id,
                        'parent_section_id' => $rootSection->id,
                        'section_number' => (string) $persistedSectionIndex,
                        'full_section_number' => ($persistedLocalIndex . '.' . $persistedSectionIndex),
                        'name' => $section['title'],
                        'description' => $section['construction_part'] ?? null,
                        'sort_order' => $persistedSectionIndex - 1,
                        'section_total_amount' => $this->workItemsTotal($persistableWorkItems),
                    ]);

                    foreach ($persistableWorkItems as $workIndex => $workItem) {
                        $work = EstimateItem::create([
                            'estimate_id' => $estimate->id,
                            'estimate_section_id' => $sectionModel->id,
                            'item_type' => EstimatePositionItemType::WORK->value,
                            'position_number' => $persistedLocalIndex . '.' . $persistedSectionIndex . '.' . ($workIndex + 1),
                            'name' => $workItem['name'],
                            'description' => $workItem['description'],
                            'normative_rate_code' => $this->normativeRateCode($workItem),
                            'measurement_unit_id' => $this->resolveMeasurementUnitId($session->organization_id, $workItem['unit']),
                            'quantity' => $workItem['quantity'],
                            'quantity_total' => $workItem['quantity'],
                            'unit_price' => $workItem['quantity'] > 0 ? round(($workItem['total_cost'] / $workItem['quantity']), 4) : 0,
                            'materials_cost' => $workItem['materials_cost'],
                            'machinery_cost' => $workItem['machinery_cost'],
                            'labor_cost' => $workItem['labor_cost'],
                            'direct_costs' => $workItem['total_cost'],
                            'total_amount' => $workItem['total_cost'],
                            'current_total_amount' => $workItem['total_cost'],
                            'justification' => $workItem['quantity_basis'],
                            'is_manual' => true,
                            'metadata' => [
                                'source_refs' => $workItem['source_refs'],
                                'confidence' => $workItem['confidence'],
                                'validation_flags' => $workItem['validation_flags'],
                                'normative_dataset' => $workItem['normative_dataset'] ?? null,
                                'normative_match' => $workItem['normative_match'] ?? null,
                                'normative_candidates' => $workItem['normative_candidates'] ?? [],
                                'price_source' => $workItem['price_source'] ?? null,
                            ],
                        ]);

                        $this->persistResources($work, $workItem['materials'] ?? [], EstimatePositionItemType::MATERIAL->value, $session->organization_id);
                        $this->persistResources($work, $workItem['labor'] ?? [], EstimatePositionItemType::LABOR->value, $session->organization_id);
                        $this->persistResources($work, $workItem['machinery'] ?? [], EstimatePositionItemType::MACHINERY->value, $session->organization_id);
                    }
                }
            }

            $session->forceFill([
                'applied_estimate_id' => $estimate->id,
                'status' => 'applied',
                'processing_stage' => 'applied',
            ])->save();

            return $estimate;
        });
    }

    protected function persistResources(EstimateItem $parent, array $resources, string $itemType, int $organizationId): void
    {
        foreach ($resources as $resourceIndex => $resource) {
            EstimateItem::create([
                'estimate_id' => $parent->estimate_id,
                'estimate_section_id' => $parent->estimate_section_id,
                'parent_work_id' => $parent->id,
                'item_type' => $itemType,
                'position_number' => $parent->position_number . '.' . ($resourceIndex + 1),
                'name' => $resource['name'],
                'description' => $resource['source'] ?? null,
                'normative_rate_code' => $resource['normative_ref']['resource_code'] ?? null,
                'measurement_unit_id' => $this->resolveMeasurementUnitId($organizationId, $resource['unit']),
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
                'measurement_unit_id' => $this->resolveMeasurementUnitId($organizationId, $resource['unit']),
                'quantity_per_unit' => $resource['quantity_per_unit'] ?? 1,
                'total_quantity' => $resource['quantity'],
                'unit_price' => $resource['unit_price'],
                'total_amount' => $resource['total_price'],
            ]);
        }
    }

    protected function assertNoBlockingReviewItems(EstimateGenerationSession $session): void
    {
        $reviewQueue = $this->reviewItemService->forSession($session);
        $blockingCount = (int) data_get($reviewQueue, 'summary.blocking', 0);

        if ($blockingCount <= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'draft' => [trans_message('estimate_generation.apply_review_items_blocked', [
                'count' => $blockingCount,
            ])],
        ]);
    }

    /**
     * @param array<string, mixed> $draft
     */
    protected function persistableDraftTotal(array $draft): float
    {
        $total = 0.0;

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (is_array($localEstimate)) {
                $total += $this->persistableLocalEstimateTotal($localEstimate);
            }
        }

        return round($total, 2);
    }

    /**
     * @param array<string, mixed> $localEstimate
     */
    protected function persistableLocalEstimateTotal(array $localEstimate): float
    {
        $total = 0.0;

        foreach ($localEstimate['sections'] ?? [] as $section) {
            if (!is_array($section)) {
                continue;
            }

            $total += $this->workItemsTotal($this->persistableWorkItems($section['work_items'] ?? []));
        }

        return round($total, 2);
    }

    /**
     * @param array<int, mixed> $workItems
     * @return array<int, array<string, mixed>>
     */
    protected function persistableWorkItems(array $workItems): array
    {
        return array_values(array_filter(
            $workItems,
            fn (mixed $workItem): bool => is_array($workItem) && $this->isPersistableWorkItem($workItem)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $workItems
     */
    protected function workItemsTotal(array $workItems): float
    {
        return round(array_sum(array_map(
            static fn (array $workItem): float => (float) ($workItem['total_cost'] ?? 0),
            $workItems
        )), 2);
    }

    /**
     * @param array<string, mixed> $workItem
     */
    protected function isPersistableWorkItem(array $workItem): bool
    {
        return $this->finalWorkItemGuard->isFinalEstimateWorkItem($workItem);
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function normativeRateCode(array $workItem): ?string
    {
        return $this->finalWorkItemGuard->normativeRateCode($workItem);
    }

    protected function resolveMeasurementUnitId(int $organizationId, string $unit): ?int
    {
        $normalized = mb_strtolower(trim($unit));

        return MeasurementUnit::query()
            ->where(function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->where(function ($query) use ($normalized): void {
                $query->whereRaw('LOWER(short_name) = ?', [$normalized])
                    ->orWhereRaw('LOWER(name) = ?', [$normalized]);
            })
            ->value('id');
    }

    protected function generateEstimateNumber(int $projectId): string
    {
        return sprintf('AI-%d-%s', $projectId, now()->format('YmdHis'));
    }

    private function resolveEstimateName(EstimateGenerationSession $session, array $payload, array $draft): string
    {
        $payloadName = trim((string) ($payload['name'] ?? ''));
        if ($payloadName !== '' && mb_strlen($payloadName) <= self::ESTIMATE_NAME_MAX_LENGTH) {
            return $payloadName;
        }

        $draftTitle = trim((string) ($draft['title'] ?? ''));
        if ($draftTitle !== '' && mb_strlen($draftTitle) <= self::ESTIMATE_NAME_MAX_LENGTH) {
            return $draftTitle;
        }

        return mb_substr($this->buildGeneratedEstimateName($session), 0, self::ESTIMATE_NAME_MAX_LENGTH);
    }

    /**
     * @param array<string, mixed> $draft
     */
    protected function assertDraftCanBeApplied(array $draft): void
    {
        $blocker = $this->applyBlocker($draft);

        if ($blocker === null) {
            return;
        }

        if ($blocker['type'] === 'unresolved_normatives') {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.unresolved_normatives', [
                    'count' => $blocker['count'],
                ])],
            ]);
        }

        if ($blocker['type'] === 'prices_require_review') {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.apply_prices_require_review')],
            ]);
        }

        if ($blocker['type'] === 'quantities_require_review') {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.apply_quantities_require_review')],
            ]);
        }

        if ($blocker['type'] === 'blocked') {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.apply_blocked')],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $draft
     * @return array{type: string, count?: int}|null
     */
    protected function applyBlocker(array $draft): ?array
    {
        $qualityStatus = (string) ($draft['quality_summary']['status'] ?? '');
        $qualityLevel = (string) ($draft['quality_summary']['level'] ?? '');
        $unresolvedNormatives = (int) data_get($draft, 'quality_summary.normative_items.requires_review', 0);
        $notCalculatedWorkItems = (int) data_get($draft, 'quality_summary.not_calculated_work_items', 0);
        $safeNormRequiredWorkItems = (int) data_get($draft, 'quality_summary.safe_norm_required_work_items', 0);
        $duplicateWorkItems = (int) data_get($draft, 'quality_summary.duplicate_work_items', 0);
        $quantityReviewWorkItems = (int) data_get($draft, 'quality_summary.quantity_review_work_items', 0);

        if ($unresolvedNormatives > 0) {
            return [
                'type' => 'unresolved_normatives',
                'count' => $unresolvedNormatives,
            ];
        }

        if ($quantityReviewWorkItems > 0) {
            return [
                'type' => 'quantities_require_review',
                'count' => $quantityReviewWorkItems,
            ];
        }

        if (
            $notCalculatedWorkItems > 0
            || $safeNormRequiredWorkItems > 0
            || $duplicateWorkItems > 0
            || $qualityStatus === 'review_required'
            || $this->persistableDraftTotal($draft) <= 0
            || $this->hasNonPersistablePricedWorkItems($draft)
        ) {
            return ['type' => 'prices_require_review'];
        }

        if ($qualityStatus === 'critical' || $qualityLevel === 'blocked') {
            return ['type' => 'blocked'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function hasNonPersistablePricedWorkItems(array $draft): bool
    {
        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (!is_array($localEstimate)) {
                continue;
            }

            foreach ($localEstimate['sections'] ?? [] as $section) {
                if (!is_array($section)) {
                    continue;
                }

                foreach ($section['work_items'] ?? [] as $workItem) {
                    if (!is_array($workItem)) {
                        continue;
                    }

                    $type = (string) ($workItem['item_type'] ?? 'priced_work');

                    if (!in_array($type, ['operation', 'resource_note', 'review_note'], true) && !$this->isPersistableWorkItem($workItem)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function buildGeneratedEstimateName(EstimateGenerationSession $session): string
    {
        $input = $session->input_payload ?? [];
        $parts = ['AI-смета'];

        $buildingType = trim((string) ($input['building_type'] ?? ''));
        if ($buildingType !== '') {
            $parts[] = $buildingType;
        }

        $area = $input['area'] ?? null;
        if ($area !== null && $area !== '') {
            $parts[] = ((float) $area) . ' м²';
        }

        $region = trim((string) ($input['region'] ?? ($input['regional_context']['region_name'] ?? '')));
        if ($region !== '') {
            $parts[] = $region;
        }

        return implode(' • ', $parts);
    }
}
