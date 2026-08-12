<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;

final readonly class GeometryCoverage
{
    private const STATUSES = [
        'covered_empty',
        'covered_with_entities',
        'unknown',
        'incomplete',
        'conflicted',
        'stale',
    ];

    private function __construct(
        public string $factId,
        public string $relation,
        public string $status,
        public int $entityCount,
        public array $evidenceIds,
        public array $representation,
    ) {}

    public static function fromFact(Fact $fact, string $relation): ?self
    {
        $value = is_array($fact->value) ? $fact->value : [];
        $status = $value['status'] ?? null;
        $entityCount = $value['entity_count'] ?? null;
        $representation = $value['representation'] ?? null;
        if (! in_array($fact->type, ['geometry_coverage', 'geometry_coverage_'.$relation], true)
            || ($value['relation'] ?? null) !== $relation
            || ! is_string($status) || ! in_array($status, self::STATUSES, true)
            || ! is_int($entityCount) || $entityCount < 0
            || ! is_array($representation) || array_is_list($representation)
            || ! is_string($representation['type'] ?? null)
            || ! is_string($representation['id'] ?? null)
            || ! is_string($representation['source_artifact_id'] ?? null)
            || ! is_string($representation['source_version'] ?? null)
            || ! hash_equals($fact->sourceVersion, $representation['source_version'])) {
            return null;
        }

        return new self(
            $fact->id,
            $relation,
            $status,
            $entityCount,
            $fact->evidenceIds,
            $representation,
        );
    }

    public function issue(string $factStatus, int $actualEntityCount): ?array
    {
        if ($factStatus !== 'confirmed') {
            return [
                'code' => in_array($factStatus, ['conflicted', 'invalidated'], true)
                    ? 'geometry_coverage_blocked' : 'geometry_coverage_incomplete',
                'operand' => 'geometry_coverage',
                'fact_id' => $this->factId,
                'coverage_status' => $factStatus,
            ];
        }
        if (in_array($this->status, ['unknown', 'incomplete'], true)) {
            return [
                'code' => 'geometry_coverage_incomplete',
                'operand' => 'geometry_coverage',
                'fact_id' => $this->factId,
                'coverage_status' => $this->status,
            ];
        }
        if (in_array($this->status, ['conflicted', 'stale'], true)) {
            return [
                'code' => 'geometry_coverage_blocked',
                'operand' => 'geometry_coverage',
                'fact_id' => $this->factId,
                'coverage_status' => $this->status,
            ];
        }
        if (($this->status === 'covered_empty' && ($this->entityCount !== 0 || $actualEntityCount !== 0))
            || ($this->status === 'covered_with_entities'
                && ($this->entityCount < 1 || $actualEntityCount !== $this->entityCount))) {
            return [
                'code' => 'geometry_coverage_conflict',
                'operand' => 'geometry_coverage',
                'fact_id' => $this->factId,
                'coverage_status' => $this->status,
            ];
        }

        return null;
    }

    public function identity(): array
    {
        return [
            'fact_id' => $this->factId,
            'relation' => $this->relation,
            'status' => $this->status,
            'entity_count' => $this->entityCount,
            'evidence_ids' => $this->evidenceIds,
            'representation' => $this->representation,
        ];
    }
}
