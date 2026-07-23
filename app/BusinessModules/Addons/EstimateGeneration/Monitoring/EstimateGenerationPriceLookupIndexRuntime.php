<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

final class EstimateGenerationPriceLookupIndexRuntime
{
    /** @var list<array{name: string, create: string, drop: string, expected: string}> */
    private const INDEXES = [
        [
            'name' => 'eg_prices_reg_ctx_code_unit_idx',
            'create' => 'CREATE INDEX CONCURRENTLY eg_prices_reg_ctx_code_unit_idx ON estimate_resource_prices (regional_price_version_id, region_id, price_zone_id, period_id, resource_code, unit, id) WHERE base_price > 0',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_prices_reg_ctx_code_unit_idx"',
            'expected' => 'CREATE INDEX eg_prices_reg_ctx_code_unit_idx ON public.estimate_resource_prices USING btree (regional_price_version_id, region_id, price_zone_id, period_id, resource_code, unit, id) WHERE (base_price > (0)::numeric)',
        ],
        [
            'name' => 'eg_prices_base_dataset_code_unit_idx',
            'create' => 'CREATE INDEX CONCURRENTLY eg_prices_base_dataset_code_unit_idx ON estimate_resource_prices (dataset_version_id, resource_code, unit, id) WHERE regional_price_version_id IS NULL AND base_price > 0',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_prices_base_dataset_code_unit_idx"',
            'expected' => 'CREATE INDEX eg_prices_base_dataset_code_unit_idx ON public.estimate_resource_prices USING btree (dataset_version_id, resource_code, unit, id) WHERE ((regional_price_version_id IS NULL) AND (base_price > (0)::numeric))',
        ],
    ];

    public function ensureAll(): void
    {
        foreach (self::INDEXES as $index) {
            $this->ensure($index);
        }
    }

    public function dropAll(): void
    {
        foreach (array_reverse(self::INDEXES) as $index) {
            DB::statement($index['drop']);
        }
    }

    /** @param array{name: string, create: string, drop: string, expected: string} $index */
    private function ensure(array $index): void
    {
        $existing = $this->find($index['name']);
        if ($existing !== null && $this->usable($existing)) {
            if ($this->normalized($existing->definition) !== $this->normalized($index['expected'])) {
                throw new RuntimeException('estimate_generation_price_lookup_index_definition_mismatch');
            }

            return;
        }
        if ($existing !== null) {
            DB::statement($index['drop']);
        }
        DB::statement($index['create']);
        $created = $this->find($index['name']);
        if ($created === null || ! $this->usable($created)
            || $this->normalized($created->definition) !== $this->normalized($index['expected'])) {
            throw new RuntimeException('estimate_generation_price_lookup_index_postcondition_failed');
        }
    }

    private function find(string $name): ?stdClass
    {
        return DB::selectOne(
            'SELECT i.indisvalid, i.indisready, pg_get_indexdef(c.oid) AS definition FROM pg_class AS c INNER JOIN pg_namespace AS n ON n.oid = c.relnamespace INNER JOIN pg_index AS i ON i.indexrelid = c.oid WHERE n.nspname = ? AND c.relname = ?',
            ['public', $name],
        );
    }

    private function usable(stdClass $index): bool
    {
        return $this->boolean($index->indisvalid) && $this->boolean($index->indisready);
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function normalized(mixed $definition): string
    {
        return is_string($definition) ? preg_replace('/\s+/', ' ', trim($definition)) ?? '' : '';
    }
}
