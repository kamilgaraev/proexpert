<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RecordedCatalogNormativeCandidateSource implements NormativeCandidateSource
{
    public function __construct(private RecordedBenchmarkCatalogData $catalog) {}

    public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
    {
        if ($datasetVersion !== $this->catalog->datasetVersion) {
            return [];
        }

        return array_slice(array_map(static function (array $record): NormativeCandidateData {
            foreach (['candidate_id', 'normative_id', 'dataset_id', 'dataset_version', 'dataset_status', 'code', 'name',
                'unit', 'unit_dimension', 'material', 'technology', 'structure', 'normative_section', 'object_type',
                'region_code', 'valid_from', 'lexical_score', 'source_evidence'] as $key) {
                if (! array_key_exists($key, $record)) {
                    throw new InvalidArgumentException('recorded_catalog_candidate_invalid');
                }
            }

            return new NormativeCandidateData(
                (string) $record['candidate_id'], (int) $record['normative_id'], (int) $record['dataset_id'],
                (string) $record['dataset_version'], (string) $record['dataset_status'], (string) $record['code'],
                (string) $record['name'], (string) $record['unit'], (string) $record['unit_dimension'],
                (string) $record['material'], (string) $record['technology'], (string) $record['structure'],
                (string) $record['normative_section'], (string) $record['object_type'], (string) $record['region_code'],
                new DateTimeImmutable((string) $record['valid_from']), null, (float) $record['lexical_score'],
                isset($record['semantic_score']) ? (float) $record['semantic_score'] : null, 'recorded-catalog:v1',
                null, array_values(array_map('strval', $record['source_evidence'])),
            );
        }, $this->catalog->candidates), 0, $limit);
    }
}
