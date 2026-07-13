<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use InvalidArgumentException;

final readonly class NormativeResourceRowData
{
    private function __construct(
        public int $estimateNormId,
        public string $group,
        public array $resource,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $normId = self::positiveInt($row->estimate_norm_id ?? null);
        $normResourceId = self::positiveInt($row->norm_resource_id ?? null);
        $linkedResourceId = self::positiveInt($row->construction_resource_id ?? null);
        $priceResourceId = self::positiveInt($row->price_construction_resource_id ?? null);
        $priceId = self::positiveInt($row->price_id ?? null);
        if ($normId === null || $normResourceId === null || $linkedResourceId === null
            || $priceResourceId === null || $priceId === null || $linkedResourceId !== $priceResourceId) {
            throw new InvalidArgumentException('normative_resource_price_relation_invalid');
        }
        $group = match ((string) ($row->resource_type ?? '')) {
            'material' => 'materials',
            'labor' => 'labor',
            'machine', 'machinery' => 'machinery',
            default => 'other',
        };

        return new self($normId, $group, [
            'code' => (string) ($row->resource_code ?? ''),
            'name' => (string) ($row->resource_name ?? ''),
            'unit' => (string) ($row->unit ?? ''),
            'quantity' => (float) ($row->quantity ?? 0),
            'price_id' => $priceId,
            'price_source' => 'regional_catalog',
            'linked_resource_id' => $linkedResourceId,
            'norm_resource_id' => $normResourceId,
        ]);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : null;
    }
}
