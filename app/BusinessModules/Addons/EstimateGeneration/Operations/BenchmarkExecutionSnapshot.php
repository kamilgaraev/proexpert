<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use DomainException;

final readonly class BenchmarkExecutionSnapshot
{
    private const KEYS = [
        'schema_version', 'organization_id', 'dataset_id', 'dataset_type', 'dataset_version',
        'dataset_content_hash', 'manifest_locator', 'manifest_sha256', 'adapter_id', 'prompt_version',
        'settings_snapshot_id', 'settings_snapshot_version', 'pipeline_version', 'model_versions',
        'normative_version', 'price_version', 'currency',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $keys = array_keys($values);
        sort($keys);
        $expected = self::KEYS;
        sort($expected);
        if ($keys !== $expected || $values['schema_version'] !== 1
            || ! self::positive($values['organization_id']) || ! self::positive($values['dataset_id'])
            || ! self::positive($values['dataset_version']) || ! self::positive($values['settings_snapshot_id'])
            || ! self::positive($values['settings_snapshot_version'])
            || ! in_array($values['dataset_type'], ['development', 'regression', 'acceptance'], true)
            || ! self::hash($values['dataset_content_hash'], true) || ! self::hash($values['manifest_sha256'])
            || ! self::identifier($values['adapter_id']) || ! self::version($values['prompt_version'])
            || ! self::version($values['pipeline_version']) || ! self::version($values['normative_version'])
            || ! self::version($values['price_version']) || ! in_array($values['currency'], ['RUB', 'USD', 'EUR'], true)
            || ! self::models($values['model_versions']) || ! self::locator($values['manifest_locator'], (int) $values['organization_id'], (string) $values['dataset_type'])) {
            throw new DomainException('benchmark_execution_snapshot_invalid');
        }

        return new self($values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? throw new DomainException('benchmark_execution_snapshot_key_invalid');
    }

    public function assertDataset(int $organizationId, int $datasetId, string $type, int $version, string $contentHash): void
    {
        if ($organizationId !== $this->values['organization_id'] || $datasetId !== $this->values['dataset_id']
            || $type !== $this->values['dataset_type'] || $version !== $this->values['dataset_version']
            || ! hash_equals((string) $this->values['dataset_content_hash'], $contentHash)) {
            throw new DomainException('benchmark_execution_dataset_mismatch');
        }
    }

    /** @param array<string, mixed> $report */
    public function assertReport(array $report): void
    {
        $mapping = [
            'dataset_type' => 'dataset', 'manifest_sha256' => 'manifest_sha256', 'adapter_id' => 'adapter_id',
            'prompt_version' => 'prompt_version', 'pipeline_version' => 'pipeline_version',
            'model_versions' => 'model_versions', 'normative_version' => 'normative_version',
            'price_version' => 'price_version', 'currency' => 'currency',
            'settings_snapshot_id' => 'settings_snapshot_id', 'settings_snapshot_version' => 'settings_snapshot_version',
        ];
        foreach ($mapping as $snapshotKey => $reportKey) {
            if (! array_key_exists($reportKey, $report)
                || self::canonical($this->values[$snapshotKey]) !== self::canonical($report[$reportKey])) {
                throw new DomainException('benchmark_execution_report_mismatch');
            }
        }
    }

    private static function positive(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function hash(mixed $value, bool $prefix = false): bool
    {
        return is_string($value) && preg_match($prefix ? '/^sha256:[a-f0-9]{64}$/' : '/^[a-f0-9]{64}$/', $value) === 1;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9-]{2,63}$/', $value) === 1;
    }

    private static function version(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,95}$/', $value) === 1;
    }

    private static function models(mixed $value): bool
    {
        return is_array($value) && $value !== [] && count($value) <= 16
            && array_reduce(array_keys($value), static fn (bool $valid, mixed $key): bool => $valid && is_string($key) && preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) === 1, true)
            && array_reduce($value, static fn (bool $valid, mixed $model): bool => $valid && is_string($model) && strlen($model) <= 192, true);
    }

    private static function locator(mixed $value, int $organizationId, string $type): bool
    {
        return is_string($value) && preg_match('#^s3://org-'.$organizationId.'/estimate-generation/benchmarks/'.preg_quote($type, '#').'/[A-Za-z0-9._/-]+\.json$#', $value) === 1
            && ! str_contains($value, '..') && ! str_contains($value, '?');
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
