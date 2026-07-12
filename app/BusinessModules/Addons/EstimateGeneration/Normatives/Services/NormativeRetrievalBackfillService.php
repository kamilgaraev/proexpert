<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final readonly class NormativeRetrievalBackfillService
{
    public const VERSION = 'normative-retrieval-v1';

    public function __construct(private ConnectionInterface $connection) {}

    /** @return array{processed: int, next_cursor: int, complete: bool} */
    public function backfill(int $cursor, int $batchSize): array
    {
        if ($cursor < 0 || $batchSize < 1 || $batchSize > 5000) {
            throw new InvalidArgumentException('Normative backfill bounds are invalid.');
        }
        $ids = array_map('intval', array_column($this->connection->select(
            'SELECT id FROM estimate_norms WHERE id > :cursor ORDER BY id LIMIT :batch',
            ['cursor' => $cursor, 'batch' => $batchSize],
        ), 'id'));
        if ($ids === []) {
            return ['processed' => 0, 'next_cursor' => $cursor, 'complete' => true];
        }
        $this->connection->statement(<<<'SQL'
UPDATE estimate_norms SET
 canonical_unit = COALESCE(canonical_unit, unit),
 unit_dimension = COALESCE(unit_dimension, CASE WHEN unit IN ('м2','м²') THEN 'area' WHEN unit IN ('м3','м³') THEN 'volume' WHEN unit IN ('м','м.п.') THEN 'length' WHEN unit IN ('шт','компл') THEN 'count' END),
 material = COALESCE(material, NULLIF(raw_payload->>'material','')),
 technology = COALESCE(technology, NULLIF(raw_payload->>'technology','')),
 structure = COALESCE(structure, NULLIF(raw_payload->>'structure','')),
 object_type = COALESCE(object_type, NULLIF(raw_payload->>'object_type','')),
 region_code = COALESCE(region_code, NULLIF(raw_payload->>'region_code','')),
 valid_from = COALESCE(valid_from, CASE WHEN pg_input_is_valid(raw_payload->>'valid_from','date') THEN (raw_payload->>'valid_from')::date END),
 valid_to = COALESCE(valid_to, CASE WHEN pg_input_is_valid(raw_payload->>'valid_to','date') THEN (raw_payload->>'valid_to')::date END),
 search_vector = to_tsvector('russian', coalesce(code,'') || ' ' || coalesce(name,'') || ' ' || coalesce(section_name,''))
WHERE id = ANY(:ids)
SQL, ['ids' => '{'.implode(',', $ids).'}']);

        return ['processed' => count($ids), 'next_cursor' => max($ids), 'complete' => count($ids) < $batchSize];
    }

    public function resume(int $batchSize): array
    {
        try {
            return $this->connection->transaction(function () use ($batchSize): array {
                $state = $this->connection->table('estimate_normative_retrieval_rollouts')->where('schema_version', self::VERSION)->lockForUpdate()->first();
                if ($state === null) {
                    $target = (int) $this->connection->table('estimate_norms')->max('id');
                    $this->connection->table('estimate_normative_retrieval_rollouts')->insert(['schema_version' => self::VERSION, 'cursor' => 0, 'target_max_id' => $target, 'backfill_status' => 'running', 'deploy_phase' => 'pending', 'deploy_status' => 'pending', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    $state = (object) ['cursor' => 0, 'target_max_id' => $target];
                }
                $currentMax = (int) $this->connection->table('estimate_norms')->max('id');
                $targetMax = max((int) $state->target_max_id, $currentMax);
                if ($targetMax !== (int) $state->target_max_id) {
                    $this->connection->table('estimate_normative_retrieval_rollouts')->where('schema_version', self::VERSION)->update(['target_max_id' => $targetMax, 'backfill_status' => 'running', 'deploy_status' => 'pending', 'completed_at' => null, 'updated_at' => now()]);
                }
                $result = $this->backfill((int) $state->cursor, $batchSize);
                $remaining = $this->connection->table('estimate_norms')->where('id', '<=', $targetMax)
                    ->where(static fn ($query) => $query->whereNull('canonical_unit')->orWhereNull('search_vector'))->exists();
                $complete = $result['next_cursor'] >= $targetMax && ! $remaining;
                $this->connection->table('estimate_normative_retrieval_rollouts')->where('schema_version', self::VERSION)->update(['cursor' => $result['next_cursor'], 'backfill_status' => $complete ? 'complete' : 'running', 'completed_at' => $complete ? now() : null, 'last_error' => null, 'updated_at' => now()]);

                return [...$result, 'complete' => $complete];
            });
        } catch (\Throwable $exception) {
            $this->connection->table('estimate_normative_retrieval_rollouts')->updateOrInsert(
                ['schema_version' => self::VERSION],
                ['backfill_status' => 'failed', 'last_error' => mb_substr($exception::class, 0, 255), 'updated_at' => now()],
            );
            throw $exception;
        }
    }
}
