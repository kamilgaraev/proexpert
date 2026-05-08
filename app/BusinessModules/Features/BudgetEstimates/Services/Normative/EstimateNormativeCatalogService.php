<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Normative;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Repositories\EstimateItemRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EstimateNormativeCatalogService
{
    public function __construct(
        protected EstimateItemRepository $itemRepository,
        protected EstimateCalculationService $calculationService,
    ) {}

    public function search(array $filters): LengthAwarePaginator
    {
        $version = $this->latestFsnbVersion();
        $priceVersions = $this->latestPriceVersions();

        $query = EstimateNorm::query()
            ->with(['collection', 'section'])
            ->withCount([
                'resources' => static fn (Builder $builder) => $builder
                    ->where('resource_type', '<>', EstimateResourceType::SUMMARY->value),
            ])
            ->when($version !== null, function (Builder $query) use ($version): void {
                $query->whereHas('collection', static fn (Builder $builder) => $builder->where('dataset_version_id', $version->id));
            })
            ->when($version === null, static fn (Builder $query) => $query->whereRaw('1 = 0'));

        if (($filters['query'] ?? null) !== null) {
            $search = mb_strtolower(trim((string) $filters['query']));
            $query->where(function (Builder $builder) use ($search): void {
                $builder->whereRaw('LOWER(code) LIKE ?', ['%' . $search . '%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $search . '%'])
                    ->orWhereRaw('LOWER(COALESCE(section_name, \'\')) LIKE ?', ['%' . $search . '%']);
            });
        }

        if (($filters['norm_type'] ?? null) !== null) {
            $query->whereHas('collection', static fn (Builder $builder) => $builder->where('norm_type', $filters['norm_type']));
        }

        if (($filters['collection_id'] ?? null) !== null) {
            $query->where('collection_id', (int) $filters['collection_id']);
        }

        if (($filters['section_id'] ?? null) !== null) {
            $query->where('section_id', (int) $filters['section_id']);
        }

        $paginator = $query
            ->orderBy('code')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $paginator->getCollection()->transform(fn (EstimateNorm $norm): array => $this->normPayload($norm, $priceVersions, false));

        if (($filters['has_prices'] ?? false) === true) {
            $paginator->setCollection($paginator->getCollection()->filter(
                static fn (array $item): bool => (int) ($item['priced_resources_count'] ?? 0) > 0
            )->values());
        }

        return $paginator;
    }

    public function detail(EstimateNorm $norm): array
    {
        return $this->normPayload($norm->load(['collection', 'section', 'resources']), $this->latestPriceVersions(), true);
    }

    public function addItemsFromNormatives(Estimate $estimate, array $items): array
    {
        return DB::transaction(function () use ($estimate, $items): array {
            $created = [];
            $priceVersions = $this->latestPriceVersions();

            foreach ($items as $itemData) {
                $norm = EstimateNorm::query()
                    ->with(['collection.datasetVersion', 'section', 'resources'])
                    ->findOrFail((int) $itemData['estimate_norm_id']);
                $sectionId = $this->resolveSectionId($estimate, $itemData['estimate_section_id'] ?? null);
                $quantity = (float) $itemData['quantity'];
                $resources = $this->resourcesPayload($norm, $priceVersions);
                $totals = $this->resourceTotals($resources, $quantity);
                $positionNumber = $itemData['position_number'] ?? $this->itemRepository->getNextPositionNumber($estimate->id);

                $work = EstimateItem::query()->create([
                    'estimate_id' => $estimate->id,
                    'estimate_section_id' => $sectionId,
                    'item_type' => EstimatePositionItemType::WORK->value,
                    'position_number' => $positionNumber,
                    'name' => $norm->name,
                    'description' => implode('; ', array_slice($norm->work_composition ?? [], 0, 10)),
                    'normative_rate_code' => $norm->code,
                    'measurement_unit_id' => $this->resolveMeasurementUnitId($estimate->organization_id, (string) $norm->unit),
                    'quantity' => $quantity,
                    'quantity_total' => $quantity,
                    'unit_price' => $quantity > 0 ? round($totals['total'] / $quantity, 4) : 0,
                    'materials_cost' => $totals['materials'],
                    'machinery_cost' => $totals['machinery'],
                    'labor_cost' => $totals['labor'],
                    'labor_hours' => $totals['labor_hours'],
                    'machinery_hours' => $totals['machinery_hours'],
                    'direct_costs' => $totals['total'],
                    'total_amount' => $totals['total'],
                    'current_total_amount' => $totals['total'],
                    'justification' => $norm->code,
                    'is_manual' => false,
                    'metadata' => [
                        'normative_source' => 'estimate_norms',
                        'estimate_norm_id' => $norm->id,
                        'normative_dataset' => [
                            'source_type' => $norm->collection?->datasetVersion?->source_type?->value,
                            'version_key' => $norm->collection?->datasetVersion?->version_key,
                        ],
                        'price_datasets' => $priceVersions->map(static fn (EstimateDatasetVersion $version): array => [
                            'source_type' => $version->source_type->value,
                            'version_key' => $version->version_key,
                        ])->values()->all(),
                        'price_dataset' => $priceVersions->first() !== null ? [
                            'source_type' => $priceVersions->first()->source_type->value,
                            'version_key' => $priceVersions->first()->version_key,
                        ] : null,
                    ],
                ]);

                $this->persistResources($work, $resources, $quantity, $estimate->organization_id);
                $this->calculationService->calculateItemTotal($work->fresh(), $estimate);

                if ($work->section) {
                    $this->calculationService->calculateSectionTotal($work->section);
                }

                $created[] = $this->freshWorkWithChildren($work);
            }

            $this->calculationService->calculateEstimateTotal($estimate);

            return $created;
        });
    }

    private function normPayload(EstimateNorm $norm, Collection $priceVersions, bool $includeResources): array
    {
        $resources = $includeResources ? $this->resourcesPayload($norm, $priceVersions) : collect();

        return [
            'id' => $norm->id,
            'code' => $norm->code,
            'name' => $norm->name,
            'unit' => $norm->unit,
            'section_code' => $norm->section_code,
            'section_name' => $norm->section_name,
            'collection' => [
                'id' => $norm->collection?->id,
                'code' => $norm->collection?->code,
                'name' => $norm->collection?->name,
                'norm_type' => $norm->collection?->norm_type?->value,
            ],
            'section' => [
                'id' => $norm->section?->id,
                'code' => $norm->section?->code,
                'name' => $norm->section?->name,
                'type' => $norm->section?->section_type,
                'path' => $norm->section?->path,
            ],
            'resources_count' => (int) ($norm->resources_count ?? $norm->resources()
                ->where('resource_type', '<>', EstimateResourceType::SUMMARY->value)
                ->count()),
            'priced_resources_count' => $includeResources ? $resources->whereNotNull('price_source')->count() : null,
            'resources' => $includeResources ? $resources->values()->all() : null,
        ];
    }

    private function resourcesPayload(EstimateNorm $norm, Collection $priceVersions): Collection
    {
        $resources = $norm->resources()
            ->where('resource_type', '<>', EstimateResourceType::SUMMARY->value)
            ->orderBy('id')
            ->get();
        $prices = $priceVersions->isNotEmpty()
            ? EstimateResourcePrice::query()
                ->whereIn('dataset_version_id', $priceVersions->pluck('id')->values()->all())
                ->whereIn('resource_code', $resources->pluck('resource_code')->filter()->values()->all())
                ->orderByDesc('dataset_version_id')
                ->get()
                ->groupBy('resource_code')
            : collect();

        $priceSourceById = $priceVersions->mapWithKeys(
            static fn (EstimateDatasetVersion $version): array => [$version->id => $version->source_type->value . '_base']
        );

        return $resources->flatMap(function ($resource) use ($prices, $priceSourceById): array {
            $type = $resource->resource_type?->value ?? EstimateResourceType::OTHER->value;
            $price = $this->resolvePrice($prices->get($resource->resource_code) ?? collect(), $type, (string) ($resource->unit ?? ''));
            $payload = [
                'resource_code' => $resource->resource_code,
                'name' => $resource->resource_name,
                'resource_type' => $type,
                'unit' => $resource->unit,
                'quantity_per_unit' => $resource->quantity !== null ? (float) $resource->quantity : 0.0,
                'unit_price' => $this->effectiveUnitPrice($price, $type),
                'price_id' => $price?->id,
                'price_source' => $price !== null ? ($priceSourceById[(int) $price->dataset_version_id] ?? null) : null,
                'pricing' => $this->pricePayload($price),
                'construction_resource_id' => $resource->construction_resource_id,
            ];

            $machineLabor = $this->machineLaborResource($payload);

            return $machineLabor !== null ? [$payload, $machineLabor] : [$payload];
        });
    }

    private function persistResources(EstimateItem $work, Collection $resources, float $workQuantity, int $organizationId): void
    {
        foreach ($resources as $index => $resource) {
            $quantity = round((float) $resource['quantity_per_unit'] * $workQuantity, 6);
            $total = round($quantity * (float) $resource['unit_price'], 2);
            $itemType = $this->estimateItemType((string) $resource['resource_type']);

            $child = EstimateItem::query()->create([
                'estimate_id' => $work->estimate_id,
                'estimate_section_id' => $work->estimate_section_id,
                'parent_work_id' => $work->id,
                'item_type' => $itemType,
                'position_number' => $work->position_number . '.' . ($index + 1),
                'name' => $resource['name'] ?? $resource['resource_code'],
                'description' => 'fsnb_norm_resource',
                'normative_rate_code' => $resource['resource_code'],
                'measurement_unit_id' => $this->resolveMeasurementUnitId($organizationId, (string) $resource['unit']),
                'quantity' => $quantity,
                'quantity_total' => $quantity,
                'unit_price' => $resource['unit_price'],
                'labor_hours' => $itemType === EstimatePositionItemType::LABOR->value ? $quantity : 0,
                'machinery_hours' => $itemType === EstimatePositionItemType::MACHINERY->value ? $quantity : 0,
                'direct_costs' => $total,
                'materials_cost' => $itemType === EstimatePositionItemType::MATERIAL->value ? $total : 0,
                'machinery_cost' => $itemType === EstimatePositionItemType::MACHINERY->value ? $total : 0,
                'labor_cost' => $itemType === EstimatePositionItemType::LABOR->value ? $total : 0,
                'total_amount' => $total,
                'current_total_amount' => $total,
                'is_manual' => false,
                'metadata' => [
                    'normative_ref' => $resource,
                    'quantity_per_unit' => $resource['quantity_per_unit'],
                    'machine_labor_breakdown' => $this->machineLaborBreakdown($resource, $quantity),
                ],
            ]);

            $this->calculationService->calculateItemTotal($child, $work->estimate);

            EstimateItemResource::query()->create([
                'estimate_item_id' => $work->id,
                'resource_type' => $itemType === EstimatePositionItemType::MACHINERY->value ? 'equipment' : $itemType,
                'name' => $resource['name'] ?? $resource['resource_code'],
                'description' => $resource['resource_code'],
                'measurement_unit_id' => $this->resolveMeasurementUnitId($organizationId, (string) $resource['unit']),
                'quantity_per_unit' => $resource['quantity_per_unit'],
                'total_quantity' => $quantity,
                'unit_price' => $resource['unit_price'],
                'total_amount' => $total,
            ]);
        }
    }

    private function freshWorkWithChildren(EstimateItem $work): EstimateItem
    {
        $freshWork = $work->fresh(['measurementUnit', 'resources']) ?? $work;
        $children = EstimateItem::query()
            ->with(['measurementUnit', 'resources'])
            ->where('parent_work_id', $work->id)
            ->orderBy('id')
            ->get();

        return $freshWork->setRelation('childItems', $children);
    }

    private function resourceTotals(Collection $resources, float $workQuantity): array
    {
        $totals = [
            'materials' => 0.0,
            'machinery' => 0.0,
            'labor' => 0.0,
            'total' => 0.0,
            'labor_hours' => 0.0,
            'machinery_hours' => 0.0,
        ];

        foreach ($resources as $resource) {
            $quantity = (float) $resource['quantity_per_unit'] * $workQuantity;
            $total = round($quantity * (float) $resource['unit_price'], 2);
            $itemType = $this->estimateItemType((string) $resource['resource_type']);

            if ($itemType === EstimatePositionItemType::MACHINERY->value) {
                $totals['machinery'] += $total;
                $totals['machinery_hours'] += $quantity;
            } elseif ($itemType === EstimatePositionItemType::LABOR->value) {
                $totals['labor'] += $total;
                $totals['labor_hours'] += $quantity;
            } else {
                $totals['materials'] += $total;
            }

            $totals['total'] += $total;
        }

        return array_map(static fn (float $value): float => round($value, 2), $totals);
    }

    private function estimateItemType(string $resourceType): string
    {
        return match ($resourceType) {
            EstimateResourceType::MACHINE->value => EstimatePositionItemType::MACHINERY->value,
            EstimateResourceType::LABOR->value, EstimateResourceType::MACHINE_LABOR->value => EstimatePositionItemType::LABOR->value,
            default => EstimatePositionItemType::MATERIAL->value,
        };
    }

    private function pricePayload(?EstimateResourcePrice $price): array
    {
        return [
            'base_price' => $price?->base_price !== null ? (float) $price->base_price : 0.0,
            'machine_salary_price' => $price?->machine_salary_price !== null ? (float) $price->machine_salary_price : null,
            'machine_price_without_salary' => $price?->machine_price_without_salary !== null ? (float) $price->machine_price_without_salary : null,
            'machine_labor_quantity' => $price?->machine_labor_quantity !== null ? (float) $price->machine_labor_quantity : null,
            'driver_code' => $price?->driver_code,
            'machinist_category' => $price?->machinist_category,
            'source_price_kind' => $price?->source_price_kind,
        ];
    }

    private function effectiveUnitPrice(?EstimateResourcePrice $price, string $resourceType): float
    {
        if ($price === null) {
            return 0.0;
        }

        if ($resourceType === EstimateResourceType::MACHINE->value && $price->machine_price_without_salary !== null) {
            return (float) $price->machine_price_without_salary;
        }

        return $price->base_price !== null ? (float) $price->base_price : 0.0;
    }

    private function machineLaborResource(array $machineResource): ?array
    {
        $pricing = $machineResource['pricing'] ?? [];
        $driverCode = $pricing['driver_code'] ?? null;
        $machinistCategory = $pricing['machinist_category'] ?? null;
        $machineLaborQuantity = (float) ($pricing['machine_labor_quantity'] ?? 0);
        $machineSalaryPrice = (float) ($pricing['machine_salary_price'] ?? 0);

        if ((string) $machineResource['resource_type'] !== EstimateResourceType::MACHINE->value || $driverCode === null || $machineLaborQuantity <= 0 || $machineSalaryPrice <= 0) {
            return null;
        }

        return [
            'resource_code' => $driverCode,
            'name' => trim('ОТм(ЗТм) Средний разряд машинистов ' . (string) $machinistCategory),
            'resource_type' => EstimateResourceType::MACHINE_LABOR->value,
            'unit' => 'чел.-ч',
            'quantity_per_unit' => round((float) $machineResource['quantity_per_unit'] * $machineLaborQuantity, 6),
            'unit_price' => $machineSalaryPrice,
            'price_id' => null,
            'price_source' => $machineResource['price_source'] ?? null,
            'pricing' => [
                'base_price' => $machineSalaryPrice,
                'machine_salary_price' => null,
                'machine_price_without_salary' => null,
                'machine_labor_quantity' => null,
                'driver_code' => $driverCode,
                'machinist_category' => $machinistCategory,
                'source_price_kind' => 'fsbc_machine_salary',
            ],
            'construction_resource_id' => null,
            'derived_from_resource_code' => $machineResource['resource_code'] ?? null,
        ];
    }

    private function machineLaborBreakdown(array $resource, float $totalQuantity): ?array
    {
        $pricing = $resource['pricing'] ?? [];
        $driverCode = $pricing['driver_code'] ?? null;
        $machinistCategory = $pricing['machinist_category'] ?? null;
        $machineLaborQuantity = (float) ($pricing['machine_labor_quantity'] ?? 0);
        $machineSalaryPrice = (float) ($pricing['machine_salary_price'] ?? 0);

        if ((string) $resource['resource_type'] !== EstimateResourceType::MACHINE->value || $driverCode === null || $machineLaborQuantity <= 0 || $machineSalaryPrice <= 0) {
            return null;
        }

        $quantity = round($machineLaborQuantity * $totalQuantity, 6);

        return [
            'name' => trim('ОТм(ЗТм) Средний разряд машинистов ' . (string) $machinistCategory),
            'resource_code' => $driverCode,
            'unit' => 'чел.-ч',
            'quantity' => $quantity,
            'unit_price' => $machineSalaryPrice,
            'total' => round($quantity * $machineSalaryPrice, 2),
        ];
    }

    private function resolveSectionId(Estimate $estimate, ?int $sectionId): ?int
    {
        if ($sectionId === null) {
            return null;
        }

        return EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->where('id', $sectionId)
            ->value('id');
    }

    private function resolveMeasurementUnitId(int $organizationId, string $unit): ?int
    {
        $normalized = mb_strtolower(trim($unit));

        return MeasurementUnit::query()
            ->where(function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->where(function (Builder $query) use ($normalized): void {
                $query->whereRaw('LOWER(short_name) = ?', [$normalized])
                    ->orWhereRaw('LOWER(name) = ?', [$normalized]);
            })
            ->value('id');
    }

    private function resolvePrice(Collection $prices, string $resourceType, string $unit): ?EstimateResourcePrice
    {
        if ($prices->isEmpty()) {
            return null;
        }

        $preferredType = $resourceType === EstimateResourceType::EQUIPMENT->value
            ? EstimateResourceType::MATERIAL->value
            : $resourceType;

        return $prices->first(function (EstimateResourcePrice $price) use ($preferredType, $unit): bool {
            return ($price->price_type?->value ?? $price->price_type) === $preferredType
                && mb_strtolower((string) $price->unit) === mb_strtolower($unit);
        }) ?? $prices->first(function (EstimateResourcePrice $price) use ($preferredType): bool {
            return ($price->price_type?->value ?? $price->price_type) === $preferredType;
        }) ?? $prices->first();
    }

    private function latestFsnbVersion(): ?EstimateDatasetVersion
    {
        return EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FSNB_2022->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->latest('id')
            ->first();
    }

    private function latestPriceVersions(): Collection
    {
        $fsbcVersion = EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FSBC->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->whereHas('resourcePrices')
            ->latest('id')
            ->first();

        $fallbackFsnbVersion = null;

        if ($fsbcVersion === null) {
            $fallbackFsnbVersion = EstimateDatasetVersion::query()
                ->where('source_type', EstimateSourceType::FSNB_2022->value)
                ->where('status', EstimateImportStatus::PARSED->value)
                ->whereHas('resourcePrices')
                ->latest('id')
                ->first();
        }

        $laborVersion = EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FGIS_LABOR_PRICES->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->whereHas('resourcePrices')
            ->latest('id')
            ->first();

        return collect([$fsbcVersion ?? $fallbackFsnbVersion, $laborVersion])->filter()->values();
    }
}
