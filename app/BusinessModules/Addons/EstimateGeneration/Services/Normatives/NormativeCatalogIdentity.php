<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final readonly class NormativeCatalogIdentity
{
    public function matches(array $match, object $row): bool
    {
        $identity = $match['catalog_identity'] ?? null;
        if (! is_array($identity)) {
            return false;
        }
        $current = $this->fromDatabaseRow($row);
        $fields = array_fill_keys(['norm_id', 'code', 'name', 'unit', 'collection', 'section'], true);

        return CanonicalPipelineJson::encode($identity) === CanonicalPipelineJson::encode($current)
            && CanonicalPipelineJson::encode(array_intersect_key($match, $fields))
                === CanonicalPipelineJson::encode(array_intersect_key($current, $fields));
    }

    public function fromCandidate(array $candidate): array
    {
        $metadata = $candidate['retrieval_metadata'] ?? [];

        return ['norm_id' => $candidate['normative_id'], 'code' => $candidate['code'], 'name' => $candidate['name'],
            'unit' => $candidate['unit'], 'collection' => $candidate['collection'], 'section' => $candidate['section'],
            'work_composition' => $candidate['work_composition'],
            'metadata' => array_intersect_key($metadata, array_fill_keys([
                'raw_unit', 'unit_dimension', 'material', 'technology', 'structure', 'object_type', 'region_code', 'valid_from', 'valid_to',
            ], true))];
    }

    public function fromDatabaseRow(object $row): array
    {
        $composition = is_array($row->work_composition) ? $row->work_composition : json_decode((string) $row->work_composition, true);

        return ['norm_id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name,
            'unit' => (string) ($row->canonical_unit ?: $row->unit),
            'collection' => ['id' => (int) $row->collection_id, 'code' => (string) $row->collection_code,
                'name' => (string) $row->collection_name, 'norm_type' => (string) $row->norm_type],
            'section' => ['code' => (string) $row->section_code, 'name' => (string) $row->section_name],
            'work_composition' => is_array($composition) ? array_values($composition) : [],
            'metadata' => ['raw_unit' => (string) $row->unit, ...array_intersect_key((array) $row, array_fill_keys([
                'unit_dimension', 'material', 'technology', 'structure', 'object_type', 'region_code', 'valid_from', 'valid_to',
            ], true))]];
    }
}
