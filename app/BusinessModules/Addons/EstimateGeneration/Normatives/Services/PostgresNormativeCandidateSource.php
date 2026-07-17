<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class PostgresNormativeCandidateSource implements NormativeCandidateSource
{
    public const QUERY_CONTRACT = <<<'SQL'
WITH lexical AS (
 SELECT n.id, ts_rank_cd(n.search_vector, websearch_to_tsquery('russian', :query)) AS lexical_score, NULL::numeric AS semantic_score
 FROM estimate_norms n JOIN estimate_norm_collections c ON c.id=n.collection_id JOIN estimate_dataset_versions d ON d.id=c.dataset_version_id
 WHERE d.source_type='fsnb_2022' AND d.version_key=:lexical_dataset_version AND d.status='parsed'
  AND n.search_vector @@ websearch_to_tsquery('russian', :query)
 ORDER BY lexical_score DESC, n.id LIMIT :lexical_limit
), semantic AS (
 SELECT s.estimate_norm_id AS id, NULL::real AS lexical_score, s.score AS semantic_score
 FROM estimate_norm_semantic_scores s JOIN estimate_norms n ON n.id=s.estimate_norm_id JOIN estimate_norm_collections c ON c.id=n.collection_id JOIN estimate_dataset_versions d ON d.id=c.dataset_version_id
 WHERE d.source_type='fsnb_2022' AND d.version_key=:semantic_dataset_version AND d.status='parsed'
  AND s.index_version=:semantic_index_version AND s.query_hash=:query_hash
 ORDER BY s.score DESC, s.estimate_norm_id LIMIT :semantic_limit
), candidates AS (
 SELECT id, max(lexical_score) AS lexical_score, max(semantic_score) AS semantic_score FROM (SELECT * FROM lexical UNION ALL SELECT * FROM semantic) p GROUP BY id
)
SELECT n.id, c.dataset_version_id, n.code, n.name, n.canonical_unit AS unit, n.unit_dimension,
       n.material, n.technology, n.structure, n.section_code, n.object_type,
       n.region_code, n.valid_from, n.valid_to, d.version_key, d.status,
       candidates.lexical_score, candidates.semantic_score
FROM candidates JOIN estimate_norms n ON n.id=candidates.id
JOIN estimate_norm_collections c ON c.id = n.collection_id
JOIN estimate_dataset_versions d ON d.id = c.dataset_version_id
ORDER BY n.id
SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private NormativeSearchQueryBuilder $queryBuilder = new NormativeSearchQueryBuilder,
    ) {}

    public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
    {
        $ready = $this->connection->table('estimate_normative_retrieval_rollouts')
            ->where('schema_version', NormativeRetrievalBackfillService::VERSION)->where('deploy_status', 'enabled')->exists();
        if (! $ready) {
            throw new \RuntimeException('Normative retrieval rollout is incomplete.');
        }
        $lexicalQuery = $this->queryBuilder->build($query);
        $rows = $this->connection->select(self::QUERY_CONTRACT, [
            'lexical_dataset_version' => $datasetVersion,
            'semantic_dataset_version' => $datasetVersion,
            'query' => $lexicalQuery,
            'query_hash' => hash('sha256', mb_strtolower(trim($query))),
            'semantic_index_version' => $semanticIndexVersion,
            'lexical_limit' => min(128, max(1, $limit)),
            'semantic_limit' => min(128, max(1, $limit)),
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
