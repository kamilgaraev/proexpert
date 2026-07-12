<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class PostgresNormativeCandidateSource implements NormativeCandidateSource
{
    public const INDEX_CONTRACT = 'CREATE INDEX CONCURRENTLY estimate_norms_dataset_search_v1 ON estimate_norms (organization_id, project_id, dataset_version_id, code) INCLUDE (name, unit)';

    public const QUERY_CONTRACT = <<<'SQL'
SELECT n.id, n.dataset_version_id, n.code, n.name, n.unit, n.unit_dimension,
       n.material, n.technology, n.structure, n.section_code, n.object_type,
       n.region_code, n.valid_from, n.valid_to, d.version_key, d.status,
       ts_rank_cd(n.search_vector, websearch_to_tsquery('russian', :query)) AS lexical_score,
       CASE WHEN :semantic_index_version IS NULL THEN NULL ELSE s.score END AS semantic_score
FROM estimate_norms n
JOIN estimate_dataset_versions d ON d.id = n.dataset_version_id
LEFT JOIN estimate_norm_semantic_scores s ON s.norm_id = n.id
 AND s.index_version = :semantic_index_version
WHERE n.organization_id = :organization_id AND n.project_id = :project_id
 AND d.version_key = :dataset_version AND d.status = 'published'
 AND n.search_vector @@ websearch_to_tsquery('russian', :query)
ORDER BY lexical_score DESC, semantic_score DESC NULLS LAST, n.id ASC
LIMIT :limit
SQL;

    public function __construct(private ConnectionInterface $connection) {}

    public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
    {
        $rows = $this->connection->select(self::QUERY_CONTRACT, [
            'organization_id' => $organizationId, 'project_id' => $projectId,
            'dataset_version' => $datasetVersion, 'query' => mb_substr($query, 0, 1000),
            'limit' => min(32, max(1, $limit)), 'semantic_index_version' => $semanticIndexVersion,
        ]);

        return array_map(static fn (object $row): NormativeCandidateData => new NormativeCandidateData(
            id: (string) $row->id, normativeId: (int) $row->id, datasetId: (int) $row->dataset_version_id,
            datasetVersion: (string) $row->version_key, datasetStatus: (string) $row->status,
            code: (string) $row->code, name: (string) $row->name, canonicalUnit: $row->unit,
            unitDimension: $row->unit_dimension, material: $row->material, technology: $row->technology,
            structure: $row->structure, normativeSection: $row->section_code, objectType: $row->object_type,
            regionCode: $row->region_code, validFrom: $row->valid_from ? new DateTimeImmutable($row->valid_from) : null,
            validTo: $row->valid_to ? new DateTimeImmutable($row->valid_to) : null,
            lexicalScore: (float) $row->lexical_score,
            semanticScore: $row->semantic_score === null ? null : (float) $row->semantic_score,
            lexicalAlgorithmVersion: 'postgres-tsvector-russian-v1', semanticIndexVersion: $semanticIndexVersion,
            sourceEvidence: ['norm:'.(string) $row->id, 'dataset:'.(string) $row->dataset_version_id],
        ), $rows);
    }
}
