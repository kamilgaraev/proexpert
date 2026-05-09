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
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\FgiscsBuildingResourcePriceSpreadsheetParser;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage\EstimateSourceStorageService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FgiscsBuildingResourcePriceUpdateService
{
    private const SOURCE_KINDS = [
        'regional_building_resource_export',
        'regional_building_resource_direct',
        'regional_building_resource_index',
    ];

    public function __construct(
        private readonly FgiscsClient $client,
        private readonly FgiscsRegionalCatalogService $catalogService,
        private readonly FgiscsBuildingResourcePriceSpreadsheetParser $parser,
        private readonly EstimateSourceStorageService $storageService,
    ) {
    }

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
        $versionKey = $this->versionKey($period, (string) $region->code, (int) $priceZone->fgiscs_price_zone_id);
        $prefix = $this->prefix($period, (int) $region->fgiscs_subject_id, (int) $priceZone->fgiscs_price_zone_id);

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

        if (!$force && (bool) ($regionalVersion->metadata['building_resources_imported'] ?? false)) {
            return [
                'skipped' => true,
                'reason' => 'building_resources_already_imported',
                'region' => $region->name,
                'price_zone' => $priceZone->name,
                'period' => $period->name,
                'version_id' => $regionalVersion->id,
                'version_key' => $versionKey,
                'status' => $regionalVersion->status->value,
            ];
        }

        $downloads = [];
        $files = [
            'building-resources.xlsx' => fn () => $this->client->downloadBuildingResources((int) $priceZone->fgiscs_price_zone_id, (int) $period->fgiscs_period_id),
        ];

        if ($withSplitForm) {
            $files['building-resources-split-form.xlsx'] = fn () => $this->client->downloadBuildingResourcesSplitForm((int) $priceZone->fgiscs_price_zone_id, (int) $period->fgiscs_period_id);
        }

        foreach ($files as $fileName => $download) {
            $fileKey = $prefix . $fileName;
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

        $initialStatus = $regionalVersion->status;
        $stats = $this->importDownloads($downloads, $datasetVersion, $regionalVersion, $progress);

        $datasetVersion->update([
            'status' => EstimateImportStatus::PARSED->value,
            'files_count' => count($downloads),
            'rows_read' => $stats['rows_read'],
            'rows_imported' => $stats['rows_imported'],
            'errors_count' => $stats['errors_count'],
            'finished_at' => now(),
        ]);

        $regionalVersion->update([
            'status' => $this->finalStatus($initialStatus),
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
            ]),
        ]);

        return array_merge($stats, [
            'status' => $regionalVersion->fresh()->status->value,
            'region' => $region->name,
            'price_zone' => $priceZone->name,
            'period' => $period->name,
            'version_id' => $regionalVersion->id,
            'version_key' => $versionKey,
            'files_count' => count($downloads),
        ]);
    }

    /**
     * @param array<string, \App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsDownloadDTO> $downloads
     * @return array{rows_read:int,rows_imported:int,errors_count:int}
     */
    private function importDownloads(array $downloads, EstimateDatasetVersion $datasetVersion, EstimateRegionalPriceVersion $regionalVersion, ?callable $progress): array
    {
        $regionalVersion->update(['status' => RegionalPriceStatus::PARSING->value]);
        $rowsRead = 0;
        $rowsImported = 0;
        $errorsCount = 0;

        DB::transaction(function () use ($downloads, $datasetVersion, $regionalVersion, $progress, &$rowsRead, &$rowsImported, &$errorsCount): void {
            EstimateResourcePrice::query()
                ->where('regional_price_version_id', $regionalVersion->id)
                ->whereIn('source_price_kind', self::SOURCE_KINDS)
                ->delete();

            foreach ($downloads as $fileKey => $download) {
                $path = tempnam(sys_get_temp_dir(), 'fgiscs-building-resources-') . '.xlsx';
                file_put_contents($path, $download->content);

                try {
                    foreach ($this->parser->parse($path) as $price) {
                        $rowsRead++;

                        if (!$price instanceof FgiscsBuildingResourcePriceDTO) {
                            continue;
                        }

                        try {
                            $this->storeRegionalPrice($price, $datasetVersion, $regionalVersion);
                            $rowsImported++;
                            $this->reportRowsProgress($progress, $fileKey, $rowsRead, $rowsImported, $errorsCount);
                        } catch (\Throwable) {
                            $errorsCount++;
                        }
                    }
                } finally {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        });

        return [
            'rows_read' => $rowsRead,
            'rows_imported' => $rowsImported,
            'errors_count' => $errorsCount,
        ];
    }

    private function storeRegionalPrice(FgiscsBuildingResourcePriceDTO $dto, EstimateDatasetVersion $datasetVersion, EstimateRegionalPriceVersion $regionalVersion): void
    {
        $resourceCode = trim($dto->code);

        EstimateResourcePrice::query()->updateOrCreate(
            [
                'dataset_version_id' => $datasetVersion->id,
                'regional_price_version_id' => $regionalVersion->id,
                'resource_code' => $resourceCode,
                'price_type' => EstimateResourceType::MATERIAL->value,
            ],
            [
                'region_id' => $regionalVersion->region_id,
                'price_zone_id' => $regionalVersion->price_zone_id,
                'period_id' => $regionalVersion->period_id,
                'construction_resource_id' => $this->findConstructionResourceId($resourceCode),
                'resource_name' => $dto->name,
                'unit' => $dto->unit,
                'base_price' => $dto->currentPrice,
                'source_price_kind' => $dto->sourcePriceKind,
                'raw_payload' => $dto->rawData,
            ]
        );
    }

    private function findConstructionResourceId(string $code): ?int
    {
        return ConstructionResource::query()
            ->where('ksr_code', $code)
            ->latest('dataset_version_id')
            ->value('id');
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

    private function finalStatus(RegionalPriceStatus|string|null $status): string
    {
        $value = $status instanceof RegionalPriceStatus ? $status->value : (string) $status;

        return in_array($value, [RegionalPriceStatus::ACTIVE->value, RegionalPriceStatus::CHECKED->value], true)
            ? $value
            : RegionalPriceStatus::PARSED->value;
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
     * @param array<string, mixed> $payload
     */
    private function report(?callable $progress, string $event, array $payload): void
    {
        if ($progress !== null) {
            $progress($event, $payload);
        }
    }
}
