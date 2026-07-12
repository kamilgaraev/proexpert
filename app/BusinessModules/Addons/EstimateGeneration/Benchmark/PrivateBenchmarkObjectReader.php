<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class PrivateBenchmarkObjectReader implements BenchmarkObjectReader
{
    public function __construct(
        private BenchmarkPrivateObjectStore $store,
        private int $organizationId,
    ) {}

    public function read(BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string
    {
        $locator = match ($role) {
            'input' => $case->inputLocator,
            'expected' => $case instanceof BenchmarkCaseData
                ? $case->expectedLocator
                : throw new BenchmarkContractException('prediction_expected_read_forbidden'),
            default => throw new BenchmarkContractException('object_role_invalid'),
        };
        $path = $this->path($locator);
        $content = $this->store->read($path, $maxBytes);
        $expectedHash = $role === 'input'
            ? $case->inputSha256
            : ($case instanceof BenchmarkCaseData
                ? $case->expectedSha256
                : throw new BenchmarkContractException('prediction_expected_read_forbidden'));
        if (! hash_equals($expectedHash, hash('sha256', $content))) {
            throw new BenchmarkContractException('private_object_hash_mismatch');
        }

        return $content;
    }

    private function path(string $locator): string
    {
        $resolved = str_replace('{organization_id}', (string) $this->organizationId, $locator);
        $prefix = 's3://org-'.$this->organizationId.'/estimate-generation/benchmarks/acceptance/';
        if (! str_starts_with($resolved, $prefix) || str_contains($resolved, '..') || str_contains($resolved, '?')) {
            throw new BenchmarkContractException('private_object_path_invalid');
        }

        return substr($resolved, strlen('s3://'));
    }
}
