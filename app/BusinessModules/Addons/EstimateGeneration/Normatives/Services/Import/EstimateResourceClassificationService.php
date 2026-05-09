<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EstimateResourceClassificationService
{
    public function __construct(
        private readonly EstimateResourceClassifier $classifier,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function classify(
        string $sourceType,
        string $versionKey,
        int $chunkSize = 1000,
        bool $dryRun = false,
        ?callable $progress = null
    ): array
    {
        $version = $this->resolveVersion($sourceType, $versionKey);
        $chunkSize = max(100, min($chunkSize, 10000));

        $summary = [
            'source_type' => $sourceType,
            'version_key' => $versionKey,
            'dry_run' => $dryRun,
            'processed' => 0,
            'updated' => 0,
            'by_type' => [],
        ];

        $this->reportProgress($progress, 'started', $summary);

        if ($sourceType === EstimateSourceType::KSR->value) {
            return $this->classifyConstructionResources((int) $version['id'], $chunkSize, $dryRun, $summary, $progress);
        }

        $summary = $this->classifyNormResources((int) $version['id'], $chunkSize, $dryRun, $summary, $progress);

        return $this->classifyResourcePrices((int) $version['id'], $chunkSize, $dryRun, $summary, $progress);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveVersion(string $sourceType, string $versionKey): array
    {
        $version = DB::table('estimate_dataset_versions')
            ->select(['id', 'source_type', 'version_key'])
            ->where('source_type', $sourceType)
            ->where('version_key', $versionKey)
            ->first();

        if ($version === null) {
            throw new RuntimeException("Normative version {$sourceType}:{$versionKey} was not found.");
        }

        return (array) $version;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function classifyConstructionResources(
        int $versionId,
        int $chunkSize,
        bool $dryRun,
        array $summary,
        ?callable $progress
    ): array
    {
        DB::table('construction_resources')
            ->where('dataset_version_id', $versionId)
            ->select(['id', 'ksr_code', 'name', 'resource_type'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($resources) use ($dryRun, $progress, &$summary): void {
                foreach ($resources as $resource) {
                    $type = $this->classifier->classify($resource->ksr_code, $resource->name, $resource->resource_type);
                    $this->countType($summary, $type);
                    $summary['processed']++;

                    if ($type === $resource->resource_type) {
                        continue;
                    }

                    $summary['updated']++;

                    if (!$dryRun) {
                        DB::table('construction_resources')
                            ->where('id', $resource->id)
                            ->update(['resource_type' => $type, 'updated_at' => now()]);
                    }
                }

                $this->reportProgress($progress, 'resources_progress', $summary);
            });

        $this->reportProgress($progress, 'finished', $summary);

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function classifyNormResources(
        int $versionId,
        int $chunkSize,
        bool $dryRun,
        array $summary,
        ?callable $progress
    ): array
    {
        $lastId = 0;

        do {
            $resources = DB::table('estimate_norm_resources as resources')
                ->join('estimate_norms as norms', 'norms.id', '=', 'resources.estimate_norm_id')
                ->join('estimate_norm_collections as collections', 'collections.id', '=', 'norms.collection_id')
                ->where('collections.dataset_version_id', $versionId)
                ->where('resources.id', '>', $lastId)
                ->select([
                    'resources.id',
                    'resources.resource_code',
                    'resources.resource_name',
                    'resources.resource_type',
                    'resources.raw_payload',
                ])
                ->orderBy('resources.id')
                ->limit($chunkSize)
                ->get();

            foreach ($resources as $resource) {
                $lastId = (int) $resource->id;
                $type = $this->isAbstractResource($resource->raw_payload ?? null)
                    ? EstimateResourceType::ABSTRACT->value
                    : $this->classifier->classify($resource->resource_code, $resource->resource_name, $resource->resource_type);
                $this->countType($summary, $type);
                $summary['processed']++;

                if ($type === $resource->resource_type) {
                    continue;
                }

                $summary['updated']++;

                if (!$dryRun) {
                    DB::table('estimate_norm_resources')
                        ->where('id', $resource->id)
                        ->update(['resource_type' => $type, 'updated_at' => now()]);
                }
            }

            if ($resources->isNotEmpty()) {
                $this->reportProgress($progress, 'norm_resources_progress', $summary);
            }
        } while ($resources->isNotEmpty());

        return $summary;
    }

    private function isAbstractResource(mixed $rawPayload): bool
    {
        if (is_string($rawPayload)) {
            $rawPayload = json_decode($rawPayload, true);
        }

        if (!is_array($rawPayload)) {
            return false;
        }

        return mb_strtolower((string) ($rawPayload['source_tag'] ?? '')) === 'abstractresource';
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function classifyResourcePrices(
        int $versionId,
        int $chunkSize,
        bool $dryRun,
        array $summary,
        ?callable $progress
    ): array
    {
        DB::table('estimate_resource_prices')
            ->where('dataset_version_id', $versionId)
            ->select(['id', 'resource_code', 'resource_name', 'price_type'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($prices) use ($dryRun, $progress, &$summary): void {
                foreach ($prices as $price) {
                    $type = $this->classifier->classify($price->resource_code, $price->resource_name, $price->price_type);
                    $this->countType($summary, $type);
                    $summary['processed']++;

                    if ($type === $price->price_type) {
                        continue;
                    }

                    $summary['updated']++;

                    if (!$dryRun) {
                        DB::table('estimate_resource_prices')
                            ->where('id', $price->id)
                            ->update(['price_type' => $type, 'updated_at' => now()]);
                    }
                }

                $this->reportProgress($progress, 'prices_progress', $summary);
            });

        $this->reportProgress($progress, 'finished', $summary);

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function countType(array &$summary, string $type): void
    {
        $summary['by_type'][$type] = ((int) ($summary['by_type'][$type] ?? 0)) + 1;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function reportProgress(?callable $progress, string $event, array $summary): void
    {
        if ($progress === null) {
            return;
        }

        $progress($event, $summary);
    }
}
