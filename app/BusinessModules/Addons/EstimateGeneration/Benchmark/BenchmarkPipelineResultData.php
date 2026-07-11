<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;

final readonly class BenchmarkPipelineResultData
{
    /** @param array<string, mixed> $prediction @param array<string, string> $modelVersions */
    private function __construct(
        public string $status,
        public array $prediction,
        public array $modelVersions,
        public ?string $costAmount,
        public ?string $currency,
        public ?string $failureCode,
    ) {
        if (! in_array($status, ['success', 'technical_failure', 'unsupported'], true)) {
            throw new InvalidArgumentException('pipeline_status_invalid');
        }
        if ($status === 'success' && $failureCode !== null) {
            throw new InvalidArgumentException('success_failure_code_invalid');
        }
        if ($status !== 'success' && (! is_string($failureCode) || ! preg_match('/^[a-z][a-z0-9_]{2,63}$/', $failureCode))) {
            throw new InvalidArgumentException('failure_code_invalid');
        }
        if (($costAmount === null) !== ($currency === null)) {
            throw new InvalidArgumentException('cost_pair_invalid');
        }
        if ($costAmount !== null && ! preg_match('/^(0|[1-9][0-9]{0,12})(\.[0-9]{1,9})?$/', $costAmount)) {
            throw new InvalidArgumentException('cost_amount_invalid');
        }
        if ($currency !== null && ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('currency_invalid');
        }
        if (count($modelVersions) > 32) {
            throw new InvalidArgumentException('model_versions_invalid');
        }
        foreach ($modelVersions as $key => $version) {
            if (! is_string($key) || ! is_string($version)
                || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{1,63}$/', $key)
                || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,95}$/', $version)) {
                throw new InvalidArgumentException('model_versions_invalid');
            }
        }
    }

    /** @param array<string, mixed> $prediction @param array<string, string> $modelVersions */
    public static function success(array $prediction, array $modelVersions, ?string $costAmount, ?string $currency): self
    {
        return new self('success', $prediction, $modelVersions, $costAmount, $currency, null);
    }

    public static function technicalFailure(string $code): self
    {
        return new self('technical_failure', [], [], null, null, $code);
    }

    public static function unsupported(string $code = 'capability_unsupported'): self
    {
        return new self('unsupported', [], [], null, null, $code);
    }
}
