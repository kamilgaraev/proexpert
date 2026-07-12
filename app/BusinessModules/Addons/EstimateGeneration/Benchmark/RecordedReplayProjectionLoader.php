<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;
use JsonException;

final readonly class RecordedReplayProjectionLoader
{
    private const KEYS = [
        'schema_version', 'case_id', 'input_sha256', 'envelopes', 'catalog', 'recording_manifest_sha256',
    ];

    public function __construct(private string $fixtureRoot) {}

    public function load(
        BenchmarkPredictionCaseData $case,
        string $manifestReference,
        string $manifestSha256,
    ): BenchmarkPredictionCaseData {
        $path = $this->safeFile($manifestReference, $manifestSha256);
        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('recorded_projection_json_invalid');
        }
        if (! is_array($data) || ! $this->exactKeys($data, self::KEYS)
            || $data['schema_version'] !== 'recorded-replay-projection:v1'
            || $data['case_id'] !== $case->id || $data['input_sha256'] !== $case->inputSha256) {
            throw new InvalidArgumentException(is_array($data) && $this->exactKeys($data, self::KEYS)
                ? 'recorded_projection_dependency_mismatch' : 'recorded_projection_contract_invalid');
        }
        if (! $this->hash($data['recording_manifest_sha256']) || ! is_array($data['envelopes'])
            || array_is_list($data['envelopes']) || $data['envelopes'] === [] || count($data['envelopes']) > 8
            || ! is_array($data['catalog']) || ! $this->exactKeys($data['catalog'], ['locator', 'sha256'])) {
            throw new InvalidArgumentException('recorded_projection_contract_invalid');
        }
        $references = [];
        $hashes = [];
        foreach ($data['envelopes'] as $port => $descriptor) {
            if (RecordedPort::tryFrom(is_string($port) ? $port : '') === null || ! is_array($descriptor)
                || ! $this->exactKeys($descriptor, ['locator', 'sha256'])
                || ! $this->locator($descriptor['locator'] ?? null) || ! $this->hash($descriptor['sha256'] ?? null)) {
                throw new InvalidArgumentException('recorded_projection_contract_invalid');
            }
            $references[$port] = $descriptor['locator'];
            $hashes[$port] = $descriptor['sha256'];
        }
        if (! $this->locator($data['catalog']['locator']) || ! $this->hash($data['catalog']['sha256'])) {
            throw new InvalidArgumentException('recorded_projection_contract_invalid');
        }
        ksort($references, SORT_STRING);
        ksort($hashes, SORT_STRING);

        return new BenchmarkPredictionCaseData(
            $case->id, $case->dataset, $case->sourceType, $case->inputLocator, $case->inputSha256,
            $case->tags, $case->allowedCapabilities, $references, $hashes,
            $data['recording_manifest_sha256'], $data['catalog']['locator'], $data['catalog']['sha256'],
        );
    }

    private function safeFile(string $reference, string $sha256): string
    {
        if (! $this->locator($reference) || ! $this->hash($sha256)) {
            throw new InvalidArgumentException('recorded_projection_path_invalid');
        }
        $root = realpath($this->fixtureRoot);
        $candidate = $this->fixtureRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $reference);
        $path = realpath($candidate);
        $prefix = $root === false ? '' : rtrim(str_replace('\\', '/', $root), '/').'/';
        if ($root === false || $path === false || is_link($candidate)
            || ! str_starts_with(str_replace('\\', '/', $path), $prefix)
            || ! hash_equals($sha256, (string) hash_file('sha256', $path))) {
            throw new InvalidArgumentException('recorded_projection_integrity_failed');
        }

        return $path;
    }

    private function locator(mixed $value): bool
    {
        return is_string($value) && preg_match('#^[a-zA-Z0-9._/-]+\.json$#D', $value) === 1
            && ! str_contains($value, '..') && ! str_starts_with($value, '/');
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        return $actual === $keys;
    }
}
