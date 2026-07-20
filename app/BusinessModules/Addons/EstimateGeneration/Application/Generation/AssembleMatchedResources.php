<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;

final readonly class AssembleMatchedResources
{
    private const GROUPS = ['materials', 'labor', 'machinery', 'other_resources'];

    public function __construct(
        private ResidentialProjectMaterialCatalog $projectMaterials = new ResidentialProjectMaterialCatalog,
    ) {}

    /** @param array<string, mixed> $data @return array{data: array<string, mixed>, resources_count: int} */
    public function handle(array $data): array
    {
        $count = 0;
        $pinnedMaterials = is_array($data['supplementary_materials'] ?? null)
            ? $data['supplementary_materials']
            : [];
        foreach ($data['local_estimates'] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                foreach ($section['work_items'] as $itemIndex => $workItem) {
                    $workItem = $this->withProjectMaterial($workItem, $pinnedMaterials);
                    $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $workItem;
                    foreach (self::GROUPS as $group) {
                        $resources = is_array($workItem[$group] ?? null)
                            ? array_values(array_filter($workItem[$group], 'is_array'))
                            : [];
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex][$group] = $resources;
                        $count += count($resources);
                    }
                }
            }
        }

        return ['data' => $data, 'resources_count' => $count];
    }

    /** @param list<mixed> $pinnedMaterials @return array<string, mixed> */
    private function withProjectMaterial(array $workItem, array $pinnedMaterials): array
    {
        $scenario = is_array($workItem['specialization_scenario'] ?? null)
            ? $workItem['specialization_scenario']
            : null;
        $requirement = $this->projectMaterials->requirementForIntent(['specialization_scenario' => $scenario]);
        if ($requirement === null) {
            return $workItem;
        }

        $normCode = trim((string) ($workItem['normative_rate_code'] ?? ''));
        if ($normCode === '' || $normCode !== trim((string) ($scenario['normative_rate_code'] ?? ''))) {
            return $this->blocked($workItem);
        }

        $pinned = null;
        foreach ($pinnedMaterials as $candidate) {
            if (is_array($candidate) && ($candidate['work_item_key'] ?? null) === $requirement['work_item_key']) {
                $pinned = $candidate;
                break;
            }
        }
        $resource = is_array($pinned['resource'] ?? null) ? $pinned['resource'] : null;
        $selection = is_array($resource['project_material_requirement'] ?? null)
            ? $resource['project_material_requirement']
            : [];
        if (($pinned['status'] ?? null) !== 'priced'
            || ! $this->validPinnedResource($resource, $selection, $requirement)) {
            return $this->blocked($workItem);
        }

        $workQuantity = is_numeric($workItem['quantity'] ?? null) ? (float) $workItem['quantity'] : 0.0;
        $quantityPerUnit = (float) ($resource['quantity'] ?? 0);
        if ($workQuantity <= 0 || $quantityPerUnit <= 0) {
            return $this->blocked($workItem);
        }
        $quantity = round($workQuantity * $quantityPerUnit, 6);
        $unitPrice = (float) $resource['unit_price'];
        $materials = is_array($workItem['materials'] ?? null)
            ? array_values(array_filter($workItem['materials'], 'is_array'))
            : [];
        $key = (string) ($workItem['key'] ?? $requirement['work_item_key']).'-project-material-'.$resource['code'];
        $materials = array_values(array_filter(
            $materials,
            static fn (array $material): bool => ($material['key'] ?? null) !== $key,
        ));
        $materials[] = [
            'key' => $key,
            'code' => $resource['code'],
            'name' => $resource['name'],
            'resource_type' => 'material',
            'unit' => $resource['unit'],
            'price_unit' => $resource['price_unit'],
            'quantity' => $quantity,
            'quantity_per_unit' => $quantityPerUnit,
            'quantity_basis' => 'project_material_scenario',
            'unit_price' => $unitPrice,
            'total_price' => round($quantity * $unitPrice, 2),
            'price_source' => $resource['price_source'],
            'price_source_version' => $resource['price_source_version'],
            'source' => 'project_material_catalog:'.ResidentialProjectMaterialCatalog::VERSION,
            'confidence' => $workItem['confidence'] ?? 0.8,
            'project_material_selection' => $selection,
            'normative_ref' => [
                'norm_code' => $normCode,
                'resource_code' => $resource['code'],
                'resource_id' => $resource['linked_resource_id'] ?? null,
                'price_id' => $resource['price_id'],
                'price_source' => $resource['price_source'],
                'price_source_version' => $resource['price_source_version'],
                'project_material_selection' => $selection,
            ],
        ];
        $workItem['materials'] = $materials;
        $workItem['project_material_selections'] = [[
            ...$selection,
            'resource_code' => $resource['code'],
            'resource_name' => $resource['name'],
            'quantity' => $quantity,
            'unit' => $resource['unit'],
            'unit_price' => $unitPrice,
            'price_source' => $resource['price_source'],
            'price_source_version' => $resource['price_source_version'],
        ]];

        return $workItem;
    }

    /** @param array<string, mixed> $resource @param array<string, mixed> $selection @param array<string, mixed> $requirement */
    private function validPinnedResource(array $resource, array $selection, array $requirement): bool
    {
        if (($resource['unit'] ?? null) !== $requirement['unit']
            || ($selection['version'] ?? null) !== ResidentialProjectMaterialCatalog::VERSION
            || ($selection['work_item_key'] ?? null) !== $requirement['work_item_key']
            || ($selection['assumption_code'] ?? null) !== $requirement['assumption_code']
            || ($selection['preferred_resource_code'] ?? null) !== $requirement['resource_code']) {
            return false;
        }

        $normalized = $this->projectMaterials->resourceFromPriceRows($requirement, [(object) [
            'price_id' => $resource['price_id'] ?? null,
            'construction_resource_id' => $resource['linked_resource_id'] ?? null,
            'resource_code' => $resource['code'] ?? null,
            'resource_name' => $resource['name'] ?? null,
            'unit' => $selection['source_price_unit'] ?? null,
            'base_price' => $selection['source_unit_price'] ?? null,
            'price_source' => $resource['price_source'] ?? null,
            'price_source_version' => $resource['price_source_version'] ?? null,
        ]]);

        return $normalized !== null
            && ($normalized['code'] ?? null) === ($resource['code'] ?? null)
            && (string) $normalized['unit_price'] === (string) ($resource['unit_price'] ?? '')
            && (float) $normalized['quantity'] === (float) ($resource['quantity'] ?? 0)
            && ($normalized['project_material_requirement']['selection_policy'] ?? null)
                === ($selection['selection_policy'] ?? null);
    }

    /** @return array<string, mixed> */
    private function blocked(array $workItem): array
    {
        $flags = is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : [];

        return [
            ...$workItem,
            'pricing_status' => 'not_calculated',
            'pricing_blocker' => 'project_material_price_missing',
            'pricing_blocker_message' => $this->message(),
            'validation_flags' => array_values(array_unique([...$flags, 'project_material_price_missing', 'pricing_not_calculated'])),
        ];
    }

    private function message(): ?string
    {
        try {
            return trans_message('estimate_generation.project_material_price_missing');
        } catch (\Throwable) {
            return null;
        }
    }
}
