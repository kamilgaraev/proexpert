<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FsbcPriceDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FsnbNormDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FsnbNormResourceDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\KsrResourceDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateNormType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\ConstructionResource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateImportError;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNormCollection;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNormResource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNormSection;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Storage\EstimateSourceStorageService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EstimateSourceImportService
{
    public function __construct(
        private readonly EstimateSourceStorageService $storageService,
        private readonly KsrCsvParser $ksrCsvParser,
        private readonly FsnbXmlParser $fsnbXmlParser,
        private readonly FsbcXmlParser $fsbcXmlParser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(string $sourceType, string $bucket, string $prefix, string $versionKey, ?callable $progress = null): array
    {
        $sourceType = $this->normalizeToken($sourceType, 'source_type');
        $versionKey = $this->normalizeToken($versionKey, 'version_key');
        $files = $this->storageService->listFiles($bucket, $prefix);
        $datasetVersion = $this->upsertDatasetVersion($sourceType, $bucket, $prefix, $versionKey, count($files));

        try {
            $stats = [
                'files_count' => count($files),
                'rows_read' => 0,
                'rows_imported' => 0,
                'errors_count' => 0,
            ];

            foreach ($files as $fileKey) {
                $this->reportProgress($progress, 'file_started', [
                    'file' => $fileKey,
                    'source_type' => $sourceType,
                ]);

                $result = $this->importStoredFile($datasetVersion, $sourceType, $bucket, $fileKey, $progress);
                $stats['rows_read'] += (int) ($result['rows_read'] ?? 0);
                $stats['rows_imported'] += (int) ($result['rows_imported'] ?? 0);
                $stats['errors_count'] += (int) ($result['errors_count'] ?? 0);

                $this->reportProgress($progress, 'file_finished', array_merge([
                    'file' => $fileKey,
                    'source_type' => $sourceType,
                ], $result));
            }

            $this->markDatasetVersionFinished($datasetVersion, EstimateImportStatus::PARSED->value, $stats);

            return array_merge([
                'source_type' => $sourceType,
                'version_key' => $versionKey,
                'bucket' => $bucket,
                'prefix' => $prefix,
                'status' => EstimateImportStatus::PARSED->value,
            ], $stats);
        } catch (Throwable $exception) {
            $this->markDatasetVersionFinished($datasetVersion, EstimateImportStatus::FAILED->value, [
                'files_count' => count($files),
                'errors_count' => 1,
            ]);
            $this->recordImportError($datasetVersion, $prefix, 'error', $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function importStoredFile(
        EstimateDatasetVersion $datasetVersion,
        string $sourceType,
        string $bucket,
        string $fileKey,
        ?callable $progress = null
    ): array
    {
        $extension = mb_strtolower(pathinfo($fileKey, PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'xml'], true)) {
            return ['rows_read' => 0, 'rows_imported' => 0, 'errors_count' => 0];
        }

        $localPath = $this->copySourceToTemporaryFile($bucket, $fileKey);

        try {
            if ($sourceType === EstimateSourceType::KSR->value || $extension === 'csv') {
                return $this->importKsrFile($datasetVersion, $localPath, $fileKey, $progress);
            }

            if ($this->isFsbcFile($fileKey)) {
                return $this->importFsbcFile($datasetVersion, $localPath, $fileKey, $progress);
            }

            return $this->importFsnbFile($datasetVersion, $localPath, $fileKey, $progress);
        } finally {
            if (is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function importKsrFile(
        EstimateDatasetVersion $datasetVersion,
        string $localPath,
        string $fileKey,
        ?callable $progress = null
    ): array
    {
        $rowsRead = 0;
        $rowsImported = 0;
        $errorsCount = 0;

        foreach ($this->ksrCsvParser->parse($localPath) as $resource) {
            $rowsRead++;

            if (!$resource instanceof KsrResourceDTO) {
                continue;
            }

            try {
                ConstructionResource::query()->updateOrCreate(
                    [
                        'dataset_version_id' => $datasetVersion->id,
                        'ksr_code' => $this->normalizeCode($resource->code),
                    ],
                    [
                        'name' => $resource->name,
                        'unit' => $resource->unit,
                        'resource_type' => $this->normalizeResourceType($resource->resourceType),
                        'okpd2_code' => $this->extractOkpd2($resource->rawData),
                        'raw_payload' => $resource->rawData,
                    ]
                );
                $rowsImported++;
                $this->reportRowsProgress($progress, $fileKey, $rowsRead, $rowsImported, $errorsCount);
            } catch (Throwable $exception) {
                $errorsCount++;
                $this->recordImportError($datasetVersion, $fileKey, 'error', $exception->getMessage(), $rowsRead, [
                    'resource' => $resource->toArray(),
                ]);
            }
        }

        return compact('rowsRead', 'rowsImported', 'errorsCount') + [
            'rows_read' => $rowsRead,
            'rows_imported' => $rowsImported,
            'errors_count' => $errorsCount,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function importFsnbFile(
        EstimateDatasetVersion $datasetVersion,
        string $localPath,
        string $fileKey,
        ?callable $progress = null
    ): array
    {
        $rowsRead = 0;
        $rowsImported = 0;
        $errorsCount = 0;
        $collectionType = $this->fsnbXmlParser->detectCollectionType($localPath);
        $collection = $this->resolveCollection($datasetVersion, $collectionType, $fileKey);

        foreach ($this->fsnbXmlParser->parse($localPath) as $norm) {
            $rowsRead++;

            if (!$norm instanceof FsnbNormDTO) {
                continue;
            }

            try {
                DB::transaction(function () use ($collection, $norm, $progress, $fileKey, $rowsRead, &$rowsImported, &$errorsCount): void {
                    $section = $this->resolveSectionChain($collection, $norm->rawData['sections'] ?? []);

                    $estimateNorm = EstimateNorm::query()->updateOrCreate(
                        [
                            'collection_id' => $collection->id,
                            'code' => $this->normalizeCode($norm->code),
                        ],
                        [
                            'section_id' => $section?->id,
                            'name' => $norm->name,
                            'unit' => $norm->unit,
                            'section_code' => $norm->rawData['section_code'] ?? null,
                            'section_name' => $norm->section,
                            'work_composition' => $norm->rawData['content'] ?? [],
                            'raw_payload' => $norm->rawData,
                        ]
                    );

                    $estimateNorm->resources()->delete();

                    foreach ($norm->resources as $resource) {
                        if ($resource instanceof FsnbNormResourceDTO) {
                            $this->storeNormResource($estimateNorm, $resource);
                        }
                    }

                    $rowsImported++;
                    $this->reportRowsProgress($progress, $fileKey, $rowsRead, $rowsImported, $errorsCount);
                });
            } catch (Throwable $exception) {
                $errorsCount++;
                $this->recordImportError($datasetVersion, $fileKey, 'error', $exception->getMessage(), $rowsRead, [
                    'norm' => $norm->toArray(),
                ]);
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_imported' => $rowsImported,
            'errors_count' => $errorsCount,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function importFsbcFile(
        EstimateDatasetVersion $datasetVersion,
        string $localPath,
        string $fileKey,
        ?callable $progress = null
    ): array
    {
        $rowsRead = 0;
        $rowsImported = 0;
        $errorsCount = 0;
        $filePriceType = $this->isFsbcMachineFile($fileKey)
            ? EstimateResourceType::MACHINE->value
            : EstimateResourceType::MATERIAL->value;

        foreach ($this->fsbcXmlParser->parse($localPath) as $price) {
            $rowsRead++;

            if (!$price instanceof FsbcPriceDTO) {
                continue;
            }

            try {
                $resourceCode = $this->normalizeCode($price->code);

                EstimateResourcePrice::query()->updateOrCreate(
                    [
                        'dataset_version_id' => $datasetVersion->id,
                        'resource_code' => $resourceCode,
                        'price_type' => $this->normalizeResourceType($price->resourceType ?? $filePriceType),
                    ],
                    [
                        'construction_resource_id' => $this->findConstructionResourceId($resourceCode),
                        'resource_name' => $price->name,
                        'unit' => $price->unit,
                        'base_price' => $price->basePrice ?? 0,
                        'raw_payload' => $price->rawData,
                    ]
                );
                $rowsImported++;
                $this->reportRowsProgress($progress, $fileKey, $rowsRead, $rowsImported, $errorsCount);
            } catch (Throwable $exception) {
                $errorsCount++;
                $this->recordImportError($datasetVersion, $fileKey, 'error', $exception->getMessage(), $rowsRead, [
                    'price' => $price->toArray(),
                ]);
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_imported' => $rowsImported,
            'errors_count' => $errorsCount,
        ];
    }

    private function upsertDatasetVersion(
        string $sourceType,
        string $bucket,
        string $prefix,
        string $versionKey,
        int $filesCount
    ): EstimateDatasetVersion {
        $payload = [
            'bucket' => $bucket,
            'prefix' => $prefix,
            'status' => EstimateImportStatus::IMPORTING->value,
            'files_count' => $filesCount,
            'rows_read' => 0,
            'rows_imported' => 0,
            'errors_count' => 0,
            'started_at' => now(),
            'finished_at' => null,
            'updated_at' => now(),
        ];

        return EstimateDatasetVersion::query()->updateOrCreate(
            [
                'source_type' => $sourceType,
                'version_key' => $versionKey,
            ],
            $payload
        );
    }

    /**
     * @param array<string, int> $stats
     */
    private function markDatasetVersionFinished(EstimateDatasetVersion $datasetVersion, string $status, array $stats): void
    {
        $payload = array_merge($stats, [
            'status' => $status,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        $datasetVersion->update($payload);
    }

    /**
     * @param array<string, mixed>|null $rawFragment
     */
    private function recordImportError(
        EstimateDatasetVersion $datasetVersion,
        string $sourceFile,
        string $severity,
        string $message,
        ?int $rowNumber = null,
        ?array $rawFragment = null
    ): void
    {
        EstimateImportError::query()->create([
            'dataset_version_id' => $datasetVersion->id,
            'source_file' => $sourceFile,
            'row_number' => $rowNumber,
            'severity' => $severity,
            'message' => $message,
            'raw_fragment' => $rawFragment,
        ]);
    }

    private function resolveCollection(EstimateDatasetVersion $datasetVersion, string $collectionType, string $fileKey): EstimateNormCollection
    {
        $normType = $this->normalizeNormType($collectionType);

        return EstimateNormCollection::query()->updateOrCreate(
            [
                'dataset_version_id' => $datasetVersion->id,
                'code' => $normType,
                'norm_type' => $normType,
            ],
            [
                'name' => $this->collectionName($normType, $fileKey),
                'source_file' => $fileKey,
            ]
        );
    }

    /**
     * @param array<int, array{code?: ?string, name?: ?string, type?: ?string}> $sections
     */
    private function resolveSectionChain(EstimateNormCollection $collection, array $sections): ?EstimateNormSection
    {
        $parent = null;
        $pathParts = [];

        foreach ($sections as $index => $sectionData) {
            $name = trim((string) ($sectionData['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $code = $this->nullableString($sectionData['code'] ?? null);
            $type = $this->nullableString($sectionData['type'] ?? null);
            $pathParts[] = $this->sectionPathPart($index, $code, $name, $type);
            $path = implode('/', $pathParts);

            $parent = EstimateNormSection::query()->updateOrCreate(
                [
                    'collection_id' => $collection->id,
                    'path' => $path,
                ],
                [
                    'parent_id' => $parent?->id,
                    'code' => $code,
                    'name' => $name,
                    'section_type' => $type,
                    'depth' => count($pathParts) - 1,
                    'raw_payload' => $sectionData,
                ]
            );
        }

        return $parent;
    }

    private function storeNormResource(EstimateNorm $estimateNorm, FsnbNormResourceDTO $resource): void
    {
        $resourceCode = $resource->code !== null ? $this->normalizeCode($resource->code) : null;

        EstimateNormResource::query()->create([
            'estimate_norm_id' => $estimateNorm->id,
            'construction_resource_id' => $resourceCode !== null ? $this->findConstructionResourceId($resourceCode) : null,
            'resource_code' => $resourceCode ?? '',
            'resource_name' => $resource->name,
            'unit' => $resource->unit,
            'quantity' => $resource->quantity,
            'resource_type' => $this->normalizeResourceType($resource->resourceType),
            'raw_payload' => $resource->rawData,
        ]);
    }

    private function findConstructionResourceId(?string $resourceCode): ?int
    {
        if ($resourceCode === null || $resourceCode === '') {
            return null;
        }

        $id = ConstructionResource::query()
            ->where('ksr_code', $resourceCode)
            ->latest('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function copySourceToTemporaryFile(string $bucket, string $fileKey): string
    {
        $stream = $this->storageService->openReadStream($bucket, $fileKey);
        $extension = pathinfo($fileKey, PATHINFO_EXTENSION) ?: 'tmp';
        $localPath = tempnam(sys_get_temp_dir(), 'estimate-source-');

        if ($localPath === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new RuntimeException('Unable to create temporary file for estimate source import.');
        }

        $targetPath = $localPath . '.' . $extension;
        rename($localPath, $targetPath);
        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new RuntimeException('Unable to open temporary file for estimate source import.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $targetPath;
    }

    private function normalizeResourceType(?string $resourceType): string
    {
        $value = mb_strtolower(trim((string) $resourceType));

        return match (true) {
            str_contains($value, 'маш') || str_contains($value, 'механ') || $value === 'machine' => EstimateResourceType::MACHINE->value,
            str_contains($value, 'обор') || $value === 'equipment' => EstimateResourceType::EQUIPMENT->value,
            str_contains($value, 'труд') || str_contains($value, 'рабоч') || $value === 'labor' => EstimateResourceType::LABOR->value,
            str_contains($value, 'мат') || $value === 'material' => EstimateResourceType::MATERIAL->value,
            default => EstimateResourceType::OTHER->value,
        };
    }

    private function sectionPathPart(int $index, ?string $code, string $name, ?string $type): string
    {
        $base = implode('|', array_filter([
            (string) $index,
            $code,
            $type,
            $name,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));

        return mb_substr(sha1($base), 0, 16);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeNormType(string $normType): string
    {
        $value = mb_strtolower(trim($normType));

        return EstimateNormType::tryFrom($value)?->value ?? EstimateNormType::GESN->value;
    }

    private function normalizeCode(string $code): string
    {
        return trim(preg_replace('/\s+/u', ' ', $code) ?? $code);
    }

    private function isFsbcFile(string $fileKey): bool
    {
        $name = mb_strtolower(basename($fileKey));

        return str_contains($name, 'фсбц') || str_contains($name, 'fsbc');
    }

    private function isFsbcMachineFile(string $fileKey): bool
    {
        $name = mb_strtolower(basename($fileKey));

        return str_contains($name, 'маш') || str_contains($name, 'machine');
    }

    private function collectionName(string $normType, string $fileKey): string
    {
        return match ($normType) {
            EstimateNormType::GESN->value => 'ГЭСН',
            EstimateNormType::GESNM->value => 'ГЭСНм',
            EstimateNormType::GESNMR->value => 'ГЭСНмр',
            EstimateNormType::GESNP->value => 'ГЭСНп',
            EstimateNormType::GESNR->value => 'ГЭСНр',
            EstimateNormType::FSBC_MATERIAL->value => 'ФСБЦ материалы и оборудование',
            EstimateNormType::FSBC_MACHINE->value => 'ФСБЦ машины и механизмы',
            default => basename($fileKey),
        };
    }

    /**
     * @param array<string, mixed>|null $rawData
     */
    private function extractOkpd2(?array $rawData): ?string
    {
        $row = $rawData['row'] ?? null;

        if (!is_array($row)) {
            return null;
        }

        foreach ($row as $value) {
            $value = trim((string) $value);

            if (preg_match('/^\d{2}(?:\.\d+)+$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeToken(string $value, string $field): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw new RuntimeException("Estimate import {$field} must not be empty.");
        }

        return $normalized;
    }

    private function reportRowsProgress(
        ?callable $progress,
        string $fileKey,
        int $rowsRead,
        int $rowsImported,
        int $errorsCount
    ): void {
        if ($rowsRead === 1 || $rowsRead % 1000 === 0) {
            $this->reportProgress($progress, 'rows_progress', [
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
    private function reportProgress(?callable $progress, string $event, array $payload): void
    {
        if ($progress === null) {
            return;
        }

        $progress($event, $payload);
    }
}
