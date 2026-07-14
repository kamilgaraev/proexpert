<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkImmutableObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObject;
use DomainException;
use Throwable;

final readonly class DatasetImportCorpusOrchestrator
{
    public function __construct(private BenchmarkImmutableObjectStore $objects) {}

    /**
     * @template T
     *
     * @param  callable(BenchmarkPrivateObject, list<array{upload: DatasetImportUpload, object: BenchmarkPrivateObject}>): T  $persist
     * @return T
     */
    public function execute(string $manifestPath, string $manifestJson, string $manifestSha256, DatasetImportCorpusPlan $plan, callable $persist): mixed
    {
        $receipts = [];
        try {
            $manifest = $this->objects->putImmutable($manifestPath, $manifestJson, 'application/json');
            $receipts[] = $manifest;
            $this->assertReceipt($manifest, $manifestPath, $manifestSha256);
            $staged = [];
            foreach ($plan->objects as $planned) {
                $receipt = $this->objects->putImmutable($planned['path'], $planned['upload']->body, $planned['upload']->mimeType);
                $receipts[] = $receipt;
                $this->assertReceipt($receipt, $planned['path'], $planned['upload']->sha256);
                $staged[] = ['upload' => $planned['upload'], 'object' => $receipt];
            }

            return $persist($manifest, $staged);
        } catch (Throwable $exception) {
            $cleanupFailure = null;
            foreach (array_reverse($receipts) as $receipt) {
                if ($receipt->created) {
                    try {
                        $this->objects->removeCreated($receipt);
                    } catch (Throwable $cleanupException) {
                        $cleanupFailure ??= $cleanupException;
                    }
                }
            }
            if ($cleanupFailure instanceof Throwable) {
                throw new DomainException('dataset_import_compensation_failed', 0, $exception);
            }
            throw $exception;
        }
    }

    private function assertReceipt(BenchmarkPrivateObject $receipt, string $path, string $sha256): void
    {
        if ($receipt->path !== $path || ! hash_equals($sha256, $receipt->sha256)
            || ! is_string($receipt->versionId) || trim($receipt->versionId) === ''
            || ! is_string($receipt->etag) || trim($receipt->etag) === '') {
            throw new DomainException('dataset_import_receipt_invalid');
        }
    }
}
