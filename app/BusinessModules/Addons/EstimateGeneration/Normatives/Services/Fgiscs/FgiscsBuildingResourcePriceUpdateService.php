<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsBuildingResourcePriceDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\ConstructionResource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePricePeriod;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePriceZone;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture\ResidentialConjuncturePriceImporter;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\FgiscsBuildingResourcePriceSpreadsheetParser;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage\EstimateSourceStorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class FgiscsBuildingResourcePriceUpdateService
{
    private const UPSERT_BATCH_SIZE = 1000;

    private const SOURCE_KINDS = [
        'regional_building_resource_index',
        'regional_building_resource_export',
        'regional_building_resource_direct',
    ];

    public function __construct(
        private readonly FgiscsClient $client,
        private readonly FgiscsRegionalCatalogService $catalogService,
        private readonly FgiscsBuildingResourcePriceSpreadsheetParser $parser,
        private readonly EstimateSourceStorageService $storageService,
        private readonly RegionalPriceImportLifecycleService $lifecycleService,
        private readonly RegionalPriceVersionResolver $versionResolver,
        private readonly FgiscsBuildingResourcePricePriority $pricePriority,
        private readonly ResidentialConjuncturePriceImporter $conjuncturePrices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function syncTatarstan(string $bucket, ?int $periodId = null, bool $force = false, bool $withSplitForm = true, ?callable $progress = null): array
    {
        $catalog = $this->catalogService->syncTatarstan();
        $period = $this->resolvePeriod((int) $catalog['price_zone']->fgiscs_price_zone_id, $periodId);

        return $this->syncPeriod($bucket, $catalog['price_zone'], $period, $force, $withSplitForm, $progress);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncSubject(int $subjectId, string $bucket, ?int $periodId = null, bool $force = false, bool $withSplitForm = true, ?callable $progress = null): array
    {
        $catalog = $this->catalogService->syncSubject($subjectId);

        return $this->syncPriceZones(
            $bucket,
            $catalog['price_zones'],
            $periodId,
            $force,
            $withSplitForm,
            $progress,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncAllRegions(string $bucket, ?int $periodId = null, bool $force = false, bool $withSplitForm = true, ?int $limit = null, ?callable $progress = null): array
    {
        $results = [];

        foreach ($this->catalogService->countrySubjects() as $index => $subject) {
            if ($limit !== null && $index >= $limit) {
                break;
            }

            try {
                array_push($results, ...$this->syncSubject(
                    (int) $subject['id'],
                    $bucket,
                    $periodId,
                    $force,
                    $withSplitForm,
                    $progress,
                ));
            } catch (Throwable $exception) {
                $results[] = [
                    'status' => RegionalPriceStatus::FAILED->value,
                    'subject_id' => $subject['id'],
                    'region' => $subject['name'],
                    'failure_code' => 'fgiscs_building_resource_region_update_failed',
                ];
            }
        }

        return $results;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EstimatePriceZone>  $priceZones
     * @return array<int, array<string, mixed>>
     */
    private function syncPriceZones(string $bucket, Collection $priceZones, ?int $periodId, bool $force, bool $withSplitForm, ?callable $progress): array
    {
        $results = [];

        foreach ($priceZones as $priceZone) {
            $period = $this->resolvePeriod((int) $priceZone->fgiscs_price_zone_id, $periodId);
            $results[] = $this->syncPeriod($bucket, $priceZone, $period, $force, $withSplitForm, $progress);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncPeriod(
        string $bucket,
        EstimatePriceZone $priceZone,
        EstimatePricePeriod $period,
        bool $force,
        bool $withSplitForm,
        ?callable $progress,
    ): array {
        $region = $priceZone->region()->firstOrFail();
        $baseVersionKey = $this->versionKey($period, (string) $region->code, (int) $priceZone->fgiscs_price_zone_id);
        $versionKey = $this->versionResolver->resolveVersionKey(
            EstimateSourceType::FGIS_LABOR_PRICES->value,
            (int) $region->id,
            (int) $priceZone->id,
            (int) $period->id,
            $baseVersionKey,
            'building_resources_imported',
            $force,
        );
        $prefix = $this->prefix($period, (int) $region->fgiscs_subject_id, (int) $priceZone->fgiscs_price_zone_id);

        $regionalVersion = EstimateRegionalPriceVersion::query()->firstOrCreate(
            [
                'source' => EstimateSourceType::FGIS_LABOR_PRICES->value,
                'region_id' => $region->id,
                'price_zone_id' => $priceZone->id,
                'period_id' => $period->id,
                'version_key' => $versionKey,
            ],
            [
                'status' => RegionalPriceStatus::DISCOVERED->value,
                'files_count' => 0,
                'rows_read' => 0,
                'rows_imported' => 0,
                'errors_count' => 0,
                'metadata' => [],
            ]
        );

        if (! $force
            && (bool) ($regionalVersion->metadata['building_resources_imported'] ?? false)
            && (int) $regionalVersion->rows_imported > 0
            && $this->hasImportedBuildingResources($regionalVersion)) {
            $lifecycle = null;

            if (in_array($regionalVersion->status, [
                RegionalPriceStatus::PARSED,
                RegionalPriceStatus::CHECKED,
            ], true) && (bool) ($regionalVersion->metadata['worker_salary_imported'] ?? false)) {
                $lifecycle = $this->lifecycleService->finalize(
                    $regionalVersion,
                    (bool) ($regionalVersion->metadata['activation_requested'] ?? false),
                    true,
                );
                $regionalVersion->refresh();
            }

            return [
                'skipped' => true,
                'reason' => 'building_resources_already_imported',
                'region' => $region->name,
                'price_zone' => $priceZone->name,
                'period' => $period->name,
                'version_id' => $regionalVersion->id,
                'version_key' => $versionKey,
                'status' => $regionalVersion->status->value,
                'activation_id' => $lifecycle['activation_id'] ?? null,
            ];
        }

        $this->versionResolver->assertWritable($regionalVersion);

        $datasetVersion = EstimateDatasetVersion::query()->updateOrCreate(
            [
                'source_type' => EstimateSourceType::FGIS_LABOR_PRICES->value,
                'version_key' => $versionKey,
            ],
            [
                'bucket' => $bucket,
                'prefix' => $prefix,
                'status' => EstimateImportStatus::IMPORTING->value,
                'files_count' => $withSplitForm ? 2 : 1,
                'started_at' => now(),
                'finished_at' => null,
                'meta' => [
                    'region_code' => $region->code,
                    'region_id' => $region->id,
                    'price_zone_id' => $priceZone->fgiscs_price_zone_id,
                    'fgiscs_period_id' => $period->fgiscs_period_id,
                    'regional' => true,
                    'building_resources' => true,
                ],
            ]
        );

        $downloads = [];
        $files = [
            'building-resources.xlsx' => fn () => $this->client->downloadBuildingResources((int) $priceZone->fgiscs_price_zone_id, (int) $period->fgiscs_period_id),
        ];

        if ($withSplitForm) {
            $files['building-resources-split-form.xlsx'] = fn () => $this->client->downloadBuildingResourcesSplitForm((int) $priceZone->fgiscs_price_zone_id, (int) $period->fgiscs_period_id);
        }

        foreach ($files as $fileName => $download) {
            $fileKey = $prefix.$fileName;
            $this->report($progress, 'download_started', ['file' => $fileKey]);
            $downloads[$fileKey] = $download();
            $this->storageService->disk($bucket)->put($fileKey, $downloads[$fileKey]->content);
        }

        $regionalVersion->update([
            'status' => RegionalPriceStatus::DOWNLOADED->value,
            'files_count' => count($downloads),
            'metadata' => array_merge($regionalVersion->metadata ?? [], [
                'building_resources_files' => array_map(
                    static fn ($download): array => [
                        'file_name' => $download->fileName,
                        'content_type' => $download->contentType,
                    ],
                    $downloads
                ),
            ]),
        ]);

        $stats = $this->importDownloads($downloads, $datasetVersion, $regionalVersion, $progress);
        $conjunctureStats = $this->conjuncturePrices->import(
            $datasetVersion,
            $regionalVersion,
            (string) $region->code,
        );
        $datasetVersion->update([
            'status' => EstimateImportStatus::PARSED->value,
            'files_count' => count($downloads),
            'rows_read' => $stats['rows_read'],
            'rows_imported' => $stats['rows_imported'],
            'errors_count' => $stats['errors_count'],
            'finished_at' => now(),
        ]);

        $regionalVersion->update([
            'status' => RegionalPriceStatus::PARSED->value,
            'files_count' => max((int) $regionalVersion->files_count, count($downloads)),
            'rows_read' => max((int) $regionalVersion->rows_read, $stats['rows_read']),
            'rows_imported' => (int) EstimateResourcePrice::query()
                ->where('regional_price_version_id', $regionalVersion->id)
                ->count(),
            'errors_count' => $stats['errors_count'],
            'metadata' => array_merge($regionalVersion->metadata ?? [], [
                'building_resources_imported' => true,
                'building_resources_imported_at' => now()->toIso8601String(),
                'building_resources_stats' => $stats,
                'project_material_conjuncture_checked' => true,
                'project_material_conjuncture_checked_at' => now()->toIso8601String(),
                'project_material_conjuncture_complete' => $conjunctureStats['missing'] === 0,
                'project_material_conjuncture_stats' => $conjunctureStats,
            ]),
        ]);

        $activationRequested = (bool) ($regionalVersion->metadata['activation_requested'] ?? false);
        $lifecycle = $this->lifecycleService->finalize($regionalVersion, $activationRequested, true);

        return array_merge($stats, [
            'status' => $regionalVersion->fresh()->status->value,
            'region' => $region->name,
            'price_zone' => $priceZone->name,
            'period' => $period->name,
            'version_id' => $regionalVersion->id,
            'version_key' => $versionKey,
            'files_count' => count($downloads),
            'activation_id' => $lifecycle['activation_id'],
            'complete_quality' => $lifecycle['quality'],
            'project_material_conjuncture' => $conjunctureStats,
        ]);
    }

    private function hasImportedBuildingResources(EstimateRegionalPriceVersion $regionalVersion): bool
    {
        return EstimateResourcePrice::query()
            ->where('regional_price_version_id', $regionalVersion->id)
            ->whereIn('source_price_kind', self::SOURCE_KINDS)
            ->exists();
    }

    /**
     * @param  array<string, \App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsDownloadDTO>  $downloads
     * @return array{rows_read:int,rows_imported:int,errors_count:int}
     */
    private function importDownloads(array $downloads, EstimateDatasetVersion $datasetVersion, EstimateRegionalPriceVersion $regionalVersion, ?callable $progress): array
    {
        $regionalVersion->update(['status' => RegionalPriceStatus::PARSING->value]);
        $rowsRead = 0;
        $rowsImported = 0;
        $errorsCount = 0;
        $batches = array_fill_keys(self::SOURCE_KINDS, []);

        try {
            EstimateResourcePrice::query()
                ->where('regional_price_version_id', $regionalVersion->id)
                ->whereIn('source_price_kind', self::SOURCE_KINDS)
                ->delete();

            foreach ($downloads as $fileKey => $download) {
                $path = tempnam(sys_get_temp_dir(), 'fgiscs-building-resources-').'.xlsx';
                file_put_contents($path, $download->content);

                try {
                    foreach ($this->parser->parse($path) as $price) {
                        $rowsRead++;

                        if (! $price instanceof FgiscsBuildingResourcePriceDTO) {
                            continue;
                        }

                        if (! array_key_exists($price->sourcePriceKind, $batches)) {
                            throw new RuntimeException('Unsupported regional building resource price source.');
                        }

                        $resourceCode = trim($price->code);
                        $batches[$price->sourcePriceKind][$resourceCode] = $this->pricePriority->preferred(
                            $batches[$price->sourcePriceKind][$resourceCode] ?? null,
                            $price,
                        );

                        if (count($batches[$price->sourcePriceKind]) >= self::UPSERT_BATCH_SIZE) {
                            $rowsImported += $this->upsertPriceBatch(
                                array_values($batches[$price->sourcePriceKind]),
                                $datasetVersion,
                                $regionalVersion,
                            );
                            $batches[$price->sourcePriceKind] = [];
                            $this->persistImportProgress($regionalVersion, $datasetVersion, $rowsRead, $rowsImported);
                        }

                        $this->reportRowsProgress($progress, $fileKey, $rowsRead, $rowsImported, $errorsCount);
                    }
                } finally {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }

            foreach ($batches as $batch) {
                if ($batch === []) {
                    continue;
                }

                $rowsImported += $this->upsertPriceBatch(array_values($batch), $datasetVersion, $regionalVersion);
                $this->persistImportProgress($regionalVersion, $datasetVersion, $rowsRead, $rowsImported);
            }
            $rowsImported = (int) EstimateResourcePrice::query()
                ->where('regional_price_version_id', $regionalVersion->id)
                ->whereIn('source_price_kind', self::SOURCE_KINDS)
                ->count();
        } catch (Throwable $exception) {
            $errorsCount++;
            $this->recordImportFailure(
                $regionalVersion,
                $datasetVersion,
                $rowsRead,
                $errorsCount,
            );

            throw $exception;
        }

        return [
            'rows_read' => $rowsRead,
            'rows_imported' => $rowsImported,
            'errors_count' => $errorsCount,
        ];
    }

    private function persistImportProgress(
        EstimateRegionalPriceVersion $regionalVersion,
        EstimateDatasetVersion $datasetVersion,
        int $rowsRead,
        int $rowsImported,
    ): void {
        $regionalVersion->update([
            'rows_read' => $rowsRead,
            'rows_imported' => $rowsImported,
        ]);
        $datasetVersion->update([
            'rows_read' => $rowsRead,
            'rows_imported' => $rowsImported,
        ]);
    }

    private function recordImportFailure(
        EstimateRegionalPriceVersion $regionalVersion,
        EstimateDatasetVersion $datasetVersion,
        int $rowsRead,
        int $errorsCount,
    ): void {
        $this->attemptFailureStatusUpdate('regional_version', static fn (): bool => $regionalVersion->update([
            'status' => RegionalPriceStatus::FAILED->value,
            'errors_count' => max(1, (int) $regionalVersion->errors_count + 1),
            'metadata' => array_merge($regionalVersion->metadata ?? [], [
                'building_resources_imported' => false,
                'building_resources_failure_code' => 'building_resource_import_failed',
            ]),
        ]));
        $this->attemptFailureStatusUpdate('dataset_version', static fn (): bool => $datasetVersion->update([
            'status' => EstimateImportStatus::FAILED->value,
            'rows_read' => $rowsRead,
            'rows_imported' => 0,
            'errors_count' => $errorsCount,
            'finished_at' => now(),
        ]));
    }

    private function attemptFailureStatusUpdate(string $target, callable $update): void
    {
        try {
            $update();
        } catch (Throwable $statusException) {
            try {
                Log::warning('[EstimateGeneration] Failed to record building resource import failure status.', [
                    'target' => $target,
                    'exception_class' => $statusException::class,
                    'exception_code' => $statusException->getCode(),
                ]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param  list<FgiscsBuildingResourcePriceDTO>  $prices
     */
    private function upsertPriceBatch(
        array $prices,
        EstimateDatasetVersion $datasetVersion,
        EstimateRegionalPriceVersion $regionalVersion,
    ): int {
        if ($prices === []) {
            return 0;
        }

        $resourceCodes = array_values(array_unique(array_map(
            static fn (FgiscsBuildingResourcePriceDTO $price): string => trim($price->code),
            $prices,
        )));
        $existingSourceKinds = EstimateResourcePrice::query()
            ->where('dataset_version_id', $datasetVersion->id)
            ->where('price_type', EstimateResourceType::MATERIAL->value)
            ->whereIn('resource_code', $resourceCodes)
            ->pluck('source_price_kind', 'resource_code')
            ->all();
        $prices = array_values(array_filter(
            $prices,
            fn (FgiscsBuildingResourcePriceDTO $price): bool => $this->pricePriority->shouldReplace(
                (string) ($existingSourceKinds[trim($price->code)] ?? ''),
                $price,
            ),
        ));

        if ($prices === []) {
            return 0;
        }

        $resourceIds = ConstructionResource::query()
            ->whereIn('ksr_code', $resourceCodes)
            ->orderByDesc('dataset_version_id')
            ->get(['id', 'ksr_code'])
            ->unique('ksr_code')
            ->mapWithKeys(static fn (ConstructionResource $resource): array => [$resource->ksr_code => $resource->id])
            ->all();
        $timestamp = now();
        $rows = array_map(static function (FgiscsBuildingResourcePriceDTO $price) use ($datasetVersion, $regionalVersion, $resourceIds, $timestamp): array {
            $resourceCode = trim($price->code);

            return [
                'dataset_version_id' => $datasetVersion->id,
                'regional_price_version_id' => $regionalVersion->id,
                'region_id' => $regionalVersion->region_id,
                'price_zone_id' => $regionalVersion->price_zone_id,
                'period_id' => $regionalVersion->period_id,
                'construction_resource_id' => $resourceIds[$resourceCode] ?? null,
                'resource_code' => $resourceCode,
                'resource_name' => $price->name,
                'unit' => $price->unit,
                'base_price' => $price->currentPrice,
                'price_type' => EstimateResourceType::MATERIAL->value,
                'source_price_kind' => $price->sourcePriceKind,
                'raw_payload' => json_encode($price->rawData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }, $prices);

        EstimateResourcePrice::query()->upsert(
            $rows,
            ['dataset_version_id', 'resource_code', 'price_type'],
            [
                'regional_price_version_id',
                'region_id',
                'price_zone_id',
                'period_id',
                'construction_resource_id',
                'resource_name',
                'unit',
                'base_price',
                'source_price_kind',
                'raw_payload',
                'updated_at',
            ],
        );

        return count($rows);
    }

    private function resolvePeriod(int $priceZoneId, ?int $periodId): EstimatePricePeriod
    {
        if ($periodId !== null) {
            $this->catalogService->syncPeriods($priceZoneId);

            return EstimatePricePeriod::query()
                ->where('fgiscs_period_id', $periodId)
                ->first() ?? throw new RuntimeException('Указанный период ФГИС ЦС не найден.');
        }

        return $this->catalogService->latestPeriod($priceZoneId);
    }

    private function versionKey(EstimatePricePeriod $period, string $regionCode, int $priceZoneId): string
    {
        if ($regionCode === FgiscsRegionalCatalogService::DEFAULT_REGION_CODE && $priceZoneId === FgiscsRegionalCatalogService::TATARSTAN_PRICE_ZONE_ID) {
            return sprintf('%d-q%d-ru-ta', $period->year, $period->quarter);
        }

        return sprintf('%d-q%d-%s-pz-%d', $period->year, $period->quarter, strtolower($regionCode), $priceZoneId);
    }

    private function prefix(EstimatePricePeriod $period, int $subjectId, int $priceZoneId): string
    {
        return sprintf(
            'estimate-sources/fgiscs/building-resources/%d-q%d/region-%d/price-zone-%d/',
            $period->year,
            $period->quarter,
            $subjectId,
            $priceZoneId
        );
    }

    private function reportRowsProgress(?callable $progress, string $fileKey, int $rowsRead, int $rowsImported, int $errorsCount): void
    {
        if ($rowsRead === 1 || $rowsRead % 1000 === 0) {
            $this->report($progress, 'rows_progress', [
                'file' => $fileKey,
                'rows_read' => $rowsRead,
                'rows_imported' => $rowsImported,
                'errors_count' => $errorsCount,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(?callable $progress, string $event, array $payload): void
    {
        if ($progress !== null) {
            $progress($event, $payload);
        }
    }
}
