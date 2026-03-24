<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use App\Models\Estimate;

class EstimateGenerationExcelExportService
{
    public function __construct(
        protected ExcelEstimateBuilder $excelEstimateBuilder
    ) {}

    public function export(EstimateGenerationSession $session): array
    {
        $draft = $session->draft_payload ?? [];
        $estimate = $this->makeVirtualEstimate($session, $draft);
        $data = $this->prepareExportData($session, $draft);

        return $this->excelEstimateBuilder->build($estimate, $data, $data['options']);
    }

    protected function makeVirtualEstimate(EstimateGenerationSession $session, array $draft): Estimate
    {
        $estimate = new Estimate();
        $estimate->forceFill([
            'id' => 0,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'number' => 'AI-' . $session->id,
            'name' => $draft['title'] ?? 'AI-смета',
            'description' => $session->input_payload['description'] ?? null,
            'type' => 'local',
            'status' => 'draft',
        ]);
        $estimate->estimate_date = now();

        if ($session->relationLoaded('project')) {
            $estimate->setRelation('project', $session->project);
        } elseif ($session->project()->exists()) {
            $estimate->setRelation('project', $session->project()->first());
        }

        if ($estimate->project?->relationLoaded('organization')) {
            $estimate->setRelation('organization', $estimate->project->organization);
        } elseif ($session->organization()->exists()) {
            $estimate->setRelation('organization', $session->organization()->first());
        }

        return $estimate;
    }

    protected function prepareExportData(EstimateGenerationSession $session, array $draft): array
    {
        $sections = [];
        $sectionId = 1;
        $itemId = 1;

        foreach ($draft['local_estimates'] ?? [] as $localEstimateIndex => $localEstimate) {
            $localEstimateSectionId = $sectionId++;
            $localEstimateTotal = (float) ($localEstimate['totals']['total_cost'] ?? 0);

            $sections[] = [
                'id' => $localEstimateSectionId,
                'parent_section_id' => null,
                'section_number' => (string) ($localEstimateIndex + 1),
                'full_section_number' => (string) ($localEstimateIndex + 1),
                'name' => $localEstimate['title'] ?? ('Локальная смета ' . ($localEstimateIndex + 1)),
                'description' => $this->buildLocalEstimateDescription($localEstimate),
                'is_summary' => true,
                'section_total_amount' => round($localEstimateTotal, 2),
                'items' => [],
            ];

            foreach ($localEstimate['sections'] ?? [] as $sectionIndex => $section) {
                $currentSectionId = $sectionId++;
                $fullSectionNumber = (string) ($localEstimateIndex + 1) . '.' . ($sectionIndex + 1);
                $sectionTotal = 0.0;
                $preparedItems = [];

                foreach ($section['work_items'] ?? [] as $workIndex => $workItem) {
                    $preparedWorkItem = $this->prepareWorkItem(
                        $workItem,
                        $currentSectionId,
                        $itemId,
                        (string) ($workIndex + 1)
                    );

                    $itemId = $preparedWorkItem['next_item_id'];
                    $sectionTotal += (float) ($preparedWorkItem['item']['total_amount'] ?? 0);
                    $preparedItems[] = $preparedWorkItem['item'];
                    foreach ($preparedWorkItem['child_items'] as $childItem) {
                        $preparedItems[] = $childItem;
                    }
                }

                $sections[] = [
                    'id' => $currentSectionId,
                    'parent_section_id' => $localEstimateSectionId,
                    'section_number' => (string) ($sectionIndex + 1),
                    'full_section_number' => $fullSectionNumber,
                    'name' => $section['title'] ?? ($localEstimate['title'] ?? 'Раздел'),
                    'description' => $this->buildSectionDescription($localEstimate, $section),
                    'is_summary' => false,
                    'section_total_amount' => round($sectionTotal, 2),
                    'items' => $preparedItems,
                ];
            }
        }

        return [
            'estimate' => [
                'id' => 0,
                'number' => 'AI-' . $session->id,
                'name' => $draft['title'] ?? 'AI-смета',
                'description' => $session->input_payload['description'] ?? null,
                'type' => 'local',
                'status' => 'draft',
                'version' => 1,
                'estimate_date' => now()->format('d.m.Y'),
                'base_price_date' => now()->format('d.m.Y'),
                'organization' => [
                    'name' => $session->organization?->name,
                    'legal_name' => $session->organization?->legal_name,
                    'tax_number' => $session->organization?->tax_number,
                    'address' => $session->organization?->address,
                ],
                'project' => $session->project ? [
                    'id' => $session->project->id,
                    'name' => $session->project->name,
                    'address' => $session->project->address,
                ] : null,
                'contract' => null,
                'approved_by' => null,
                'approved_at' => null,
            ],
            'sections' => $sections,
            'totals' => [
                'total_direct_costs' => (float) ($draft['totals']['total_cost'] ?? 0),
                'total_overhead_costs' => 0.0,
                'total_estimated_profit' => 0.0,
                'total_amount' => (float) ($draft['totals']['total_cost'] ?? 0),
                'total_amount_with_vat' => (float) ($draft['totals']['total_cost'] ?? 0),
                'vat_rate' => 0.0,
                'overhead_rate' => 0.0,
                'profit_rate' => 0.0,
                'vat_amount' => 0.0,
            ],
            'metadata' => [
                'organization_id' => $session->organization_id,
                'export_date' => now()->toIso8601String(),
                'calculation_settings' => [
                    'overhead_rate' => 0.0,
                    'profit_rate' => 0.0,
                    'vat_rate' => 0.0,
                    'calculation_method' => 'commercial_ai',
                ],
                'draft_traceability' => $draft['traceability'] ?? [],
                'source_documents' => $draft['source_documents'] ?? [],
                'problem_flags' => $draft['problem_flags'] ?? [],
                'generation_session_id' => $session->id,
                'is_ai_generated' => true,
            ],
            'options' => [
                'include_sections' => true,
                'include_works' => true,
                'include_materials' => true,
                'include_machinery' => true,
                'include_labor' => true,
                'include_resources' => true,
                'include_coefficients' => false,
                'include_formulas' => false,
                'show_prices' => true,
                'signature_fields' => [
                    'Составил',
                    'Проверил',
                    'Руководитель проекта',
                    'Заказчик',
                ],
            ],
        ];
    }

