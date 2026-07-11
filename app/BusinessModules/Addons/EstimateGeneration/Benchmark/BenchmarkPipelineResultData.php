<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;
use JsonException;

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

    public function protocolJson(): string
    {
        return (string) json_encode([
            'schema_version' => 1,
            'status' => $this->status,
            'prediction' => $this->prediction,
            'model_versions' => $this->modelVersions,
            'cost_amount' => $this->costAmount,
            'currency' => $this->currency,
            'failure_code' => $this->failureCode,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function fromProtocolJson(string $json): self
    {
        if ($json === '' || strlen($json) > 1_048_576) {
            return self::technicalFailure('worker_protocol_invalid');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::technicalFailure('worker_protocol_invalid');
        }
        if (! is_array($payload)) {
            return self::technicalFailure('worker_protocol_invalid');
        }
        $expectedKeys = ['schema_version', 'status', 'prediction', 'model_versions', 'cost_amount', 'currency', 'failure_code'];
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys || ($payload['schema_version'] ?? null) !== 1
            || ! is_string($payload['status'] ?? null) || ! is_array($payload['prediction'] ?? null)
            || ! is_array($payload['model_versions'] ?? null)) {
            return self::technicalFailure('worker_protocol_invalid');
        }
        try {
            return new self(
                $payload['status'],
                $payload['prediction'],
                $payload['model_versions'],
                is_string($payload['cost_amount']) ? $payload['cost_amount'] : null,
                is_string($payload['currency']) ? $payload['currency'] : null,
                is_string($payload['failure_code']) ? $payload['failure_code'] : null,
            );
        } catch (InvalidArgumentException) {
            return self::technicalFailure('worker_protocol_invalid');
        }
    }
}
