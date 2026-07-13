<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

final class EstimateGenerationResourceIndexRuntime
{
    /** @var list<array{name: string, create: string, drop: string, dropIfExists: string, expected: string}> */
    private const INDEXES = [
        [
            'name' => 'eg_usage_created_desc_idx',
            'create' => 'CREATE INDEX CONCURRENTLY eg_usage_created_desc_idx ON estimate_generation_ai_usage (created_at DESC)',
            'drop' => 'DROP INDEX CONCURRENTLY public."eg_usage_created_desc_idx"',
            'dropIfExists' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_usage_created_desc_idx"',
            'expected' => 'CREATE INDEX eg_usage_created_desc_idx ON public.estimate_generation_ai_usage USING btree (created_at DESC)',
        ],
        [
            'name' => 'eg_usage_requested_model_created_desc_idx',
            'create' => 'CREATE INDEX CONCURRENTLY eg_usage_requested_model_created_desc_idx ON estimate_generation_ai_usage (requested_model, created_at DESC)',
            'drop' => 'DROP INDEX CONCURRENTLY public."eg_usage_requested_model_created_desc_idx"',
            'dropIfExists' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_usage_requested_model_created_desc_idx"',
            'expected' => 'CREATE INDEX eg_usage_requested_model_created_desc_idx ON public.estimate_generation_ai_usage USING btree (requested_model, created_at DESC)',
        ],
        [
            'name' => 'eg_usage_status_created_desc_idx',
            'create' => 'CREATE INDEX CONCURRENTLY eg_usage_status_created_desc_idx ON estimate_generation_ai_usage (status, created_at DESC)',
            'drop' => 'DROP INDEX CONCURRENTLY public."eg_usage_status_created_desc_idx"',
            'dropIfExists' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_usage_status_created_desc_idx"',
            'expected' => 'CREATE INDEX eg_usage_status_created_desc_idx ON public.estimate_generation_ai_usage USING btree (status, created_at DESC)',
        ],
        [
            'name' => 'eg_failure_identities_stage_idx',
            'create' => 'CREATE INDEX CONCURRENTLY eg_failure_identities_stage_idx ON estimate_generation_failure_identities (stage, id)',
            'drop' => 'DROP INDEX CONCURRENTLY public."eg_failure_identities_stage_idx"',
            'dropIfExists' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_failure_identities_stage_idx"',
            'expected' => 'CREATE INDEX eg_failure_identities_stage_idx ON public.estimate_generation_failure_identities USING btree (stage, id)',
        ],
        [
            'name' => 'eg_failure_identities_category_idx',
            'create' => 'CREATE INDEX CONCURRENTLY eg_failure_identities_category_idx ON estimate_generation_failure_identities (category, id)',
            'drop' => 'DROP INDEX CONCURRENTLY public."eg_failure_identities_category_idx"',
            'dropIfExists' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_failure_identities_category_idx"',
            'expected' => 'CREATE INDEX eg_failure_identities_category_idx ON public.estimate_generation_failure_identities USING btree (category, id)',
        ],
        [
            'name' => 'eg_failure_occurrence_recorded_idx',
            'create' => "CREATE INDEX CONCURRENTLY eg_failure_occurrence_recorded_idx ON estimate_generation_failure_events (recorded_at DESC, failure_id) WHERE event_type = 'occurred'",
            'drop' => 'DROP INDEX CONCURRENTLY public."eg_failure_occurrence_recorded_idx"',
            'dropIfExists' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_failure_occurrence_recorded_idx"',
            'expected' => "CREATE INDEX eg_failure_occurrence_recorded_idx ON public.estimate_generation_failure_events USING btree (recorded_at DESC, failure_id) WHERE ((event_type)::text = 'occurred'::text)",
        ],
        [
            'name' => 'eg_failure_resolution_lookup_idx',
            'create' => "CREATE INDEX CONCURRENTLY eg_failure_resolution_lookup_idx ON estimate_generation_failure_events (failure_id, resolves_through_sequence DESC) WHERE event_type = 'resolved'",
            'drop' => 'DROP INDEX CONCURRENTLY public."eg_failure_resolution_lookup_idx"',
            'dropIfExists' => 'DROP INDEX CONCURRENTLY IF EXISTS public."eg_failure_resolution_lookup_idx"',
            'expected' => "CREATE INDEX eg_failure_resolution_lookup_idx ON public.estimate_generation_failure_events USING btree (failure_id, resolves_through_sequence DESC) WHERE ((event_type)::text = 'resolved'::text)",
        ],
    ];

    public function ensureAll(): void
    {
        foreach (self::INDEXES as $index) {
            $this->ensureConcurrentIndex($index);
        }
    }

    public function dropAll(): void
    {
        foreach (array_reverse(self::INDEXES) as $index) {
            DB::statement($index['dropIfExists']);
        }
    }

    /** @param array{name: string, create: string, drop: string, dropIfExists: string, expected: string} $index */
    private function ensureConcurrentIndex(array $index): void
    {
        $existing = $this->findIndex($index['name']);

        if ($existing !== null && $this->isUsable($existing)) {
            if ($this->normalizeDefinition($existing->definition) !== $this->normalizeDefinition($index['expected'])) {
                throw new RuntimeException('estimate_generation_resource_index_definition_mismatch');
            }

            return;
        }

        if ($existing !== null && (! $this->catalogBoolean($existing->indisvalid) || ! $this->catalogBoolean($existing->indisready))) {
            DB::statement($index['drop']);
        }

        DB::statement($index['create']);

        $created = $this->findIndex($index['name']);
        if ($created === null
            || ! $this->isUsable($created)
            || $this->normalizeDefinition($created->definition) !== $this->normalizeDefinition($index['expected'])) {
            throw new RuntimeException('estimate_generation_resource_index_postcondition_failed');
        }
    }

    private function findIndex(string $name): ?stdClass
    {
        return DB::selectOne(
            <<<'SQL'
                SELECT i.indisvalid, i.indisready, pg_get_indexdef(c.oid) AS definition
                FROM pg_class AS c
                INNER JOIN pg_namespace AS n ON n.oid = c.relnamespace
                INNER JOIN pg_index AS i ON i.indexrelid = c.oid
                WHERE n.nspname = 'public' AND c.relname = ?
                SQL,
            [$name],
        );
    }

    private function isUsable(stdClass $index): bool
    {
        return $this->catalogBoolean($index->indisvalid) && $this->catalogBoolean($index->indisready);
    }

    private function catalogBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function normalizeDefinition(mixed $definition): string
    {
        if (! is_string($definition)) {
            return '';
        }

        return preg_replace('/\s+/', ' ', trim($definition)) ?? '';
    }
}
