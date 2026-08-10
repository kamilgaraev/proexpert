<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelSchema;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecisionConflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecisionUndoUnavailable;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class ApplyProjectModelCorrection
{
    public function __construct(
        private DatabaseManager $database,
        private ApplyProjectModelDecision $projectModelDecision,
    ) {}

    public function apply(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $actorId,
        string $expectedSourceVersion,
        string $expectedValueFingerprint,
        string $assertionStableKey,
        array $value,
        string $reason,
        string $idempotencyKey,
        int $expectedDecisionVersion = 0,
    ): array {
        return $this->execute(
            $organizationId,
            $projectId,
            $sessionId,
            $actorId,
            $expectedSourceVersion,
            $expectedValueFingerprint,
            $assertionStableKey,
            $value,
            $reason,
            $idempotencyKey,
            'apply',
            $expectedDecisionVersion,
        );
    }

    public function revert(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $actorId,
        string $expectedSourceVersion,
        string $expectedValueFingerprint,
        string $assertionStableKey,
        string $reason,
        string $idempotencyKey,
        int $expectedDecisionVersion = 0,
    ): array {
        return $this->execute(
            $organizationId,
            $projectId,
            $sessionId,
            $actorId,
            $expectedSourceVersion,
            $expectedValueFingerprint,
            $assertionStableKey,
            null,
            $reason,
            $idempotencyKey,
            'revert',
            $expectedDecisionVersion,
        );
    }

    private function execute(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $actorId,
        string $expectedSourceVersion,
        string $expectedValueFingerprint,
        string $assertionStableKey,
        ?array $value,
        string $reason,
        string $idempotencyKey,
        string $operation,
        int $expectedDecisionVersion,
    ): array {
        $this->assertCommand($organizationId, $projectId, $sessionId, $actorId, $expectedSourceVersion, $expectedValueFingerprint, $assertionStableKey, $value, $reason, $idempotencyKey, $operation, $expectedDecisionVersion);

        return $this->database->connection()->transaction(function () use ($organizationId, $projectId, $sessionId, $actorId, $expectedSourceVersion, $expectedValueFingerprint, $assertionStableKey, $value, $reason, $idempotencyKey, $operation, $expectedDecisionVersion): array {
            $this->lockSession($organizationId, $projectId, $sessionId);
            $model = $this->model($organizationId, $projectId, $sessionId, $expectedSourceVersion);
            $requestHash = $this->requestHash($operation, $expectedSourceVersion, $expectedValueFingerprint, $assertionStableKey, $value, $reason);
            $idempotencyHash = hash('sha256', $idempotencyKey);
            $existing = $this->idempotentCorrection($model, $organizationId, $projectId, $sessionId, $idempotencyHash, $requestHash);
            if ($existing !== null) {
                $this->syncDecision(
                    $existing,
                    $organizationId,
                    $projectId,
                    $sessionId,
                    $expectedSourceVersion,
                    $assertionStableKey,
                    $actorId,
                    $reason,
                );

                return $this->result($existing, true);
            }
            $assertion = $this->assertion($model, $organizationId, $projectId, $sessionId, $expectedSourceVersion, $assertionStableKey);
            $latest = $this->latestCorrection($model, $organizationId, $projectId, $sessionId, (int) $assertion->id);
            if ($this->decisionVersion($model, $organizationId, $projectId, $sessionId, (int) $assertion->id) !== $expectedDecisionVersion) {
                throw new EstimateDecisionConflict('project_model_correction_stale');
            }
            $previous = $this->currentValue($assertion, $latest);
            if (! hash_equals(ProjectModelValueFingerprint::for($previous), $expectedValueFingerprint)) {
                throw new EstimateDecisionConflict('project_model_correction_stale');
            }
            if ($operation === 'revert') {
                if ($latest === null || ($this->audit($latest)['operation'] ?? null) !== 'apply') {
                    throw new EstimateDecisionUndoUnavailable('project_model_correction_undo_unavailable');
                }
                $next = $this->audit($latest)['previous_canonical_value'] ?? null;
                if (! is_array($next)) {
                    throw new EstimateDecisionUndoUnavailable('project_model_correction_undo_unavailable');
                }
                $revertedCorrectionId = (int) $latest->id;
            } else {
                $next = $value;
                $revertedCorrectionId = null;
            }
            if (! is_array($next)) {
                throw new InvalidArgumentException('Project model correction value is required.');
            }
            $this->assertValue((string) $assertion->assertion_type, $next);
            $impacts = $this->dependencyImpacts($model, $organizationId, $projectId, $sessionId, $expectedSourceVersion, (int) $assertion->entity_id);
            $occurredAt = now();
            $payload = [
                'canonical_value' => $next,
                'audit' => [
                    'schema_version' => 'project-model-correction:v1',
                    'operation' => $operation,
                    'previous_canonical_value' => $previous,
                    'new_canonical_value' => $next,
                    'dependency_impacts' => $impacts,
                    'idempotency_key_hash' => $idempotencyHash,
                    'request_hash' => $requestHash,
                    'reverted_correction_id' => $revertedCorrectionId,
                ],
            ];
            $correctionStableKey = $this->stableKey($idempotencyHash);
            $selectedFactStableKey = 'fact:decision:'.substr($idempotencyHash, 0, 48);
            $id = $this->database->table('estimate_generation_project_model_corrections')->insertGetId([
                'building_model_id' => (int) $model->id,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'session_id' => $sessionId,
                'source_version' => $expectedSourceVersion,
                'stable_key' => $correctionStableKey,
                'assertion_id' => (int) $assertion->id,
                'correction_type' => 'manual',
                'payload' => BuildingModelSchema::canonicalJson($payload),
                'reason' => trim($reason),
                'actor_id' => $actorId,
                'decision_actor_type' => 'user',
                'system_actor_key' => null,
                'decision_version' => $expectedDecisionVersion + 1,
                'target_conflict_key' => null,
                'selected_fact_stable_key' => $selectedFactStableKey,
                'evidence_lineage' => BuildingModelSchema::canonicalJson(
                    $this->decisionEvidence($organizationId, $projectId, $sessionId, (int) $assertion->id),
                ),
                'created_at' => $occurredAt,
            ]);
            $created = $this->database->table('estimate_generation_project_model_corrections')->where('id', $id)->first();
            if (! $created instanceof stdClass) {
                throw new RuntimeException('project_model_correction_persist_failed');
            }
            $this->syncDecision(
                $created,
                $organizationId,
                $projectId,
                $sessionId,
                $expectedSourceVersion,
                $assertionStableKey,
                $actorId,
                $reason,
            );

            return $this->result($created, false);
        }, 3);
    }

    private function assertCommand(int $organizationId, int $projectId, int $sessionId, int $actorId, string $expectedSourceVersion, string $expectedValueFingerprint, string $assertionStableKey, ?array $value, string $reason, string $idempotencyKey, string $operation, int $expectedDecisionVersion): void
    {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1 || $actorId < 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', $expectedSourceVersion) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedValueFingerprint) !== 1
            || preg_match('/^[a-z][a-z0-9:_-]{0,191}$/', $assertionStableKey) !== 1
            || trim($reason) === '' || mb_strlen($reason) > 1000
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $idempotencyKey) !== 1
            || ! in_array($operation, ['apply', 'revert'], true)
            || $expectedDecisionVersion < 0
            || ($operation === 'apply' && ! is_array($value))
            || ($operation === 'revert' && $value !== null)) {
            throw new InvalidArgumentException('Project model correction command is invalid.');
        }
    }

    private function lockSession(int $organizationId, int $projectId, int $sessionId): void
    {
        $session = $this->database->table('estimate_generation_sessions')
            ->where('id', $sessionId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->first(['id']);
        if (! $session instanceof stdClass) {
            throw new ProjectModelCorrectionNotFound('project_model_correction_scope_not_found');
        }
    }

    private function model(int $organizationId, int $projectId, int $sessionId, string $expectedSourceVersion): stdClass
    {
        $model = $this->database->table('estimate_generation_building_models')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->latest('id')
            ->lockForUpdate()
            ->first(['id', 'content_version']);
        if (! $model instanceof stdClass || ! hash_equals($expectedSourceVersion, (string) $model->content_version)) {
            throw new EstimateDecisionConflict('project_model_correction_stale');
        }

        return $model;
    }

    private function assertion(stdClass $model, int $organizationId, int $projectId, int $sessionId, string $sourceVersion, string $stableKey): stdClass
    {
        $assertion = $this->database->table('estimate_generation_project_model_assertions')
            ->where('building_model_id', (int) $model->id)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('source_version', $sourceVersion)
            ->where('stable_key', $stableKey)
            ->lockForUpdate()
            ->first(['id', 'entity_id', 'assertion_type', 'payload']);
        if (! $assertion instanceof stdClass) {
            throw new ProjectModelCorrectionNotFound('project_model_correction_assertion_not_found');
        }

        return $assertion;
    }

    private function latestCorrection(stdClass $model, int $organizationId, int $projectId, int $sessionId, int $assertionId): ?stdClass
    {
        $correction = $this->database->table('estimate_generation_project_model_corrections')
            ->where('building_model_id', (int) $model->id)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('source_version', (string) $model->content_version)
            ->where('assertion_id', $assertionId)
            ->latest('id')
            ->lockForUpdate()
            ->first(['id', 'stable_key', 'assertion_id', 'payload', 'reason', 'actor_id', 'created_at']);

        return $correction instanceof stdClass ? $correction : null;
    }

    private function currentValue(stdClass $assertion, ?stdClass $latest): array
    {
        if ($latest !== null) {
            $audit = $this->audit($latest);
            $value = $audit['new_canonical_value'] ?? $this->canonicalValue($latest);
            if (is_array($value)) {
                return $value;
            }
            throw new RuntimeException('project_model_correction_history_invalid');
        }
        $value = $this->array($assertion->payload);
        unset($value['source']);

        return $value;
    }

    private function decisionVersion(stdClass $model, int $organizationId, int $projectId, int $sessionId, int $assertionId): int
    {
        return $this->database->table('estimate_generation_project_model_corrections')
            ->where('building_model_id', (int) $model->id)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('source_version', (string) $model->content_version)
            ->where('assertion_id', $assertionId)
            ->count();
    }

    private function idempotentCorrection(stdClass $model, int $organizationId, int $projectId, int $sessionId, string $idempotencyHash, string $requestHash): ?stdClass
    {
        $correction = $this->database->table('estimate_generation_project_model_corrections')
            ->where('building_model_id', (int) $model->id)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('source_version', (string) $model->content_version)
            ->where('stable_key', $this->stableKey($idempotencyHash))
            ->lockForUpdate()
            ->first(['id', 'stable_key', 'assertion_id', 'payload', 'reason', 'actor_id', 'created_at']);
        if (! $correction instanceof stdClass) {
            return null;
        }
        $audit = $this->audit($correction);
        if (! hash_equals((string) ($audit['request_hash'] ?? ''), $requestHash)) {
            throw new EstimateDecisionConflict('project_model_correction_idempotency_conflict');
        }

        return $correction;
    }

    private function dependencyImpacts(stdClass $model, int $organizationId, int $projectId, int $sessionId, string $sourceVersion, int $entityId): array
    {
        return $this->database->table('estimate_generation_project_model_relations')
            ->where('building_model_id', (int) $model->id)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('source_version', $sourceVersion)
            ->where(static function ($query) use ($entityId): void {
                $query->where('from_entity_id', $entityId)->orWhere('to_entity_id', $entityId);
            })
            ->orderBy('stable_key')
            ->get(['stable_key', 'relation_type', 'from_entity_id', 'to_entity_id'])
            ->map(static fn (stdClass $relation): array => [
                'stable_key' => (string) $relation->stable_key,
                'relation_type' => (string) $relation->relation_type,
                'direction' => (int) $relation->from_entity_id === $entityId ? 'outgoing' : 'incoming',
            ])
            ->all();
    }

    private function assertValue(string $assertionType, array $value): void
    {
        $valid = match ($assertionType) {
            'area' => $this->positiveNumber($value['value'] ?? null) && ($value['unit'] ?? null) === 'm2' && count($value) === 2,
            'dimension' => $this->positiveNumber($value['value'] ?? null)
                && is_string($value['unit'] ?? null)
                && in_array($value['unit'], ['m', 'm2', 'm3', 'pcs', 'kg', 't', 'h'], true)
                && count($value) === 2,
            'room_purpose' => is_string($value['value'] ?? null) && trim($value['value']) !== '' && mb_strlen($value['value']) <= 1000 && count($value) === 1,
            'opening' => in_array($value['type'] ?? null, ['door', 'window', 'gate'], true)
                && $this->positiveNumber($value['width_m'] ?? null)
                && $this->positiveNumber($value['height_m'] ?? null)
                && count($value) === 3,
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Project model correction value is invalid.');
        }
    }

    private function positiveNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }

    private function requestHash(string $operation, string $sourceVersion, string $fingerprint, string $assertionStableKey, ?array $value, string $reason): string
    {
        return hash('sha256', BuildingModelSchema::canonicalJson([
            'operation' => $operation,
            'expected_source_version' => $sourceVersion,
            'expected_value_fingerprint' => $fingerprint,
            'assertion_stable_key' => $assertionStableKey,
            'value' => $value,
            'reason' => trim($reason),
        ]));
    }

    private function stableKey(string $idempotencyHash): string
    {
        return 'correction:'.$idempotencyHash;
    }

    private function syncDecision(
        stdClass $correction,
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $factId,
        int $actorId,
        string $reason,
    ): void {
        $audit = $this->audit($correction);
        $value = $audit['new_canonical_value'] ?? null;
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('project_model_correction_decision_invalid');
        }
        $this->projectModelDecision->apply(
            organizationId: $organizationId,
            projectId: $projectId,
            sessionId: $sessionId,
            sourceVersion: $sourceVersion,
            factId: $factId,
            value: $value['value'] ?? $value,
            unit: is_string($value['unit'] ?? null) ? $value['unit'] : null,
            actorId: (string) $actorId,
            reason: $reason,
            decisionId: (string) $correction->stable_key,
        );
    }

    private function decisionEvidence(int $organizationId, int $projectId, int $sessionId, int $assertionId): array
    {
        return $this->database->table('estimate_generation_project_model_fact_evidence')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('fact_id', $assertionId)
            ->orderBy('evidence_id')
            ->pluck('evidence_id')
            ->map(static fn ($id): string => 'evidence:'.$id)
            ->all();
    }

    private function result(stdClass $correction, bool $idempotent): array
    {
        $audit = $this->audit($correction);

        return [
            'idempotent' => $idempotent,
            'correction' => [
                'id' => (int) $correction->id,
                'stable_key' => (string) $correction->stable_key,
                'operation' => (string) ($audit['operation'] ?? ''),
                'previous_canonical_value' => $audit['previous_canonical_value'] ?? null,
                'new_canonical_value' => $audit['new_canonical_value'] ?? $this->canonicalValue($correction),
                'dependency_impacts' => $audit['dependency_impacts'] ?? [],
                'reverted_correction_id' => $audit['reverted_correction_id'] ?? null,
                'reason' => (string) $correction->reason,
                'actor_id' => (int) $correction->actor_id,
                'created_at' => (string) $correction->created_at,
            ],
        ];
    }

    private function audit(stdClass $correction): array
    {
        $payload = $this->array($correction->payload);

        return is_array($payload['audit'] ?? null) ? $payload['audit'] : [];
    }

    private function canonicalValue(stdClass $correction): array
    {
        $payload = $this->array($correction->payload);
        $value = $payload['canonical_value'] ?? $payload;
        if (! is_array($value)) {
            throw new RuntimeException('project_model_correction_history_invalid');
        }

        return $value;
    }

    private function array(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new RuntimeException('project_model_correction_history_invalid');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('project_model_correction_history_invalid');
        }

        return $decoded;
    }
}
