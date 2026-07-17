<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use Illuminate\Database\Connection;
use RuntimeException;
use stdClass;

final readonly class EloquentEvidenceRepository implements EvidenceRepository
{
    public function __construct(private Connection $database) {}

    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
    {
        return $this->database->transaction(function () use ($organizationId, $sessionId, $callback): mixed {
            if ($this->database->getDriverName() === 'pgsql') {
                $this->database->select('SELECT pg_advisory_xact_lock(?, ?)', [$organizationId, $sessionId]);
            }

            return $callback();
        }, 3);
    }

    public function insertOrGet(EvidenceData $data): EvidenceNode
    {
        $fingerprint = $data->fingerprint();
        $this->database->table('estimate_generation_evidence')->insertOrIgnore([
            'organization_id' => $data->organizationId,
            'project_id' => $data->projectId,
            'session_id' => $data->sessionId,
            'type' => $data->type->value,
            'source_type' => $data->sourceType->value,
            'source_ref' => $data->sourceRef,
            'source_version' => $data->sourceVersion,
            'locator' => json_encode($data->locator, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'value' => json_encode($data->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'confidence' => $data->confidence,
            'producer_name' => $data->producerName,
            'producer_version' => $data->producerVersion,
            'fingerprint' => $fingerprint,
            'invalidation_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $model = $this->database->table('estimate_generation_evidence')
            ->where('organization_id', $data->organizationId)
            ->where('project_id', $data->projectId)
            ->where('session_id', $data->sessionId)
            ->where('fingerprint', $fingerprint)
            ->first();
        if ($model === null) {
            throw new RuntimeException('estimate_generation.evidence_record_failed');
        }
        if ($model->invalidated_at !== null) {
            $this->database->table('estimate_generation_evidence')
                ->where('id', (int) $model->id)
                ->whereNotNull('invalidated_at')
                ->update([
                    'invalidated_at' => null,
                    'invalidation_reason' => null,
                    'invalidation_version' => 0,
                    'updated_at' => now(),
                ]);
            $model = $this->database->table('estimate_generation_evidence')
                ->where('id', (int) $model->id)
                ->first();
            if ($model === null) {
                throw new RuntimeException('estimate_generation.evidence_record_failed');
            }
        }

        return $this->map($model);
    }

    public function node(int $organizationId, int $projectId, int $sessionId, int $id): ?EvidenceNode
    {
        $model = $this->database->table('estimate_generation_evidence')->where('id', $id)
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)->first();

        return $model === null ? null : $this->map($model);
    }

    public function activeNodesForUpdate(int $organizationId, int $projectId, int $sessionId, array $ids): array
    {
        return $this->database->table('estimate_generation_evidence')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->whereIn('id', $ids)
            ->whereNull('invalidated_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (stdClass $model): EvidenceNode => $this->map($model))
            ->all();
    }

    public function addEdge(EvidenceEdge $edge): void
    {
        $this->database->table('estimate_generation_evidence_edges')->insertOrIgnore([
            'organization_id' => $edge->organizationId,
            'project_id' => $edge->projectId,
            'session_id' => $edge->sessionId,
            'parent_id' => $edge->parentId,
            'child_id' => $edge->childId,
            'relation' => $edge->relation->value,
            'created_at' => now(),
        ]);
    }

    public function pathExists(int $organizationId, int $projectId, int $sessionId, int $fromId, int $toId): bool
    {
        $frontier = [$fromId];
        $visited = [];
        while ($frontier !== []) {
            if (in_array($toId, $frontier, true)) {
                return true;
            }
            foreach ($frontier as $id) {
                $visited[$id] = true;
            }
            $frontier = $this->database->table('estimate_generation_evidence_edges')
                ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)
                ->whereIn('parent_id', $frontier)->pluck('child_id')->map(static fn (mixed $id): int => (int) $id)
                ->reject(static fn (int $id): bool => isset($visited[$id]))->unique()->values()->all();
        }

        return false;
    }

    public function descendantBatches(int $organizationId, int $projectId, int $sessionId, array $types, string $ref, string $version, int $chunkSize): iterable
    {
        $temporaryTable = 'eg_evidence_walk_ids';
        $this->database->statement("CREATE TEMP TABLE IF NOT EXISTS {$temporaryTable} (id bigint PRIMARY KEY) ON COMMIT DROP");
        $this->database->table($temporaryTable)->truncate();
        $typeValues = array_map(static fn (EvidenceSourceType $type): string => $type->value, $types);
        $placeholders = implode(',', array_fill(0, count($typeValues), '?'));
        $sql = "INSERT INTO {$temporaryTable} (id) WITH RECURSIVE graph(id) AS (
            SELECT id FROM estimate_generation_evidence
            WHERE organization_id = ? AND project_id = ? AND session_id = ?
              AND source_type IN ({$placeholders}) AND source_ref = ? AND source_version = ?
            UNION
            SELECT edge.child_id FROM estimate_generation_evidence_edges edge
            INNER JOIN graph ON graph.id = edge.parent_id
            WHERE edge.organization_id = ? AND edge.project_id = ? AND edge.session_id = ?
        ) SELECT id FROM graph";
        $bindings = [$organizationId, $projectId, $sessionId, ...$typeValues, $ref, $version, $organizationId, $projectId, $sessionId];
        $this->database->insert($sql, $bindings);
        while (true) {
            $batch = $this->database->table($temporaryTable)->orderBy('id')->limit($chunkSize)->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)->all();
            if ($batch === []) {
                break;
            }
            $this->database->table($temporaryTable)->whereIn('id', $batch)->delete();
            yield $batch;
        }
    }

    public function invalidate(int $organizationId, int $projectId, int $sessionId, array $ids, string $reason): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->database->table('estimate_generation_evidence')->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('session_id', $sessionId)->whereIn('id', $ids)->whereNull('invalidated_at')->update([
                'invalidated_at' => now(), 'invalidation_reason' => $reason,
                'invalidation_version' => $this->database->raw('invalidation_version + 1'), 'updated_at' => now(),
            ]);
    }

    private function map(stdClass $model): EvidenceNode
    {
        $data = new EvidenceData(
            (int) $model->organization_id, (int) $model->project_id, (int) $model->session_id,
            EvidenceType::from((string) $model->type), EvidenceSourceType::from((string) $model->source_type),
            (string) $model->source_ref, (string) $model->source_version,
            $this->jsonObject($model->locator), $this->jsonObject($model->value),
            (float) $model->confidence, (string) $model->producer_name, (string) $model->producer_version,
        );

        return new EvidenceNode((int) $model->id, $data, (string) $model->fingerprint,
            $model->invalidated_at !== null ? new \DateTimeImmutable((string) $model->invalidated_at) : null,
            $model->invalidation_reason !== null ? (string) $model->invalidation_reason : null,
            (int) $model->invalidation_version);
    }

    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }
}
