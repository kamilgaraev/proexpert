<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\LaborPriceDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions\FgiscsDownloadUnavailableException;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\ConstructionResource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePricePeriod;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePriceZone;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\LaborPriceSpreadsheetParser;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage\EstimateSourceStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FgiscsRegionalPriceUpdateService
{
    public function __construct(
        private readonly FgiscsClient $client,
        private readonly FgiscsRegionalCatalogService $catalogService,
        private readonly LaborPriceSpreadsheetParser $parser,
        private readonly EstimateSourceStorageService $storageService,
        private readonly RegionalPriceQualityService $qualityService,
        private readonly RegionalPriceImportLifecycleService $lifecycleService,
        private readonly RegionalPriceVersionResolver $versionResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function syncTatarstan(string $bucket, ?int $periodId = null, bool $latestOnly = true, bool $force = false, ?callable $progress = null): array
    {
        $catalog = $this->catalogService->syncTatarstan();

        return $this->syncPriceZone(
            bucket: $bucket,
            region: $catalog['region'],
            priceZone: $catalog['price_zone'],
            periodId: $periodId,
            latestOnly: $latestOnly,
            allPeriods: false,
            force: $force,
            progress: $progress,
        )[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncSupportedRegions(string $bucket, ?int $periodId = null, bool $latestOnly = true, bool $allPeriods = false, bool $force = false, ?callable $progress = null): array
    {
        $results = [];

        foreach ($this->catalogService->supportedRegions() as $region) {
            if ($region->priceZones->isEmpty()) {
                $catalog = $this->catalogService->syncSubject((int) $region->fgiscs_subject_id, $region->code, $region->name, true);
                $region = $catalog['region']->load('priceZones');
            }

            foreach ($region->priceZones as $priceZone) {
                array_push($results, ...$this->syncPriceZone($bucket, $region, $priceZone, $periodId, $latestOnly, $allPeriods, $force, $progress));
            }
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncSubject(int $subjectId, string $bucket, ?int $periodId = null, bool $latestOnly = true, bool $allPeriods = false, bool $force = false, ?callable $progress = null): array
    {
        $catalog = $this->catalogService->syncSubject($subjectId);
        $results = [];

        foreach ($catalog['price_zones'] as $priceZone) {
            array_push($results, ...$this->syncPriceZone($bucket, $catalog['region'], $priceZone, $periodId, $latestOnly, $allPeriods, $force, $progress));
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncAllRegions(string $bucket, ?int $periodId = null, bool $latestOnly = true, bool $allPeriods = false, bool $force = false, ?int $limit = null, ?callable $progress = null): array
    {
        $results = [];
        $subjects = $this->catalogService->countrySubjects();

        foreach ($subjects as $index => $subject) {
            if ($limit !== null && $index >= $limit) {
                break;
            }

            $this->report($progress, 'region_started', [
                'subject_id' => $subject['id'],
                'name' => $subject['name'],
            ]);

            try {
                $catalog = $this->catalogService->syncSubject((int) $subject['id'], null, (string) $subject['name'], true);

                foreach ($catalog['price_zones'] as $priceZone) {
                    array_push($results, ...$this->syncPriceZone($bucket, $catalog['region'], $priceZone, $periodId, $latestOnly, $allPeriods, $force, $progress));
                }
            } catch (Throwable $exception) {
                Log::error('[EstimateGeneration] FGIS CS worker salary regional sync failed.', [
                    'subject_id' => $subject['id'],
                    'region' => $subject['name'],
                    'exception_class' => $exception::class,
                    'exception_code' => $exception->getCode(),
                    'database_column' => $this->databaseColumn($exception),
                ]);

                $results[] = [
                    'status' => RegionalPriceStatus::FAILED->value,
                    'subject_id' => $subject['id'],
                    'region' => $subject['name'],
                    'failure_code' => 'fgiscs_region_update_failed',
                ];
            }
        }

        return $results;
    }

    private function databaseColumn(Throwable $exception): ?string
    {
        $message = $exception->getPrevious()?->getMessage() ?? '';

        return preg_match('/column "([^"]+)"/', $message, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function syncPriceZone(
        string $bucket,
        EstimateRegion $region,
        EstimatePriceZone $priceZone,
        ?int $periodId,
        bool $latestOnly,
        bool $allPeriods,
        bool $force,
        ?callable $progress,
    ): array {
        $periods = $this->resolvePeriods((int) $priceZone->fgiscs_price_zone_id, $periodId, $allPeriods);
        $results = [];

        foreach ($periods as $index => $period) {
            $results[] = $this->syncPeriod($bucket, $region, $priceZone, $period, $latestOnly, ! $allPeriods || $index === 0, $force, $progress);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncPeriod(string $bucket, EstimateRegion $region, EstimatePriceZone $priceZone, EstimatePricePeriod $period, bool $latestOnly, bool $activate, bool $force, ?callable $progress): array
    {
        $baseVersionKey = $this->versionKey($period, $region, $priceZone);
        $versionKey = $this->versionResolver->resolveVersionKey(
            EstimateSourceType::FGIS_LABOR_PRICES->value,
            (int) $region->id,
            (int) $priceZone->id,
            (int) $period->id,
            $baseVersionKey,
            'worker_salary_imported',
            $force,
        );
        $prefix = $this->prefix($period, (int) $region->fgiscs_subject_id, (int) $priceZone->fgiscs_price_zone_id);
        $fileKey = $prefix.'worker-salary.xlsx';

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

        if (! $force && (bool) ($regionalVersion->metadata['worker_salary_imported'] ?? false)) {
            return [
                'skipped' => true,
                'reason' => 'period_already_imported',
                'region' => $region->name,
                'price_zone' => $priceZone->name,
                'version_id' => $regionalVersion->id,
                'version_key' => $regionalVersion->version_key,
                'status' => $regionalVersion->status->value,
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
                'files_count' => 1,
                'rows_read' => 0,
                'rows_imported' => 0,
                'errors_count' => 0,
                'started_at' => now(),
                'finished_at' => null,
                'meta' => [
                    'region_code' => $region->code,
                    'region_id' => $region->id,
                    'price_zone_id' => $priceZone->fgiscs_price_zone_id,
                    'fgiscs_period_id' => $period->fgiscs_period_id,
                    'regional' => true,
                ],
            ]
        );

        try {
            $this->report($progress, 'download_started', [
                'region' => $region->name,
                'period' => $period->name,
                'file' => $fileKey,
            ]);
            $download = $this->client->downloadWorkerSalary((int) $priceZone->fgiscs_price_zone_id, (int) $period->fgiscs_period_id);
            $this->storageService->disk($bucket)->put($fileKey, $download->content);

            $regionalVersion->update([
                'status' => RegionalPriceStatus::DOWNLOADED->value,
                'files_count' => 1,
                'metadata' => array_merge($regionalVersion->metadata ?? [], [
                    'bucket' => $bucket,
                    'file_key' => $fileKey,
                    'file_name' => $download->fileName,
                    'content_type' => $download->contentType,
                    'latest_only' => $latestOnly,
                ]),
            ]);

            $this->report($progress, 'parse_started', ['file' => $fileKey]);
            $stats = $this->importWorkerSalaryContent($download->content, $datasetVersion, $regionalVersion);
            $quality = $this->qualityService->checkWorkerSalaryVersion($regionalVersion);

            if (! $quality['passed']) {
                $regionalVersion->update([
                    'status' => RegionalPriceStatus::FAILED->value,
                    'metadata' => array_merge($regionalVersion->metadata ?? [], ['quality' => $quality]),
                ]);
                $datasetVersion->update([
                    'status' => EstimateImportStatus::FAILED->value,
                    'errors_count' => count($quality['errors']),
                    'finished_at' => now(),
                ]);

                return array_merge($stats, [
                    'status' => RegionalPriceStatus::FAILED->value,
                    'region' => $region->name,
                    'price_zone' => $priceZone->name,
                    'quality' => $quality,
                ]);
            }

            $buildingResourcesRequired = true;
            $regionalVersion->update([
                'status' => RegionalPriceStatus::CHECKED->value,
                'metadata' => array_merge($regionalVersion->metadata ?? [], [
                    'quality' => $quality,
                    'worker_salary_imported' => true,
                    'worker_salary_imported_at' => now()->toIso8601String(),
                ]),
            ]);
            $lifecycle = $this->lifecycleService->finalize($regionalVersion, $activate, $buildingResourcesRequired);

            $datasetVersion->update([
                'status' => EstimateImportStatus::PARSED->value,
                'finished_at' => now(),
            ]);

            return array_merge($stats, [
                'status' => $regionalVersion->fresh()->status->value,
                'region' => $region->name,
                'price_zone' => $priceZone->name,
                'quality' => $quality,
                'activation_id' => $lifecycle['activation_id'],
                'complete_quality' => $lifecycle['quality'],
                'version_id' => $regionalVersion->id,
                'version_key' => $versionKey,
                'period' => $period->name,
                'file_key' => $fileKey,
            ]);
        } catch (FgiscsDownloadUnavailableException $exception) {
            $regionalVersion->update([
                'status' => RegionalPriceStatus::UNAVAILABLE->value,
                'errors_count' => 0,
                'metadata' => array_merge($regionalVersion->metadata ?? [], [
                    'unavailable_reason' => 'fgiscs_region_unavailable',
                    'http_status' => $exception->statusCode,
                    'failure_code' => 'fgiscs_download_unavailable',
                ]),
            ]);
            $datasetVersion->update([
                'status' => EstimateImportStatus::FAILED->value,
                'errors_count' => 0,
                'finished_at' => now(),
                'meta' => array_merge($datasetVersion->meta ?? [], [
                    'unavailable_reason' => 'fgiscs_region_unavailable',
                    'http_status' => $exception->statusCode,
                ]),
            ]);

            return [
                'skipped' => true,
                'reason' => 'fgiscs_download_unavailable',
                'status' => RegionalPriceStatus::UNAVAILABLE->value,
                'region' => $region->name,
                'price_zone' => $priceZone->name,
                'version_id' => $regionalVersion->id,
                'version_key' => $versionKey,
                'period' => $period->name,
                'message' => 'fgiscs_region_update_failed',
                'http_status' => $exception->statusCode,
            ];
        } catch (Throwable $exception) {
            $regionalVersion->update([
                'status' => RegionalPriceStatus::FAILED->value,
                'errors_count' => max(1, (int) $regionalVersion->errors_count),
                'metadata' => array_merge($regionalVersion->metadata ?? [], ['failure_code' => 'fgiscs_region_update_failed']),
            ]);
            $datasetVersion->update([
                'status' => EstimateImportStatus::FAILED->value,
                'errors_count' => 1,
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array{rows_read:int,rows_imported:int,errors_count:int}
     */
    private function importWorkerSalaryContent(string $content, EstimateDatasetVersion $datasetVersion, EstimateRegionalPriceVersion $regionalVersion): array
    {
        $regionalVersion->update(['status' => RegionalPriceStatus::PARSING->value]);

        $path = tempnam(sys_get_temp_dir(), 'fgiscs-worker-salary-').'.xlsx';
        file_put_contents($path, $content);

        $rowsRead = 0;
        $rowsImported = 0;
        $errorsCount = 0;

        try {
            DB::transaction(function () use ($path, $datasetVersion, $regionalVersion, &$rowsRead, &$rowsImported, &$errorsCount): void {
                EstimateResourcePrice::query()
                    ->where('regional_price_version_id', $regionalVersion->id)
                    ->where('source_price_kind', 'regional_worker_salary')
                    ->delete();

                foreach ($this->parser->parse($path) as $dto) {
                    $rowsRead++;

                    if (! $dto instanceof LaborPriceDTO) {
                        continue;
                    }

                    foreach ($this->expandLaborAliases($dto) as $price) {
                        $this->storeRegionalPrice($price, $datasetVersion, $regionalVersion);
                        $rowsImported++;
                    }
                }

                $datasetVersion->update([
                    'rows_read' => $rowsRead,
                    'rows_imported' => $rowsImported,
                    'errors_count' => $errorsCount,
                ]);

                $regionalVersion->update([
                    'status' => RegionalPriceStatus::PARSED->value,
                    'rows_read' => $rowsRead,
                    'rows_imported' => $rowsImported,
                    'errors_count' => $errorsCount,
                ]);
            });
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_imported' => $rowsImported,
            'errors_count' => $errorsCount,
        ];
    }

    /**
     * @return array<int, LaborPriceDTO>
     */
    private function expandLaborAliases(LaborPriceDTO $dto): array
    {
        $items = [$dto];
        $rank = $dto->rawData['row']['rank'] ?? null;

        if (preg_match('/^1-100-\d+$/', $dto->code) === 1 && is_numeric($rank)) {
            $integerRank = (int) $rank;

            if ((float) $rank === (float) $integerRank && $integerRank >= 1 && $integerRank <= 8) {
                $items[] = new LaborPriceDTO(
                    code: sprintf('2-100-%02d', $integerRank),
                    name: sprintf('Рабочий %d разряда', $integerRank),
                    unit: $dto->unit ?? 'чел.-ч',
                    basePrice: $dto->basePrice,
                    resourceType: EstimateResourceType::LABOR->value,
                    rawData: array_merge($dto->rawData ?? [], [
                        'derived_from_code' => $dto->code,
                        'derived_for_gesn_worker_code' => true,
                    ]),
                );
            }
        }

        return $items;
    }

    private function storeRegionalPrice(LaborPriceDTO $dto, EstimateDatasetVersion $datasetVersion, EstimateRegionalPriceVersion $regionalVersion): void
    {
        $resourceCode = trim($dto->code);

        EstimateResourcePrice::query()->updateOrCreate(
            [
                'dataset_version_id' => $datasetVersion->id,
                'regional_price_version_id' => $regionalVersion->id,
                'resource_code' => $resourceCode,
                'price_type' => $dto->resourceType,
            ],
            [
                'region_id' => $regionalVersion->region_id,
                'price_zone_id' => $regionalVersion->price_zone_id,
                'period_id' => $regionalVersion->period_id,
                'construction_resource_id' => $this->findConstructionResourceId($resourceCode),
                'resource_name' => $dto->name,
                'unit' => $dto->unit ?? 'чел.-ч',
                'base_price' => $dto->basePrice,
                'source_price_kind' => 'regional_worker_salary',
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

    /**
     * @return array<int, EstimatePricePeriod>
     */
    private function resolvePeriods(int $priceZoneId, ?int $periodId, bool $allPeriods): array
    {
        if ($periodId !== null) {
            $this->catalogService->syncPeriods($priceZoneId);

            $period = EstimatePricePeriod::query()
                ->where('fgiscs_period_id', $periodId)
                ->first() ?? throw new RuntimeException('Указанный период ФГИС ЦС не найден.');

            return [$period];
        }

        return $allPeriods
            ? $this->catalogService->allPeriods($priceZoneId)
            : [$this->catalogService->latestPeriod($priceZoneId)];
    }

    private function versionKey(EstimatePricePeriod $period, EstimateRegion $region, EstimatePriceZone $priceZone): string
    {
        if ($region->code === FgiscsRegionalCatalogService::DEFAULT_REGION_CODE && (int) $priceZone->fgiscs_price_zone_id === FgiscsRegionalCatalogService::TATARSTAN_PRICE_ZONE_ID) {
            return sprintf('%d-q%d-ru-ta', $period->year, $period->quarter);
        }

        return sprintf(
            '%d-q%d-%s-pz-%d',
            $period->year,
            $period->quarter,
            strtolower((string) $region->code),
            (int) $priceZone->fgiscs_price_zone_id
        );
    }

    private function prefix(EstimatePricePeriod $period, int $subjectId, int $priceZoneId): string
    {
        return sprintf(
            'estimate-sources/fgiscs/worker-salary/%d-q%d/region-%d/price-zone-%d/',
            $period->year,
            $period->quarter,
            $subjectId,
            $priceZoneId
        );
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
