<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

final readonly class DatasetPrivateBenchmarkObjectReader implements BenchmarkObjectReader
{
    public function __construct(
        private BenchmarkPrivateObjectStore $store,
        private int $organizationId,
        private string $basePrefix,
    ) {
        $expected = 'org-'.$organizationId.'/estimate-generation/benchmark-imports/';
        if ($organizationId < 1 || ! str_starts_with($basePrefix, $expected)
            || ! str_ends_with($basePrefix, '/objects/') || str_contains($basePrefix, '..')) {
            throw new BenchmarkContractException('dataset_private_base_prefix_invalid');
        }
    }

    public function read(BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string
    {
        $locator = match ($role) {
            'input' => $case->inputLocator,
            'expected' => $case instanceof BenchmarkCaseData
                ? $case->expectedLocator
                : throw new BenchmarkContractException('prediction_expected_read_forbidden'),
            default => throw new BenchmarkContractException('object_role_invalid'),
        };
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,511}$#D', $locator)
            || str_starts_with($locator, '/') || str_contains($locator, '..')
            || str_contains($locator, '://') || str_contains($locator, '\\')) {
            throw new BenchmarkContractException('dataset_private_relative_locator_invalid');
        }
        $content = $this->store->read($this->basePrefix.$locator, $maxBytes);
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
}
