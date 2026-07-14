<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifest;
use DomainException;

final readonly class DatasetImportCorpusPlan
{
    /**
     * @param  list<array{path: string, upload: DatasetImportUpload}>  $objects
     * @param  list<string>  $contentHashes
     */
    private function __construct(public array $objects, public array $contentHashes) {}

    /** @param list<DatasetImportUpload> $uploads */
    public static function fromManifest(BenchmarkManifest $manifest, BenchmarkDatasetType $dataset, string $basePrefix, array $uploads): self
    {
        $byHash = [];
        foreach ($uploads as $upload) {
            if (isset($byHash[$upload->sha256])) {
                throw new DomainException('dataset_import_duplicate_upload');
            }
            $byHash[$upload->sha256] = $upload;
        }

        $requiredHashes = [];
        $requirements = [];
        foreach ($manifest->cases() as $case) {
            if ($case->dataset !== $dataset) {
                throw new DomainException('dataset_import_manifest_kind_mismatch');
            }
            foreach ([[$case->inputLocator, $case->inputSha256], [$case->expectedLocator, $case->expectedSha256]] as [$locator, $sha256]) {
                $requiredHashes[$sha256] = true;
                $requirements[] = [$locator, $sha256];
            }
        }
        if ($requirements === []) {
            throw new DomainException('dataset_import_manifest_empty');
        }
        foreach (array_keys($requiredHashes) as $sha256) {
            if (! isset($byHash[$sha256])) {
                throw new DomainException('dataset_import_required_object_missing');
            }
        }
        foreach (array_keys($byHash) as $sha256) {
            if (! isset($requiredHashes[$sha256])) {
                throw new DomainException('dataset_import_unexpected_upload');
            }
        }

        $objects = [];
        $paths = [];
        foreach ($requirements as [$locator, $sha256]) {
            $path = self::resolvePath($dataset, $basePrefix, $locator);
            if (isset($paths[$path])) {
                throw new DomainException('dataset_import_duplicate_locator');
            }
            $paths[$path] = true;
            $objects[] = ['path' => $path, 'upload' => $byHash[$sha256]];
        }
        $hashes = array_keys($requiredHashes);
        sort($hashes, SORT_STRING);

        return new self($objects, $hashes);
    }

    private static function resolvePath(BenchmarkDatasetType $dataset, string $basePrefix, string $locator): string
    {
        if (! preg_match('#^org-[1-9][0-9]*/estimate-generation/(?:benchmark-imports/sha256-[a-f0-9]{64}/objects|benchmarks/acceptance)/$#D', $basePrefix)
            || str_contains($basePrefix, '..')) {
            throw new DomainException('dataset_import_base_prefix_invalid');
        }
        if ($dataset === BenchmarkDatasetType::Acceptance) {
            $templatePrefix = 's3://org-{organization_id}/estimate-generation/benchmarks/acceptance/';
            if (! str_starts_with($locator, $templatePrefix)) {
                throw new DomainException('dataset_import_locator_invalid');
            }
            $path = $basePrefix.substr($locator, strlen($templatePrefix));
        } else {
            if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,511}$#D', $locator)
                || str_contains($locator, '..') || str_contains($locator, '://') || str_contains($locator, '\\')) {
                throw new DomainException('dataset_import_locator_invalid');
            }
            $path = $basePrefix.$locator;
        }
        if (! str_starts_with($path, $basePrefix) || str_contains($path, '..') || str_contains($path, '//')) {
            throw new DomainException('dataset_import_locator_invalid');
        }

        return $path;
    }
}