    protected function prepareWorkItem(array $workItem, int $sectionId, int $itemId, string $positionNumber): array
    {
        $currentItemId = $itemId;
        $nextItemId = $itemId + 1;
        $childItems = [];
        $childPositionIndex = 1;

        foreach (['materials' => 'material', 'machinery' => 'machinery', 'labor' => 'labor'] as $resourceGroup => $itemType) {
            foreach ($workItem[$resourceGroup] ?? [] as $resource) {
                $childItems[] = [
                    'id' => $nextItemId,
                    'section_id' => $sectionId,
                    'parent_work_id' => $currentItemId,
                    'catalog_item_id' => null,
                    'item_type' => $itemType,
                    'position_number' => $positionNumber . '.' . $childPositionIndex,
                    'name' => $resource['name'] ?? 'Ресурс',
                    'description' => $resource['quantity_basis'] ?? null,
                    'normative_rate_code' => null,
                    'work_type' => $resource['resource_type'] ?? null,
                    'measurement_unit' => $resource['unit'] ?? '',
                    'quantity' => (float) ($resource['quantity'] ?? 0),
                    'quantity_total' => (float) ($resource['quantity'] ?? 0),
                    'unit_price' => (float) ($resource['unit_price'] ?? 0),
                    'base_unit_price' => (float) ($resource['unit_price'] ?? 0),
                    'current_unit_price' => (float) ($resource['unit_price'] ?? 0),
                    'direct_costs' => (float) ($resource['total_price'] ?? 0),
                    'materials_cost' => $itemType === 'material' ? (float) ($resource['total_price'] ?? 0) : 0.0,
                    'machinery_cost' => $itemType === 'machinery' ? (float) ($resource['total_price'] ?? 0) : 0.0,
                    'labor_cost' => $itemType === 'labor' ? (float) ($resource['total_price'] ?? 0) : 0.0,
                    'overhead_amount' => 0.0,
                    'profit_amount' => 0.0,
                    'total_amount' => (float) ($resource['total_price'] ?? 0),
                    'applied_coefficients' => null,
                    'coefficient_total' => null,
                    'custom_resources' => null,
                    'metadata' => [
                        'source' => $resource['source'] ?? null,
                        'confidence' => $resource['confidence'] ?? null,
                    ],
                    'is_manual' => false,
                    'is_not_accounted' => false,
                ];
                $nextItemId++;
                $childPositionIndex++;
            }
        }

        return [
            'next_item_id' => $nextItemId,
            'item' => [
                'id' => $currentItemId,
                'section_id' => $sectionId,
                'parent_work_id' => null,
                'catalog_item_id' => null,
                'item_type' => 'work',
                'position_number' => $positionNumber,
                'name' => $workItem['name'] ?? 'Работа',
                'description' => $this->buildWorkDescription($workItem),
                'normative_rate_code' => null,
                'work_type' => $workItem['work_category'] ?? null,
                'measurement_unit' => $workItem['unit'] ?? '',
                'quantity' => (float) ($workItem['quantity'] ?? 0),
                'quantity_total' => (float) ($workItem['quantity'] ?? 0),
                'unit_price' => $this->resolveUnitPrice($workItem),
                'base_unit_price' => $this->resolveUnitPrice($workItem),
                'current_unit_price' => $this->resolveUnitPrice($workItem),
                'direct_costs' => (float) ($workItem['total_cost'] ?? 0),
                'materials_cost' => (float) ($workItem['materials_cost'] ?? 0),
                'machinery_cost' => (float) ($workItem['machinery_cost'] ?? 0),
                'labor_cost' => (float) ($workItem['labor_cost'] ?? 0),
                'overhead_amount' => 0.0,
                'profit_amount' => 0.0,
                'total_amount' => (float) ($workItem['total_cost'] ?? 0),
                'applied_coefficients' => null,
                'coefficient_total' => null,
                'custom_resources' => null,
                'metadata' => [
                    'quantity_formula' => $workItem['quantity_formula'] ?? null,
                    'source_refs' => $workItem['source_refs'] ?? [],
                    'validation_flags' => $workItem['validation_flags'] ?? [],
                    'confidence' => $workItem['confidence'] ?? null,
                ],
                'is_manual' => false,
                'is_not_accounted' => false,
                'child_items' => $childItems,
            ],
            'child_items' => $childItems,
        ];
    }

