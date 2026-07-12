<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final readonly class NormativeRetrievalBackfillService
{
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
}
