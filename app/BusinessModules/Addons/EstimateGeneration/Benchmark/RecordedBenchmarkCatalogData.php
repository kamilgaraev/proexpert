<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RecordedBenchmarkCatalogData
{
    private const KEYS = ['schema_version', 'dataset_id', 'dataset_version', 'dataset_status', 'region_code',
        'price_period', 'currency', 'candidates', 'resources', 'prices', 'privacy_scanner',
        'privacy_scanner_version', 'approval_kind', 'approval_ref', 'approved_at'];

    private const FORBIDDEN = ['expected', 'label', 'labels', 'prediction', 'readiness', 'selected_norm_id',
        'final_norm_id', 'final_price', 'total_price', 'total_cost'];

    private function __construct(
        public int $datasetId,
        public string $datasetVersion,
        public string $datasetStatus,
        public string $regionCode,
        public string $pricePeriod,
        public string $currency,
        public array $candidates,
        public array $resources,
        public array $prices,
        public string $approvalRef,
    ) {}

    public static function fromArray(array $data): self
    {
        self::exactKeys($data, self::KEYS);
        self::scan($data);
        if ($data['schema_version'] !== 'recorded-benchmark-catalog:v1'
            || ! is_int($data['dataset_id']) || $data['dataset_id'] <= 0
            || ! self::token($data['dataset_version'], 96) || $data['dataset_status'] !== 'parsed'
            || ! self::token($data['region_code'], 32) || ! self::token($data['price_period'], 32)
            || ! is_string($data['currency']) || preg_match('/^[A-Z]{3}$/D', $data['currency']) !== 1
            || ! self::records($data['candidates']) || ! self::records($data['resources']) || ! self::records($data['prices'])
            || $data['privacy_scanner'] !== 'most-fixture-privacy'
            || ! self::token($data['privacy_scanner_version'], 32)
            || $data['approval_kind'] !== 'maintainer_code_review'
            || ! self::token($data['approval_ref'], 160)
            || ! is_string($data['approved_at'])
            || DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['approved_at']) === false) {
            throw new InvalidArgumentException('recorded_catalog_contract_invalid');
        }

        return new self($data['dataset_id'], $data['dataset_version'], $data['dataset_status'], $data['region_code'],
            $data['price_period'], $data['currency'], $data['candidates'], $data['resources'], $data['prices'], $data['approval_ref']);
    }

    private static function scan(array $value, int $depth = 0): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('recorded_catalog_contract_invalid');
        }
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $key = strtolower($key);
                if (in_array($key, self::FORBIDDEN, true) || str_starts_with($key, 'expected_') || str_starts_with($key, 'final_')) {
                    throw new InvalidArgumentException('recorded_catalog_forbidden_key');
                }
            }
            if (is_array($item)) {
                self::scan($item, $depth + 1);
            } elseif (! is_scalar($item) && $item !== null) {
                throw new InvalidArgumentException('recorded_catalog_contract_invalid');
            }
        }
    }

    private static function records(mixed $records): bool
    {
        return is_array($records) && array_is_list($records) && $records !== [] && count($records) <= 2048
            && array_filter($records, static fn (mixed $record): bool => ! is_array($record) || array_is_list($record)) === [];
    }

    private static function token(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max
            && preg_match('/^[\pL\pN][\pL\pN._:-]*$/uD', $value) === 1;
    }

    private static function exactKeys(array $data, array $expected): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('recorded_catalog_contract_invalid');
        }
    }
}
