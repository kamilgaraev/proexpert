<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateItemMetadataFactory;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EstimateGenerationPackagePersistenceService
{
    private const NORMATIVE_PRICING_FORMULA_VERSION = 'semantic_project_resource:v8';

    private const PROJECT_MATERIAL_PRICING_FORMULA_VERSION = 'supplementary_project_material:v4';

    private const PRICING_CALCULATION_IDENTITY = 'authoritative_package_pricing:v1';

    public function __construct(
        private readonly ?AuthoritativePackagePricingGuard $pricingGuard = null,
        private readonly EstimateGenerationNoAirWorkItemPolicy $noAirWorkItemPolicy = new EstimateGenerationNoAirWorkItemPolicy,
        private readonly ?SessionBaseInputVersionResolver $baseInputVersions = null,
        private readonly GeneratedEstimateItemMetadataFactory $itemMetadata = new GeneratedEstimateItemMetadataFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     */
    public function syncFromDraft(EstimateGenerationSession $session, array $draft, ?string $verifiedInputVersion = null): void
    {
        $sourceInputVersion = $draft['source_input_version'] ?? null;
        DB::transaction(function () use ($session, $draft, $sourceInputVersion, $verifiedInputVersion): void {
            $inputVersion = $verifiedInputVersion ?? $this->baseInputVersions?->resolve($session);
            $sourceInputCurrent = $this->sourceInputCurrent($sourceInputVersion, $inputVersion);
            $activePackageKeys = $this->draftPackageKeys($draft);

            foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
                if (! is_array($localEstimate)) {
                    continue;
                }

                $this->syncLocalEstimate(
                    $session,
                    $localEstimate,
                    (int) $localIndex,
                    $inputVersion,
                    $sourceInputVersion,
                    $sourceInputCurrent,
                );
            }

            $this->retainHistoricalPackages($session, $activePackageKeys);
        });
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function syncWorkItemPackageFromDraft(EstimateGenerationSession $session, array $draft, string $workItemKey): bool
    {
        $sourceInputVersion = $draft['source_input_version'] ?? null;

        return DB::transaction(function () use ($session, $draft, $workItemKey, $sourceInputVersion): bool {
            $inputVersion = $this->baseInputVersions?->resolve($session);
            $sourceInputCurrent = $this->sourceInputCurrent($sourceInputVersion, $inputVersion);
            foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
                if (! is_array($localEstimate) || ! $this->localEstimateContainsWorkItem($localEstimate, $workItemKey)) {
                    continue;
                }
                $this->syncLocalEstimate(
                    $session,
                    $localEstimate,
                    (int) $localIndex,
                    $inputVersion,
                    $sourceInputVersion,
                    $sourceInputCurrent,
                );

                return true;
            }

            return false;
        });
    }

    /** @param array<string, mixed> $draft */
    public function assertCalculatedPricesFinalized(EstimateGenerationSession $session, array $draft): void
    {
        $expected = [];
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }
            $packageKey = $this->packageKey($localEstimate, (int) $localIndex);
            foreach ($this->workItems($localEstimate) as $workIndex => $workItem) {
                if ((string) ($workItem['item_type'] ?? 'priced_work') !== 'priced_work'
                    || ! in_array((string) ($workItem['pricing_status'] ?? ''), ['calculated', 'calculated_review_required'], true)) {
                    continue;
                }
                $logicalKey = (string) ($workItem['key'] ?? $packageKey.'.item.'.($workIndex + 1));
                $expected[$packageKey.'|'.$logicalKey] = $this->hasProjectMaterialSelection($workItem)
                    ? self::PROJECT_MATERIAL_PRICING_FORMULA_VERSION
                    : self::NORMATIVE_PRICING_FORMULA_VERSION;
            }
        }
        if ($expected === []) {
            return;
        }

        $persisted = EstimateGenerationPackageItem::query()
            ->select('estimate_generation_package_items.*', 'estimate_generation_packages.key as package_key')
            ->join('estimate_generation_packages', 'estimate_generation_packages.id', '=', 'estimate_generation_package_items.package_id')
            ->where('estimate_generation_packages.session_id', $session->id)
            ->whereIn('estimate_generation_packages.key', array_values(array_unique(array_map(
                static fn (string $key): string => explode('|', $key, 2)[0],
                array_keys($expected),
            ))))
            ->latestLogicalRevisions()
            ->get();
        foreach ($persisted as $item) {
            $identity = (string) $item->getAttribute('package_key').'|'.(string) ($item->logical_key ?? $item->key);
            if (! isset($expected[$identity])) {
                continue;
            }
            if ($item->pricing_finalized_at !== null
                && (float) ($item->total_cost ?? 0) > 0
                && data_get($item->price_snapshot, 'coefficients.pricing_formula_version') === $expected[$identity]) {
                unset($expected[$identity]);
            }
        }

        if ($expected !== []) {
            throw new \DomainException('Calculated draft prices were not finalized by the authoritative pricing boundary.');
        }
    }

    /** @param array<string, mixed> $workItem */
    private function hasProjectMaterialSelection(array $workItem): bool
    {
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            foreach ($workItem[$group] ?? [] as $resource) {
                if (is_array($resource) && is_array($resource['project_material_selection'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    private function draftPackageKeys(array $draft): array
    {
        $keys = [];

        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            $keys[] = $this->packageKey($localEstimate, (int) $localIndex);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     */
    private function packageKey(array $localEstimate, int $localIndex): string
    {
        return (string) ($localEstimate['key'] ?? 'package-'.($localIndex + 1));
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     */
    private function syncLocalEstimate(
        EstimateGenerationSession $session,
        array $localEstimate,
        int $localIndex,
        ?string $inputVersion,
        mixed $sourceInputVersion,
        bool $sourceInputCurrent,
    ): void {
        $workItems = $sourceInputCurrent ? $this->estimateWorkItems($this->workItems($localEstimate)) : [];
        $quality = $this->packageQuality($localEstimate, $workItems);
        if (! $sourceInputCurrent) {
            $quality = [
                'level' => 'blocked',
                'critical_flags' => ['stale_input_version'],
                'warning_flags' => [],
            ];
        }
        $itemCounters = $this->itemCounters($workItems);
        $totalCost = $this->workItemsTotal($workItems);
        $packageKey = $this->packageKey($localEstimate, $localIndex);
        $package = EstimateGenerationPackage::query()->updateOrCreate(
            [
                'session_id' => $session->id,
                'key' => $packageKey,
            ],
            [
                'input_version' => $inputVersion,
                'title' => (string) ($localEstimate['title'] ?? 'Локальная смета'),
                'scope_type' => (string) ($localEstimate['scope_type'] ?? 'custom'),
                'status' => $this->packageStatus($quality),
                'generation_stage' => 'quality_check',
                'generation_progress' => 100,
                'target_items_min' => (int) ($localEstimate['target_items_min'] ?? 0),
                'target_items_max' => (int) ($localEstimate['target_items_max'] ?? 0),
                'actual_items_count' => $itemCounters['total_items_count'],
                'totals' => [
                    'total_cost' => $totalCost,
                    ...$itemCounters,
                ],
                'quality_summary' => $quality,
                'assumptions' => $localEstimate['assumptions'] ?? [],
                'source_refs' => $localEstimate['source_refs'] ?? [],
                'metadata' => [
                    'generated_from' => 'estimate_generation_v2',
                    'input_version' => $inputVersion,
                    'source_input_version' => is_string($sourceInputVersion) ? $sourceInputVersion : null,
                    'coverage_warnings' => is_array($localEstimate['coverage_warnings'] ?? null)
                        ? array_values(array_filter($localEstimate['coverage_warnings'], 'is_array'))
                        : [],
                ],
                'sort_order' => ($localIndex + 1) * 100,
                'finished_at' => now(),
                'failed_at' => null,
                'cancelled_at' => null,
                'last_error_code' => null,
            ]
        );

        if (! $sourceInputCurrent) {
            return;
        }

        foreach ($workItems as $workIndex => $workItem) {
            $this->appendItemRevision($package, $workItem, $workIndex);
        }

        $this->supersedeMissingItemRevisions($package, $workItems);

        $this->refreshPackagePricingState($package);
    }

    private function sourceInputCurrent(mixed $sourceInputVersion, ?string $inputVersion): bool
    {
        return is_string($sourceInputVersion)
            && preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceInputVersion) === 1
            && is_string($inputVersion)
            && preg_match('/^sha256:[a-f0-9]{64}$/D', $inputVersion) === 1
            && hash_equals($inputVersion, $sourceInputVersion);
    }

    private function refreshPackagePricingState(EstimateGenerationPackage $package): void
    {
        $latestIds = DB::table('estimate_generation_package_items as revisions')
            ->select('revisions.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY COALESCE(revisions.logical_key, revisions.key) ORDER BY revisions.revision DESC, revisions.id DESC) AS revision_rank')
            ->where('revisions.package_id', $package->id);
        $aggregate = DB::query()->fromSub($latestIds, 'latest')
            ->join('estimate_generation_package_items as item', 'item.id', '=', 'latest.id')
            ->where('latest.revision_rank', 1)
            ->selectRaw("SUM(CASE WHEN item.item_type NOT IN ('operation', 'resource_note', 'review_note') THEN 1 ELSE 0 END) AS total_items_count")
            ->selectRaw("SUM(CASE WHEN item.item_type = 'priced_work' AND item.pricing_finalized_at IS NOT NULL THEN 1 ELSE 0 END) AS priced_items_count")
            ->selectRaw("SUM(CASE WHEN item.item_type = 'priced_work' AND item.pricing_finalized_at IS NULL THEN 1 ELSE 0 END) AS unfinalized_items_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN item.item_type = 'priced_work' AND item.pricing_finalized_at IS NOT NULL THEN item.total_cost ELSE 0 END), 0) AS total_cost")
            ->first();
        $totalItemsCount = (int) ($aggregate->total_items_count ?? 0);
        $pricedItemsCount = (int) ($aggregate->priced_items_count ?? 0);
        $unfinalized = (int) ($aggregate->unfinalized_items_count ?? 0) > 0;
        $total = BigDecimal::of((string) ($aggregate->total_cost ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $quality = is_array($package->quality_summary) ? $package->quality_summary : [];
        $criticalFlags = array_values(array_filter(
            (array) ($quality['critical_flags'] ?? []),
            static fn (mixed $flag): bool => $flag !== 'missing_price_snapshot',
        ));
        if ($unfinalized) {
            $criticalFlags[] = 'missing_price_snapshot';
        }
        $quality['critical_flags'] = array_values(array_unique($criticalFlags));
        $quality['level'] = $unfinalized
            ? 'blocked'
            : ($quality['critical_flags'] === [] ? 'passed' : 'review_required');
        $totals = is_array($package->totals) ? $package->totals : [];
        $totals['total_cost'] = (string) $total;
        $totals['priced_items_count'] = $pricedItemsCount;
        $totals['total_items_count'] = $totalItemsCount;
        $totals['items_count'] = $totalItemsCount;
        $package->forceFill([
            'status' => $this->packageStatus($quality),
            'generation_progress' => $unfinalized ? 99 : 100,
            'finished_at' => $unfinalized ? null : now(),
            'actual_items_count' => $totalItemsCount,
            'quality_summary' => $quality,
            'totals' => $totals,
        ])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $workItems
     */
    private function supersedeMissingItemRevisions(EstimateGenerationPackage $package, array $workItems): void
    {
        EstimateGenerationPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
        $activeKeys = array_fill_keys(array_map(
            fn (array $workItem, int $index): string => (string) ($workItem['key'] ?? $package->key.'.item.'.($index + 1)),
            $workItems,
            array_keys($workItems),
        ), true);
        $latestItems = EstimateGenerationPackageItem::query()
            ->where('package_id', $package->id)
            ->latestLogicalRevisions()
            ->lockForUpdate()
            ->get();

        foreach ($latestItems as $latest) {
            $logicalKey = (string) ($latest->logical_key ?? $latest->key);
            if (isset($activeKeys[$logicalKey]) || in_array($latest->item_type, EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true)) {
                continue;
            }

            EstimateGenerationPackageItem::query()->create([
                'package_id' => $package->id,
                'key' => $logicalKey.'#r'.((int) $latest->revision + 1),
                'logical_key' => $logicalKey,
                'revision' => (int) $latest->revision + 1,
                'supersedes_item_id' => $latest->id,
                'parent_key' => $latest->parent_key,
                'level' => (int) $latest->level,
                'item_type' => 'operation',
                'name' => $latest->name,
                'unit' => null,
                'quantity' => null,
                'quantity_basis' => [],
                'price_source' => null,
                'price_snapshot' => null,
                'quantity_evidence_id' => null,
                'quantity_evidence_fingerprint' => null,
                'estimate_norm_id' => null,
                'region_id' => null,
                'price_zone_id' => null,
                'period_id' => null,
                'regional_price_version_id' => null,
                'pricing_finalized_at' => null,
                'normative_status' => null,
                'normative_confidence' => null,
                'unit_price' => '0.000000',
                'direct_cost' => '0.00',
                'overhead_cost' => '0.00',
                'profit_cost' => '0.00',
                'total_cost' => '0.00',
                'resources' => [],
                'flags' => [],
                'metadata' => ['superseded_by_regeneration' => true],
                'sort_order' => (int) $latest->sort_order,
            ]);
        }
    }

    private function appendItemRevision(EstimateGenerationPackage $package, array $workItem, int $index): void
    {
        EstimateGenerationPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
        $logicalKey = (string) ($workItem['key'] ?? $package->key.'.item.'.($index + 1));
        $latest = EstimateGenerationPackageItem::query()
            ->where('package_id', $package->id)
            ->where(fn ($query) => $query->where('logical_key', $logicalKey)->orWhere(fn ($legacy) => $legacy->whereNull('logical_key')->where('key', $logicalKey)))
            ->orderByDesc('revision')->orderByDesc('id')->lockForUpdate()->first();
        $revision = max(1, (int) ($latest?->revision ?? 0) + 1);
        $pricing = $this->authoritativePricing($package, $workItem, $logicalKey);
        if ($latest !== null && $pricing !== null && $this->samePricingIdentity($package, $latest, $pricing)) {
            return;
        }
        $payload = $this->itemPayload($package, $workItem, $index);
        if ((string) ($workItem['item_type'] ?? 'priced_work') === 'priced_work') {
            $payload = array_replace($payload, [
                'price_snapshot' => null, 'price_source' => null, 'unit_price' => '0.000000',
                'direct_cost' => '0.00', 'overhead_cost' => '0.00', 'profit_cost' => '0.00', 'total_cost' => '0.00',
            ]);
        }
        if ($pricing !== null) {
            $payload = array_replace($payload, $pricing['item']);
        }
        $payload['logical_key'] = $logicalKey;
        $payload['revision'] = $revision;
        $payload['supersedes_item_id'] = $latest?->id;
        $payload['key'] = $logicalKey.'#r'.$revision;

        if ($latest !== null && $this->revisionFingerprint($latest->getAttributes()) === $this->revisionFingerprint($payload)) {
            return;
        }

        $item = EstimateGenerationPackageItem::query()->create($payload);
        if ($pricing === null) {
            return;
        }
        foreach ($pricing['inputs'] as $ordinal => $input) {
            DB::table('estimate_generation_package_item_price_inputs')->insert([
                ...$input,
                'package_item_id' => $item->id,
                'ordinal' => $ordinal + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ($pricing['project_material_inputs'] as $ordinal => $input) {
            DB::table('estimate_generation_package_item_project_price_inputs')->insert([
                'package_item_id' => $item->id,
                'ordinal' => $ordinal + 1,
                'resource_price_id' => $input['resource_price_id'],
                'project_material_rule_id' => $input['project_material_rule_id'],
                'selection' => json_encode($input['selection'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->reportPricingInputCardinalityMismatch($item, $pricing['inputs'], $workItem);
        $this->reportPricingInputContractMismatch($item);
        try {
            DB::select('SELECT public.eg_finalize_package_item_price(?)', [$item->id]);
        } catch (QueryException $exception) {
            Log::warning('estimate_generation.pricing_finalization_rejected', [
                'package_item_id' => $item->id,
                'estimate_norm_id' => $item->estimate_norm_id,
                'logical_key' => $item->logical_key,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** @param list<array<string, int|null>> $inputs @param array<string, mixed> $workItem */
    private function reportPricingInputCardinalityMismatch(
        EstimateGenerationPackageItem $item,
        array $inputs,
        array $workItem,
    ): void {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $expectedIds = DB::table('estimate_norm_resources')
            ->where('estimate_norm_id', $item->estimate_norm_id)
            ->where('quantity', '>', 0)
            ->where('resource_type', '<>', 'summary')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $actualIds = array_map(static fn (array $input): int => (int) $input['norm_resource_id'], $inputs);
        sort($actualIds, SORT_NUMERIC);

        if ($expectedIds === $actualIds) {
            return;
        }

        Log::warning('estimate_generation.pricing_input_cardinality_mismatch', [
            'package_item_id' => $item->id,
            'estimate_norm_id' => $item->estimate_norm_id,
            'norm_code' => data_get($workItem, 'normative_match.code'),
            'work_name' => $workItem['name'] ?? null,
            'expected_count' => count($expectedIds),
            'actual_count' => count($actualIds),
            'missing_norm_resource_ids' => array_values(array_diff($expectedIds, $actualIds)),
            'unexpected_norm_resource_ids' => array_values(array_diff($actualIds, $expectedIds)),
        ]);
    }

    private function reportPricingInputContractMismatch(EstimateGenerationPackageItem $item): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $mismatches = DB::table('estimate_generation_package_item_price_inputs as inputs')
            ->join('estimate_norm_resources as resources', 'resources.id', '=', 'inputs.norm_resource_id')
            ->join('estimate_resource_prices as prices', 'prices.id', '=', 'inputs.resource_price_id')
            ->leftJoin('estimate_generation_unit_conversions as conversions', 'conversions.id', '=', 'inputs.unit_conversion_id')
            ->leftJoin(
                'estimate_generation_pinned_abstract_resource_conversions as abstract_conversions',
                'abstract_conversions.id',
                '=',
                'inputs.pinned_abstract_resource_conversion_id',
            )
            ->leftJoin('estimate_regional_price_versions as versions', 'versions.id', '=', 'prices.regional_price_version_id')
            ->where('inputs.package_item_id', $item->id)
            ->where(function ($query) use ($item): void {
                $query->where('resources.estimate_norm_id', '<>', $item->estimate_norm_id)
                    ->orWhereRaw(
                        "CASE WHEN resources.resource_code ~ '^[0-9]{2}\\.[0-9]\\.[0-9]{2}\\.[0-9]{2}$' ".
                        "THEN prices.resource_code !~ ('^'||replace(resources.resource_code, '.', '\\\\.')||'-[0-9]{4}$') ".
                        'ELSE prices.resource_code IS DISTINCT FROM resources.resource_code END',
                    )
                    ->orWhere('prices.region_id', '<>', $item->region_id)
                    ->orWhere('prices.price_zone_id', '<>', $item->price_zone_id)
                    ->orWhere('prices.period_id', '<>', $item->period_id)
                    ->orWhere('prices.regional_price_version_id', '<>', $item->regional_price_version_id)
                    ->orWhere('versions.status', '<>', 'active')
                    ->orWhereNull('prices.base_price')
                    ->orWhere('prices.base_price', '<=', 0)
                    ->orWhere(function ($unit) {
                        $unit->whereColumn('prices.unit', '<>', 'resources.unit')
                            ->where(function ($conversion) {
                                $conversion->whereNull('conversions.id')
                                    ->orWhereColumn('conversions.from_unit', '<>', 'resources.unit')
                                    ->orWhereColumn('conversions.to_unit', '<>', 'prices.unit')
                                    ->orWhere('conversions.factor', '<=', 0);
                            });
                    })
                    ->orWhere(function ($unit) {
                        $unit->whereColumn('prices.unit', '=', 'resources.unit')
                            ->whereNotNull('conversions.id');
                    });
            })
            ->orderBy('inputs.ordinal')
            ->get([
                'inputs.ordinal', 'resources.id as norm_resource_id', 'resources.resource_code as norm_resource_code',
                'resources.unit as norm_unit', 'prices.id as price_id', 'prices.resource_code as price_resource_code',
                'prices.unit as price_unit', 'prices.region_id', 'prices.price_zone_id', 'prices.period_id',
                'prices.regional_price_version_id', 'prices.base_price', 'versions.status as version_status',
                'conversions.id as conversion_id', 'conversions.from_unit', 'conversions.to_unit', 'conversions.factor',
                'inputs.pinned_abstract_resource_conversion_id', 'abstract_conversions.rule_key as abstract_conversion_rule_key',
                'abstract_conversions.version as abstract_conversion_rule_version',
            ]);

        if ($mismatches->isNotEmpty()) {
            Log::warning('estimate_generation.pricing_input_contract_mismatch', [
                'package_item_id' => $item->id,
                'estimate_norm_id' => $item->estimate_norm_id,
                'context' => [
                    'region_id' => $item->region_id,
                    'price_zone_id' => $item->price_zone_id,
                    'period_id' => $item->period_id,
                    'regional_price_version_id' => $item->regional_price_version_id,
                ],
                'inputs' => $mismatches->map(static fn (object $input): array => (array) $input)->all(),
            ]);
        }
    }

    /** @param array{item: array<string, mixed>, inputs: list<array<string, int|null>>, project_material_inputs: list<array<string, mixed>>, formula_version: string} $pricing */
    private function samePricingIdentity(
        EstimateGenerationPackage $package,
        EstimateGenerationPackageItem $latest,
        array $pricing,
    ): bool {
        $metadata = is_array($latest->metadata) ? $latest->metadata : [];
        if (! is_string($package->input_version)
            || ! is_string($metadata['source_input_version'] ?? null)
            || ! hash_equals($package->input_version, $metadata['source_input_version'])
            || ($metadata['pricing_calculation_identity'] ?? null) !== self::PRICING_CALCULATION_IDENTITY) {
            return false;
        }
        if (data_get($latest->price_snapshot, 'coefficients.pricing_formula_version') !== $pricing['formula_version']) {
            return false;
        }
        foreach (['quantity_evidence_id', 'quantity_evidence_fingerprint', 'estimate_norm_id', 'region_id', 'price_zone_id', 'period_id', 'regional_price_version_id'] as $column) {
            if ((string) $latest->getAttribute($column) !== (string) ($pricing['item'][$column] ?? null)) {
                return false;
            }
        }
        $stored = DB::table('estimate_generation_package_item_price_inputs')->where('package_item_id', $latest->id)
            ->orderBy('ordinal')->get(['norm_resource_id', 'resource_price_id', 'unit_conversion_id'])
            ->map(static fn (object $input): array => [
                'norm_resource_id' => (int) $input->norm_resource_id,
                'resource_price_id' => (int) $input->resource_price_id,
                'unit_conversion_id' => $input->unit_conversion_id === null ? null : (int) $input->unit_conversion_id,
            ])->all();

        if ($stored !== $pricing['inputs']) {
            return false;
        }

        $projectMaterials = DB::table('estimate_generation_package_item_project_price_inputs')
            ->where('package_item_id', $latest->id)
            ->orderBy('ordinal')
            ->get(['resource_price_id', 'project_material_rule_id', 'selection'])
            ->map(static fn (object $input): array => [
                'resource_price_id' => (int) $input->resource_price_id,
                'project_material_rule_id' => (int) $input->project_material_rule_id,
                'selection' => is_string($input->selection)
                    ? json_decode($input->selection, true, flags: JSON_THROW_ON_ERROR)
                    : (array) $input->selection,
            ])->all();

        return CanonicalPipelineJson::encode($projectMaterials)
            === CanonicalPipelineJson::encode($pricing['project_material_inputs']);
    }

    /** @return array{item: array<string, mixed>, inputs: list<array<string, int|null>>, project_material_inputs: list<array<string, mixed>>, formula_version: string}|null */
    private function authoritativePricing(EstimateGenerationPackage $package, array $workItem, string $logicalKey): ?array
    {
        if ((string) ($workItem['item_type'] ?? 'priced_work') !== 'priced_work') {
            return null;
        }
        $pricingStatus = $workItem['pricing_status'] ?? null;
        if (($workItem['pricing_blocker'] ?? null) !== null
            || (is_string($pricingStatus) && ! in_array($pricingStatus, ['calculated', 'calculated_review_required'], true))) {
            return null;
        }
        $snapshot = is_array($workItem['price_snapshot'] ?? null) ? $workItem['price_snapshot'] : [];
        $normId = $this->positiveInt($workItem['normative_match']['norm_id'] ?? null);
        $context = [
            'region_id' => $this->positiveInt($snapshot['region_id'] ?? null),
            'price_zone_id' => $this->positiveInt($snapshot['zone_id'] ?? null),
            'period_id' => $this->positiveInt($snapshot['period_id'] ?? null),
            'regional_price_version_id' => $this->positiveInt($snapshot['version_id'] ?? null),
        ];
        if ($normId === null || in_array(null, $context, true)) {
            return null;
        }
        $quantity = $this->positiveDecimal($workItem['quantity'] ?? null);
        $unit = $workItem['unit'] ?? null;
        if ($quantity === null || ! is_string($unit) || $unit === '') {
            return null;
        }
        $session = $package->session()->firstOrFail();
        $evidenceId = $this->positiveInt($workItem['quantity_evidence_id'] ?? null);
        $evidenceFingerprint = $workItem['quantity_evidence_fingerprint'] ?? null;
        $evidenceSourceVersion = $package->input_version;
        if ($evidenceId === null || ! is_string($evidenceFingerprint)
            || ! is_string($evidenceSourceVersion) || preg_match('/^sha256:[a-f0-9]{64}$/D', $evidenceSourceVersion) !== 1
            || $this->pricingGuard === null) {
            return null;
        }
        $inputs = $this->pricingGuard->inputs(
            (int) $session->organization_id,
            (int) $session->project_id,
            (int) $session->id,
            $evidenceSourceVersion,
            $workItem,
        );
        if ($inputs === null) {
            return null;
        }
        $projectMaterialInputs = $this->pricingGuard->projectMaterialInputs(
            (int) $session->organization_id,
            (int) $session->project_id,
            (int) $session->id,
            $evidenceSourceVersion,
            $workItem,
        );
        if ($projectMaterialInputs === null) {
            return null;
        }

        return ['item' => [
            'price_snapshot' => null,
            'price_source' => null,
            'quantity' => null,
            'unit_price' => '0.000000',
            'direct_cost' => '0.00',
            'overhead_cost' => '0.00',
            'profit_cost' => '0.00',
            'total_cost' => '0.00',
            'quantity_evidence_id' => $evidenceId,
            'quantity_evidence_fingerprint' => $evidenceFingerprint,
            'estimate_norm_id' => $normId,
            ...$context,
        ],
            'inputs' => $inputs,
            'project_material_inputs' => $projectMaterialInputs,
            'formula_version' => $projectMaterialInputs === []
                ? self::NORMATIVE_PRICING_FORMULA_VERSION
                : self::PROJECT_MATERIAL_PRICING_FORMULA_VERSION,
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function positiveDecimal(mixed $value): ?BigDecimal
    {
        if (! is_int($value) && ! is_string($value) && ! (is_float($value) && is_finite($value))) {
            return null;
        }

        try {
            $decimal = BigDecimal::of((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return $decimal->isGreaterThan(0) ? $decimal : null;
    }

    private function revisionFingerprint(array $payload): string
    {
        unset($payload['id'], $payload['key'], $payload['revision'], $payload['supersedes_item_id'], $payload['created_at'], $payload['updated_at']);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     */
    private function localEstimateContainsWorkItem(array $localEstimate, string $workItemKey): bool
    {
        foreach ($localEstimate['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach ($section['work_items'] ?? [] as $workItem) {
                if (is_array($workItem) && (string) ($workItem['key'] ?? '') === $workItemKey) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $activePackageKeys
     */
    private function retainHistoricalPackages(EstimateGenerationSession $session, array $activePackageKeys): void
    {
        $query = EstimateGenerationPackage::query()
            ->where('session_id', $session->id);

        if ($activePackageKeys !== []) {
            $query->whereNotIn('key', $activePackageKeys);
        }

        $query->update(['status' => 'superseded']);
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     * @return array<int, array<string, mixed>>
     */
    private function workItems(array $localEstimate): array
    {
        $items = [];

        foreach ($localEstimate['sections'] ?? [] as $section) {
            foreach ($section['work_items'] ?? [] as $workItem) {
                if (is_array($workItem)) {
                    $items[] = $workItem;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $workItems
     * @return array<int, array<string, mixed>>
     */
    private function estimateWorkItems(array $workItems): array
    {
        $workItems = array_values(array_filter(
            $workItems,
            fn (array $workItem): bool => $this->isEstimateWorkItem($workItem)
        ));

        return array_map(
            fn (array $workItem): array => $this->noAirWorkItemPolicy->requiresReview($workItem)
                ? $this->noAirWorkItemPolicy->markRequiresReview($workItem)
                : $workItem,
            $workItems
        );
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function isEstimateWorkItem(array $workItem): bool
    {
        return ! in_array((string) ($workItem['item_type'] ?? 'priced_work'), EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     * @param  array<int, array<string, mixed>>  $workItems
     * @return array<string, mixed>
     */
    private function packageQuality(array $localEstimate, array $workItems): array
    {
        $critical = [];
        $warnings = [];

        $targetItemsMin = (int) ($localEstimate['target_items_min'] ?? 0);
        $counters = $this->itemCounters($workItems);
        $pricedTargetMin = min(10, max(3, (int) ceil(max($targetItemsMin, 1) / 7)));

        if ($counters['priced_items_count'] < $pricedTargetMin && $counters['quantity_review_items_count'] === 0) {
            $critical[] = 'insufficient_detail';
        } elseif ($counters['quantity_review_items_count'] > 0) {
            $warnings[] = 'quantity_review_required';
        }

        foreach ($workItems as $workItem) {
            foreach ($workItem['validation_flags'] ?? [] as $flag) {
                if (in_array($flag, ['missing_price', 'missing_resources'], true)) {
                    $critical[] = (string) $flag;

                    continue;
                }

                if (in_array($flag, [
                    EstimateGenerationNoAirWorkItemPolicy::FLAG,
                    EstimateGenerationNoAirWorkItemPolicy::NO_AIR_FLAG,
                    'pricing_not_calculated',
                    'safe_norm_required',
                ], true)) {
                    $critical[] = (string) $flag;

                    continue;
                }

                $warnings[] = (string) $flag;
            }
        }

        $critical = array_values(array_unique($critical));
        $warnings = array_values(array_unique($warnings));

        return [
            'level' => $critical === [] ? 'passed' : 'review_required',
            'critical_flags' => $critical,
            'warning_flags' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $quality
     */
    private function packageStatus(array $quality): string
    {
        return match ((string) ($quality['level'] ?? 'review_required')) {
            'passed' => 'ready_for_review',
            'blocked' => 'blocked',
            default => 'review_required',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $workItems
     * @return array<string, int>
     */
    private function itemCounters(array $workItems): array
    {
        $priced = 0;
        $quantityReviews = 0;

        foreach ($workItems as $workItem) {
            $type = (string) ($workItem['item_type'] ?? 'priced_work');

            if ($type === EstimateGenerationPackageItem::QUANTITY_REVIEW_ITEM_TYPE) {
                $quantityReviews++;

                continue;
            }

            if (in_array($type, EstimateGenerationPackageItem::SERVICE_ITEM_TYPES, true)) {
                continue;
            }

            $priced++;
        }

        $visibleItems = $priced + $quantityReviews;

        return [
            'items_count' => $visibleItems,
            'total_items_count' => $visibleItems,
            'priced_items_count' => $priced,
            'quantity_review_items_count' => $quantityReviews,
            'operation_items_count' => 0,
            'review_notes_count' => 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $workItems
     */
    private function workItemsTotal(array $workItems): string
    {
        $total = BigDecimal::zero();
        foreach ($workItems as $workItem) {
            $total = $total->plus((string) ($workItem['total_cost'] ?? '0'));
        }

        return (string) $total->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<string, mixed>
     */
    private function itemPayload(EstimateGenerationPackage $package, array $workItem, int $index): array
    {
        $workComposition = $this->workComposition($workItem);
        $quantityCalculation = $this->itemMetadata->quantityCalculation($workItem);

        return [
            'package_id' => $package->id,
            'key' => (string) ($workItem['key'] ?? $package->key.'.item.'.($index + 1)),
            'parent_key' => $workItem['parent_key'] ?? null,
            'level' => (int) ($workItem['level'] ?? 0),
            'item_type' => (string) ($workItem['item_type'] ?? 'work'),
            'name' => (string) ($workItem['name'] ?? 'Работа'),
            'unit' => $workItem['unit'] ?? null,
            'quantity' => isset($workItem['quantity']) ? (string) BigDecimal::of((string) $workItem['quantity']) : null,
            'quantity_basis' => $quantityCalculation,
            'price_source' => $workItem['price_source'] ?? null,
            'price_snapshot' => $workItem['price_snapshot'] ?? null,
            'normative_status' => $workItem['normative_match']['status'] ?? null,
            'normative_confidence' => isset($workItem['normative_match']['confidence'])
                ? (float) $workItem['normative_match']['confidence']
                : null,
            'unit_price' => $this->unitPrice($workItem),
            'direct_cost' => (string) BigDecimal::of((string) ($workItem['materials_cost'] ?? '0'))
                ->plus((string) ($workItem['labor_cost'] ?? '0'))
                ->plus((string) ($workItem['machinery_cost'] ?? '0'))
                ->toScale(2, RoundingMode::HalfUp),
            'overhead_cost' => '0.00',
            'profit_cost' => '0.00',
            'total_cost' => (string) ($workItem['total_cost'] ?? '0.00'),
            'resources' => [
                'materials' => $workItem['materials'] ?? [],
                'labor' => $workItem['labor'] ?? [],
                'machinery' => $workItem['machinery'] ?? [],
                'other' => $workItem['other_resources'] ?? [],
            ],
            'flags' => $workItem['validation_flags'] ?? [],
            'metadata' => [
                'normative_match' => $workItem['normative_match'] ?? null,
                'normative_candidates' => $workItem['normative_candidates'] ?? [],
                'pricing_status' => $workItem['pricing_status'] ?? null,
                'pricing_blocker' => $workItem['pricing_blocker'] ?? null,
                'pricing_blocker_message' => $workItem['pricing_blocker_message'] ?? null,
                'source_refs' => $workItem['source_refs'] ?? [],
                'work_category' => $workItem['work_category'] ?? null,
                'confidence' => $workItem['confidence'] ?? null,
                ...($workItem['metadata'] ?? []),
                'specialization_scenario' => is_array($workItem['specialization_scenario'] ?? null)
                    ? $workItem['specialization_scenario']
                    : (is_array($workItem['metadata']['specialization_scenario'] ?? null)
                        ? $workItem['metadata']['specialization_scenario']
                        : null),
                'quantity_evidence' => is_array($workItem['quantity_evidence'] ?? null)
                    ? $workItem['quantity_evidence']
                    : null,
                'quantity_calculation' => $quantityCalculation,
                'applied_price' => $this->itemMetadata->appliedPrice($workItem),
                'work_composition' => $workComposition,
                'composition_items_count' => count($workComposition),
                'source_input_version' => $package->input_version,
                'pricing_calculation_identity' => self::PRICING_CALCULATION_IDENTITY,
            ],
            'sort_order' => ($index + 1) * 100,
        ];
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<int, string>
     */
    private function workComposition(array $workItem): array
    {
        $composition = $workItem['work_composition']
            ?? $workItem['metadata']['work_composition']
            ?? $workItem['normative_match']['work_composition']
            ?? [];

        if (! is_array($composition)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $composition),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function unitPrice(array $workItem): string
    {
        $quantity = BigDecimal::of((string) ($workItem['quantity'] ?? '0'));

        if ($quantity->isLessThanOrEqualTo(0)) {
            return '0.000000';
        }

        return (string) BigDecimal::of((string) ($workItem['total_cost'] ?? '0'))
            ->dividedBy($quantity, 6, RoundingMode::HalfUp);
    }
}
