<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

final class FinalizedPackageDraftProjector
{
    private const ALLOWED_FORMULA_VERSIONS = [
        'project_resource:v3',
        'semantic_project_resource:v7',
        'supplementary_project_material:v4',
    ];

    private const ALLOWED_PACKAGE_STATUSES = ['ready_for_review', 'review_required', 'approved'];

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function project(EstimateGenerationSession $session, array $draft): array
    {
        $sourceInputVersion = $this->sourceInputVersion($draft);
        $items = EstimateGenerationPackageItem::query()
            ->select(
                'estimate_generation_package_items.*',
                'estimate_generation_packages.key as package_key',
                'estimate_generation_packages.input_version as package_input_version',
                'estimate_generation_packages.status as package_status',
            )
            ->join('estimate_generation_packages', 'estimate_generation_packages.id', '=', 'estimate_generation_package_items.package_id')
            ->where('estimate_generation_packages.session_id', $session->getKey())
            ->where('estimate_generation_packages.input_version', $sourceInputVersion)
            ->where('estimate_generation_packages.status', '<>', 'superseded')
            ->whereNotIn('estimate_generation_package_items.item_type', EstimateGenerationPackageItem::SERVICE_ITEM_TYPES)
            ->latestLogicalRevisions()
            ->lockForUpdate()
            ->get();

        return $this->projectFromItems($draft, $items);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  iterable<EstimateGenerationPackageItem>  $items
     * @return array<string, mixed>
     */
    public function projectFromItems(array $draft, iterable $items): array
    {
        $sourceInputVersion = $this->sourceInputVersion($draft);
        $expected = $this->expectedWorkItems($draft);
        $finalized = [];

        foreach ($items as $item) {
            $identity = $this->itemIdentity($item);
            if (isset($finalized[$identity])) {
                throw new \DomainException('Finalized package item mapping is ambiguous.');
            }
            if (! isset($expected[$identity])) {
                throw new \DomainException('Finalized package item has no matching draft work item.');
            }

            $this->assertFinalizedItem($item, $sourceInputVersion);
            $finalized[$identity] = $item;
        }

        if (count($finalized) !== count($expected)) {
            throw new \DomainException('Finalized package item set is incomplete.');
        }

        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            $packageKey = $this->packageKey($localEstimate, (int) $localIndex);
            foreach ($localEstimate['sections'] ?? [] as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }

                foreach ($section['work_items'] ?? [] as $workItemIndex => $workItem) {
                    if (! is_array($workItem) || ! $this->requiresFinalizedPrice($workItem)) {
                        continue;
                    }

                    $identity = $packageKey.'|'.$this->workItemKey($workItem);
                    $item = $finalized[$identity] ?? null;
                    if (! $item instanceof EstimateGenerationPackageItem) {
                        throw new \DomainException('Finalized package price is missing for an applied estimate item.');
                    }

                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workItemIndex]
                        = $this->overlay($workItem, $item);
                }
            }
        }

        return $draft;
    }

    /** @param array<string, mixed> $draft @return array<string, true> */
    private function expectedWorkItems(array $draft): array
    {
        $expected = [];
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }
            $packageKey = $this->packageKey($localEstimate, (int) $localIndex);
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $workItem) {
                    if (! is_array($workItem) || ! $this->requiresFinalizedPrice($workItem)) {
                        continue;
                    }
                    $identity = $packageKey.'|'.$this->workItemKey($workItem);
                    if (isset($expected[$identity])) {
                        throw new \DomainException('Draft work item mapping is ambiguous.');
                    }
                    $expected[$identity] = true;
                }
            }
        }

        if ($expected === []) {
            throw new \DomainException('Draft has no work items backed by finalized package prices.');
        }

        return $expected;
    }

    /** @param array<string, mixed> $draft */
    private function sourceInputVersion(array $draft): string
    {
        $version = $draft['source_input_version'] ?? null;
        if (! is_string($version) || preg_match('/^sha256:[a-f0-9]{64}$/D', $version) !== 1) {
            throw new \DomainException('Applied draft input version is invalid.');
        }

        return $version;
    }

    /** @param array<string, mixed> $localEstimate */
    private function packageKey(array $localEstimate, int $localIndex): string
    {
        $key = trim((string) ($localEstimate['key'] ?? 'package-'.($localIndex + 1)));
        if ($key === '') {
            throw new \DomainException('Applied draft package key is missing.');
        }

        return $key;
    }

    /** @param array<string, mixed> $workItem */
    private function workItemKey(array $workItem): string
    {
        $key = trim((string) ($workItem['key'] ?? ''));
        if ($key === '') {
            throw new \DomainException('Applied draft work item key is missing.');
        }

        return $key;
    }

    private function itemIdentity(EstimateGenerationPackageItem $item): string
    {
        $packageKey = trim((string) $item->getAttribute('package_key'));
        $logicalKey = trim((string) ($item->logical_key ?? $item->key));
        if ($packageKey === '' || $logicalKey === '') {
            throw new \DomainException('Finalized package item identity is incomplete.');
        }

        return $packageKey.'|'.$logicalKey;
    }

    private function assertFinalizedItem(EstimateGenerationPackageItem $item, string $sourceInputVersion): void
    {
        if (! hash_equals($sourceInputVersion, (string) $item->getAttribute('package_input_version'))) {
            throw new \DomainException('Finalized package item input version is stale.');
        }
        if (! in_array((string) $item->getAttribute('package_status'), self::ALLOWED_PACKAGE_STATUSES, true)) {
            throw new \DomainException('Finalized package is not ready to be applied.');
        }
        if ((string) $item->item_type !== 'priced_work'
            || $this->pricingFinalizedAt($item) === null
            || $this->positiveDecimal($item->quantity, 'quantity')->isLessThanOrEqualTo(0)
            || trim((string) $item->unit) === ''
            || $this->positiveDecimal($item->unit_price, 'unit price')->isLessThanOrEqualTo(0)
            || $this->positiveDecimal($item->total_cost, 'total cost')->isLessThanOrEqualTo(0)) {
            throw new \DomainException('Package item price is not finalized.');
        }
        if ((string) $item->price_source !== 'regional_catalog') {
            throw new \DomainException('Finalized package item price source is invalid.');
        }

        $snapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];
        $formulaVersion = data_get($snapshot, 'coefficients.pricing_formula_version');
        if (! is_string($formulaVersion) || ! in_array($formulaVersion, self::ALLOWED_FORMULA_VERSIONS, true)) {
            throw new \DomainException('Finalized package item pricing formula is unsupported.');
        }
        if ($this->money($snapshot['final_amount'] ?? null, 'snapshot final amount')
            ->compareTo($this->money($item->total_cost, 'total cost')) !== 0) {
            throw new \DomainException('Finalized package item snapshot total is inconsistent.');
        }
        if ($this->money($item->direct_cost, 'direct cost')->compareTo($this->money($item->total_cost, 'total cost')) !== 0
            || $this->money($item->overhead_cost, 'overhead cost')->compareTo(BigDecimal::zero()) !== 0
            || $this->money($item->profit_cost, 'profit cost')->compareTo(BigDecimal::zero()) !== 0) {
            throw new \DomainException('Finalized package item cost components are inconsistent.');
        }
    }

    /** @param array<string, mixed> $workItem */
    private function requiresFinalizedPrice(array $workItem): bool
    {
        return ! in_array(
            (string) ($workItem['item_type'] ?? 'priced_work'),
            [...EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, EstimateGenerationPackageItem::QUANTITY_REVIEW_ITEM_TYPE],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function overlay(array $workItem, EstimateGenerationPackageItem $item): array
    {
        $resourceProjection = $this->projectResources($item);
        $metadata = is_array($item->metadata) ? $item->metadata : [];

        return array_replace($workItem, [
            'unit' => (string) $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'materials' => $resourceProjection['materials'],
            'labor' => $resourceProjection['labor'],
            'machinery' => $resourceProjection['machinery'],
            'other_resources' => [],
            'materials_cost' => $this->resourceTotal($resourceProjection['materials']),
            'labor_cost' => $this->resourceTotal($resourceProjection['labor']),
            'machinery_cost' => $this->resourceTotal($resourceProjection['machinery']),
            'labor_hours' => $this->resourceQuantityTotal($resourceProjection['labor']),
            'machinery_hours' => $this->resourceQuantityTotal($resourceProjection['machinery']),
            'total_cost' => (string) $item->total_cost,
            'price_source' => $item->price_source,
            'price_snapshot' => $item->price_snapshot,
            'pricing_status' => 'calculated',
            'pricing_finalized_at' => $this->pricingFinalizedAt($item),
            'normative_match' => is_array($metadata['normative_match'] ?? null)
                ? $metadata['normative_match']
                : ($workItem['normative_match'] ?? null),
            'validation_flags' => is_array($item->flags) ? $item->flags : [],
        ]);
    }

    /** @return array{materials: list<array<string, mixed>>, labor: list<array<string, mixed>>, machinery: list<array<string, mixed>>} */
    private function projectResources(EstimateGenerationPackageItem $item): array
    {
        $snapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];
        $stored = $this->storedResources(is_array($item->resources) ? $item->resources : []);
        $workQuantity = $this->positiveDecimal($item->quantity, 'work quantity');
        $formulaVersion = (string) data_get($snapshot, 'coefficients.pricing_formula_version');
        $baseEvidence = data_get($snapshot, 'coefficients.resource_evidence', []);
        $provenance = data_get($snapshot, 'coefficients.provenance.resources', []);
        $projectEvidence = data_get($snapshot, 'coefficients.project_material_evidence', []);

        if (! is_array($baseEvidence) || $baseEvidence === [] || ! is_array($provenance) || $provenance === []) {
            throw new \DomainException('Finalized normative resource evidence is missing.');
        }
        if (! is_array($projectEvidence)) {
            throw new \DomainException('Finalized project material evidence is invalid.');
        }
        if (($formulaVersion === 'supplementary_project_material:v4') !== ($projectEvidence !== [])) {
            throw new \DomainException('Finalized project material evidence does not match the pricing formula.');
        }

        $provenanceByResource = $this->indexByPositiveInt($provenance, 'norm_resource_id', 'resource provenance');
        $resources = [];
        $baseExactTotal = BigDecimal::zero();
        $projectExactTotal = BigDecimal::zero();

        foreach ($baseEvidence as $evidence) {
            if (! is_array($evidence)) {
                throw new \DomainException('Finalized normative resource evidence is invalid.');
            }
            $normResourceId = $this->positiveInt($evidence['norm_resource_id'] ?? null, 'norm resource id');
            $source = $stored['normative'][$normResourceId] ?? null;
            $trace = $provenanceByResource[$normResourceId] ?? null;
            if (! is_array($source) || ! is_array($trace)) {
                throw new \DomainException('Finalized normative resource mapping is incomplete.');
            }
            if ($this->positiveInt($evidence['resource_price_id'] ?? null, 'resource price id')
                !== $this->positiveInt($trace['price_id'] ?? null, 'provenance price id')) {
                throw new \DomainException('Finalized normative resource price mapping is inconsistent.');
            }

            $quantityPerUnit = $this->positiveDecimal($evidence['norm_quantity'] ?? null, 'norm quantity')
                ->multipliedBy($this->positiveDecimal($evidence['work_to_norm_factor'] ?? null, 'work to norm factor'))
                ->multipliedBy($this->positiveDecimal($evidence['conversion_factor'] ?? null, 'conversion factor'));
            $quantity = $workQuantity->multipliedBy($quantityPerUnit);
            $unitPrice = $this->positiveDecimal($evidence['base_price'] ?? null, 'base price');
            $exact = $quantity->multipliedBy($unitPrice);
            $group = $this->resourceGroup((string) ($evidence['resource_type'] ?? ''));
            $resources[] = [
                'group' => $group,
                'exact' => $exact,
                'resource' => array_replace($source, [
                    'name' => trim((string) ($trace['resource_name'] ?? $source['name'] ?? '')),
                    'resource_type' => (string) ($evidence['resource_type'] ?? ''),
                    'unit' => trim((string) ($evidence['price_unit'] ?? '')),
                    'price_unit' => trim((string) ($evidence['price_unit'] ?? '')),
                    'quantity' => $this->decimal($quantity, 6),
                    'quantity_per_unit' => $this->decimal($quantityPerUnit, 12),
                    'quantity_basis' => 'finalized_normative_resource',
                    'unit_price' => $this->decimal($unitPrice, 6),
                    'total_price' => $this->decimal($exact, 2),
                    'price_source' => 'regional_catalog',
                    'price_source_version' => data_get($trace, 'regional_version.version_key'),
                    'source' => 'finalized_package_price_snapshot',
                    'normative_ref' => array_replace(
                        is_array($source['normative_ref'] ?? null) ? $source['normative_ref'] : [],
                        [
                            'norm_resource_id' => $normResourceId,
                            'resource_code' => $evidence['resource_code'] ?? $trace['resource_code'] ?? null,
                            'price_id' => $evidence['resource_price_id'] ?? null,
                            'price_source' => 'regional_catalog',
                        ],
                    ),
                ]),
            ];
            $baseExactTotal = $baseExactTotal->plus($exact);
            unset($stored['normative'][$normResourceId]);
            unset($provenanceByResource[$normResourceId]);
        }

        foreach ($projectEvidence as $evidence) {
            if (! is_array($evidence)) {
                throw new \DomainException('Finalized project material evidence is invalid.');
            }
            $identity = $this->projectMaterialIdentity($evidence);
            $source = $stored['project'][$identity] ?? null;
            if (! is_array($source)) {
                throw new \DomainException('Finalized project material mapping is incomplete.');
            }

            $quantityPerUnit = $this->positiveDecimal($evidence['quantity_per_work_unit'] ?? null, 'project material quantity');
            $quantity = $workQuantity->multipliedBy($quantityPerUnit);
            $unitPrice = $this->positiveDecimal($evidence['base_price'] ?? null, 'project material base price')
                ->multipliedBy($this->positiveDecimal($evidence['price_factor'] ?? null, 'project material price factor'));
            $exact = $quantity->multipliedBy($unitPrice);
            $selection = is_array($source['project_material_selection'] ?? null)
                ? $source['project_material_selection']
                : (is_array(data_get($source, 'normative_ref.project_material_selection'))
                    ? data_get($source, 'normative_ref.project_material_selection')
                    : []);
            $resources[] = [
                'group' => 'materials',
                'exact' => $exact,
                'resource' => array_replace($source, [
                    'name' => trim((string) ($evidence['resource_name'] ?? $source['name'] ?? '')),
                    'resource_type' => 'material',
                    'quantity' => $this->decimal($quantity, 6),
                    'quantity_per_unit' => $this->decimal($quantityPerUnit, 12),
                    'quantity_basis' => 'finalized_project_material',
                    'unit_price' => $this->decimal($unitPrice, 6),
                    'total_price' => $this->decimal($exact, 2),
                    'price_source' => $evidence['price_source'] ?? null,
                    'price_source_version' => $evidence['price_source_version'] ?? null,
                    'source' => 'finalized_package_price_snapshot',
                    'project_material_selection' => array_replace($selection, $evidence),
                    'normative_ref' => array_replace(
                        is_array($source['normative_ref'] ?? null) ? $source['normative_ref'] : [],
                        [
                            'resource_code' => $evidence['resource_code'] ?? null,
                            'price_id' => $evidence['resource_price_id'] ?? null,
                            'price_source' => $evidence['price_source'] ?? null,
                            'price_source_version' => $evidence['price_source_version'] ?? null,
                            'project_material_selection' => array_replace($selection, $evidence),
                        ],
                    ),
                ]),
            ];
            $projectExactTotal = $projectExactTotal->plus($exact);
            unset($stored['project'][$identity]);
        }

        if ($stored['normative'] !== [] || $stored['project'] !== [] || $provenanceByResource !== []) {
            throw new \DomainException('Finalized resource mapping contains unexpected rows.');
        }

        $baseTotal = $baseExactTotal->toScale(2, RoundingMode::HalfUp);
        $projectTotal = $projectExactTotal->toScale(2, RoundingMode::HalfUp);
        if ($formulaVersion === 'supplementary_project_material:v4'
            && $this->money(data_get($snapshot, 'coefficients.project_material_amount'), 'project material amount')
                ->compareTo($projectTotal) !== 0) {
            throw new \DomainException('Finalized project material evidence total is inconsistent.');
        }

        $itemTotal = $this->money($item->total_cost, 'item total');
        if ($baseTotal->plus($projectTotal)->compareTo($itemTotal) !== 0) {
            throw new \DomainException('Finalized resource evidence does not equal the package item total.');
        }

        $resources = $this->allocateRoundingDifference($resources, $itemTotal);
        $projected = ['materials' => [], 'labor' => [], 'machinery' => []];
        foreach ($resources as $entry) {
            $projected[$entry['group']][] = $entry['resource'];
        }

        return $projected;
    }

    /**
     * @param  array<string, mixed>  $resources
     * @return array{normative: array<int, array<string, mixed>>, project: array<string, array<string, mixed>>}
     */
    private function storedResources(array $resources): array
    {
        $indexed = ['normative' => [], 'project' => []];
        foreach (['materials', 'labor', 'machinery', 'other', 'other_resources'] as $group) {
            foreach (is_array($resources[$group] ?? null) ? $resources[$group] : [] as $resource) {
                if (! is_array($resource)) {
                    throw new \DomainException('Stored package resource is invalid.');
                }
                $normResourceId = data_get($resource, 'normative_ref.norm_resource_id', $resource['norm_resource_id'] ?? null);
                if ($normResourceId !== null) {
                    $id = $this->positiveInt($normResourceId, 'stored norm resource id');
                    if (isset($indexed['normative'][$id])) {
                        throw new \DomainException('Stored normative resource mapping is ambiguous.');
                    }
                    $indexed['normative'][$id] = $resource;

                    continue;
                }

                $selection = is_array($resource['project_material_selection'] ?? null)
                    ? $resource['project_material_selection']
                    : data_get($resource, 'normative_ref.project_material_selection');
                if (! is_array($selection)) {
                    throw new \DomainException('Stored package resource has no finalized identity.');
                }
                $identity = $this->projectMaterialIdentity([
                    ...$selection,
                    'resource_code' => $selection['resource_code'] ?? data_get($resource, 'normative_ref.resource_code', $resource['code'] ?? null),
                ]);
                if (isset($indexed['project'][$identity])) {
                    throw new \DomainException('Stored project material mapping is ambiguous.');
                }
                $indexed['project'][$identity] = $resource;
            }
        }

        return $indexed;
    }

    /** @param array<string, mixed> $resource */
    private function projectMaterialIdentity(array $resource): string
    {
        $parts = [];
        foreach (['work_item_key', 'assumption_code', 'resource_code'] as $key) {
            $value = trim((string) ($resource[$key] ?? ''));
            if ($value === '') {
                throw new \DomainException('Project material identity is incomplete.');
            }
            $parts[] = $value;
        }

        return implode('|', $parts);
    }

    /** @param array<int, mixed> $rows @return array<int, array<string, mixed>> */
    private function indexByPositiveInt(array $rows, string $key, string $label): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new \DomainException('Finalized '.$label.' is invalid.');
            }
            $id = $this->positiveInt($row[$key] ?? null, $label.' id');
            if (isset($indexed[$id])) {
                throw new \DomainException('Finalized '.$label.' mapping is ambiguous.');
            }
            $indexed[$id] = $row;
        }

        return $indexed;
    }

    private function resourceGroup(string $resourceType): string
    {
        return match ($resourceType) {
            'material', 'equipment', 'abstract', 'other' => 'materials',
            'labor', 'machine_labor' => 'labor',
            'machine', 'machinery' => 'machinery',
            default => throw new \DomainException('Finalized resource type is unsupported.'),
        };
    }

    private function pricingFinalizedAt(EstimateGenerationPackageItem $item): ?string
    {
        $value = $item->getAttributes()['pricing_finalized_at'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toISOString();
        } catch (\Throwable) {
            throw new \DomainException('Finalized package item timestamp is invalid.');
        }
    }

    /**
     * @param  list<array{group: string, exact: BigDecimal, resource: array<string, mixed>}>  $resources
     * @return list<array{group: string, exact: BigDecimal, resource: array<string, mixed>}>
     */
    private function allocateRoundingDifference(array $resources, BigDecimal $total): array
    {
        if ($resources === []) {
            throw new \DomainException('Finalized resource projection is empty.');
        }

        $rounded = BigDecimal::zero();
        foreach ($resources as $resource) {
            $rounded = $rounded->plus((string) $resource['resource']['total_price']);
        }
        $difference = $total->minus($rounded);
        if ($difference->compareTo(BigDecimal::zero()) === 0) {
            return $resources;
        }

        $last = array_key_last($resources);
        if ($last === null) {
            throw new \DomainException('Finalized resource rounding allocation failed.');
        }
        $adjusted = $this->money($resources[$last]['resource']['total_price'], 'resource total')->plus($difference);
        if ($adjusted->isLessThanOrEqualTo(0)) {
            throw new \DomainException('Finalized resource rounding allocation is invalid.');
        }
        $resources[$last]['resource']['total_price'] = $this->decimal($adjusted, 2);
        $resources[$last]['resource']['rounding_adjustment'] = $this->decimal($difference, 2);

        return $resources;
    }

    /** @param list<array<string, mixed>> $resources */
    private function resourceTotal(array $resources): string
    {
        $total = BigDecimal::zero();
        foreach ($resources as $resource) {
            $total = $total->plus((string) ($resource['total_price'] ?? '0'));
        }

        return $this->decimal($total, 2);
    }

    /** @param list<array<string, mixed>> $resources */
    private function resourceQuantityTotal(array $resources): string
    {
        $total = BigDecimal::zero();
        foreach ($resources as $resource) {
            $total = $total->plus($this->positiveDecimal($resource['quantity'] ?? null, 'resource quantity'));
        }

        return $this->decimal($total, 8);
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \DomainException('Finalized '.$label.' is invalid.');
    }

    private function positiveDecimal(mixed $value, string $label): BigDecimal
    {
        $decimal = $this->number($value, $label);
        if ($decimal->isLessThanOrEqualTo(0)) {
            throw new \DomainException('Finalized '.$label.' must be positive.');
        }

        return $decimal;
    }

    private function money(mixed $value, string $label): BigDecimal
    {
        return $this->number($value, $label)->toScale(2, RoundingMode::Unnecessary);
    }

    private function number(mixed $value, string $label): BigDecimal
    {
        if (! is_int($value) && ! is_string($value) && ! (is_float($value) && is_finite($value))) {
            throw new \DomainException('Finalized '.$label.' is invalid.');
        }

        try {
            return BigDecimal::of((string) $value);
        } catch (\Throwable) {
            throw new \DomainException('Finalized '.$label.' is invalid.');
        }
    }

    private function decimal(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