    protected function resolveUnitPrice(array $workItem): float
    {
        $quantity = (float) ($workItem['quantity'] ?? 0);
        $totalCost = (float) ($workItem['total_cost'] ?? 0);

        if ($quantity <= 0.0) {
            return 0.0;
        }

        return round($totalCost / $quantity, 2);
    }

    protected function buildSectionDescription(array $localEstimate, array $section): ?string
    {
        $parts = [];

        if (!empty($localEstimate['source_refs'])) {
            $parts[] = implode('; ', array_map(
                static fn (array $ref): string => ($ref['type'] ?? 'источник') . ': ' . ($ref['value'] ?? ''),
                $localEstimate['source_refs']
            ));
        }

        if (!empty($section['source_refs'])) {
            $parts[] = implode('; ', array_map(
                static fn (array $ref): string => ($ref['type'] ?? 'источник') . ': ' . ($ref['value'] ?? ''),
                $section['source_refs']
            ));
        }

        return $parts !== [] ? implode(' | ', $parts) : null;
    }

    protected function buildLocalEstimateDescription(array $localEstimate): ?string
    {
        $parts = [];

        if (!empty($localEstimate['source_refs'])) {
            $parts[] = implode('; ', array_map(
                static fn (array $ref): string => ($ref['type'] ?? 'источник') . ': ' . ($ref['value'] ?? ''),
                $localEstimate['source_refs']
            ));
        }

        if (!empty($localEstimate['assumptions'])) {
            $parts[] = implode('; ', $localEstimate['assumptions']);
        }

        return $parts !== [] ? implode(' | ', $parts) : null;
    }

    protected function buildWorkDescription(array $workItem): ?string
    {
        $parts = array_values(array_filter([
            $workItem['description'] ?? null,
            $workItem['quantity_basis'] ?? null,
            !empty($workItem['source_refs'])
                ? implode('; ', array_map(
                    static fn (array $ref): string => ($ref['type'] ?? 'источник') . ': ' . ($ref['value'] ?? ''),
                    $workItem['source_refs']
                ))
                : null,
        ]));

        return $parts !== [] ? implode(' | ', $parts) : null;
    }
}
