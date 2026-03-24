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

class EstimateDraftPersistenceService
{
    public function apply(EstimateGenerationSession $session, array $payload, User $user): Estimate
    {
        $draft = $session->draft_payload ?? [];
        if (($draft['local_estimates'] ?? []) === []) {
            throw new \RuntimeException('Draft is empty.');
        }

        return DB::transaction(function () use ($session, $payload, $draft): Estimate {
            $estimate = Estimate::create([
                'organization_id' => $session->organization_id,
                'project_id' => $session->project_id,
                'number' => $this->generateEstimateNumber($session->project_id),
                'name' => $payload['name'] ?? mb_substr((string) ($draft['title'] ?? 'AI смета'), 0, 255),
                'description' => $session->input_payload['description'] ?? null,
                'type' => $payload['type'] ?? 'local',
                'status' => 'draft',
                'estimate_date' => $payload['estimate_date'] ?? now()->toDateString(),
                'calculation_method' => 'resource',
                'metadata' => [
                    'is_ai_generated' => true,
                    'generation_session_id' => $session->id,
                    'draft_traceability' => $draft['traceability'] ?? [],
                ],
                'total_direct_costs' => $draft['totals']['total_cost'] ?? 0,
                'total_amount' => $draft['totals']['total_cost'] ?? 0,
                'total_amount_with_vat' => $draft['totals']['total_cost'] ?? 0,
            ]);

            foreach ($draft['local_estimates'] as $localIndex => $localEstimate) {
                $rootSection = EstimateSection::create([
                    'estimate_id' => $estimate->id,
                    'section_number' => (string) ($localIndex + 1),
                    'full_section_number' => (string) ($localIndex + 1),
                    'name' => $localEstimate['title'],
                    'description' => implode('; ', $localEstimate['assumptions'] ?? []),
                    'sort_order' => $localIndex,
                    'section_total_amount' => $localEstimate['totals']['total_cost'] ?? 0,
                ]);

                foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                    $sectionModel = EstimateSection::create([
                        'estimate_id' => $estimate->id,
                        'parent_section_id' => $rootSection->id,
                        'section_number' => (string) ($sectionIndex + 1),
                        'full_section_number' => (($localIndex + 1) . '.' . ($sectionIndex + 1)),
                        'name' => $section['title'],
                        'description' => $section['construction_part'] ?? null,
                        'sort_order' => $sectionIndex,
                        'section_total_amount' => $section['section_totals']['total_cost'] ?? 0,
                    ]);

                    foreach ($section['work_items'] as $workIndex => $workItem) {
                        $work = EstimateItem::create([
                            'estimate_id' => $estimate->id,
                            'estimate_section_id' => $sectionModel->id,
                            'item_type' => EstimatePositionItemType::WORK->value,
                            'position_number' => ($localIndex + 1) . '.' . ($sectionIndex + 1) . '.' . ($workIndex + 1),
                            'name' => $workItem['name'],
                            'description' => $workItem['description'],
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
                ],
            ]);

            EstimateItemResource::create([
                'estimate_item_id' => $parent->id,
                'resource_type' => $itemType === EstimatePositionItemType::MACHINERY->value ? 'equipment' : $itemType,
                'name' => $resource['name'],
                'measurement_unit_id' => $this->resolveMeasurementUnitId($organizationId, $resource['unit']),
                'quantity_per_unit' => 1,
                'total_quantity' => $resource['quantity'],
                'unit_price' => $resource['unit_price'],
                'total_amount' => $resource['total_price'],
            ]);
        }
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
}
