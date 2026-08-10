<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ApplyProjectModelCorrection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class EloquentEstimateDecisionRepository implements EstimateDecisionRepository
{
    public function __construct(
        private DatabaseManager $database,
        private ApplyProjectModelCorrection $corrections,
    ) {}

    public function append(
        string $sessionId,
        string $decisionKey,
        int $expectedVersion,
        array $before,
        array $after,
        string $reason,
        ActorContext $actor,
        string $sourceCommand,
        ?int $revertedDecisionId = null,
    ): EstimateDecision {
        $sourceVersion = $actor->expectedSourceVersion;
        $valueFingerprint = $actor->expectedValueFingerprint;
        if (preg_match('/^[1-9][0-9]*$/', $sessionId) !== 1 || $sourceVersion === null || $valueFingerprint === null) {
            throw new InvalidArgumentException('Estimate decision persistence context is invalid.');
        }

        $result = $sourceCommand === 'apply'
            ? $this->corrections->apply(
                $actor->organizationId,
                $actor->projectId,
                (int) $sessionId,
                $actor->actorId,
                $sourceVersion,
                $valueFingerprint,
                $decisionKey,
                $after,
                $reason,
                $actor->idempotencyKey,
                $expectedVersion,
            )
            : $this->corrections->revert(
                $actor->organizationId,
                $actor->projectId,
                (int) $sessionId,
                $actor->actorId,
                $sourceVersion,
                $valueFingerprint,
                $decisionKey,
                $reason,
                $actor->idempotencyKey,
                $expectedVersion,
            );

        return $this->fromResult($result, $sessionId, $decisionKey, $actor);
    }

    public function latest(string $sessionId, string $decisionKey): ?EstimateDecision
    {
        $row = $this->query($sessionId, $decisionKey)->latest('correction.id')->first($this->columns());

        return $row instanceof stdClass ? $this->fromRow($row, 0, false) : null;
    }

    public function history(string $sessionId, string $decisionKey): array
    {
        return $this->query($sessionId, $decisionKey)
            ->orderBy('correction.id')
            ->get($this->columns())
            ->values()
            ->map(fn (stdClass $row, int $index): EstimateDecision => $this->fromRow($row, $index + 1, false))
            ->all();
    }

    private function query(string $sessionId, string $decisionKey): mixed
    {
        if (preg_match('/^[1-9][0-9]*$/', $sessionId) !== 1) {
            throw new InvalidArgumentException('Estimate decision session is invalid.');
        }

        return $this->database->table('estimate_generation_project_model_corrections as correction')
            ->join('estimate_generation_project_model_assertions as assertion', 'assertion.id', '=', 'correction.assertion_id')
            ->where('correction.session_id', (int) $sessionId)
            ->whereRaw('correction.building_model_id = (select max(current_model.id) from estimate_generation_building_models as current_model where current_model.session_id = correction.session_id)')
            ->where('assertion.stable_key', $decisionKey);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'correction.id',
            'correction.session_id',
            'correction.organization_id',
            'correction.project_id',
            'correction.source_version',
            'correction.stable_key',
            'correction.payload',
            'correction.reason',
            'correction.actor_id',
            'correction.created_at',
            'assertion.stable_key as assertion_stable_key',
        ];
    }

    /** @param array<string, mixed> $result */
    private function fromResult(array $result, string $sessionId, string $decisionKey, ActorContext $actor): EstimateDecision
    {
        $correction = $result['correction'] ?? null;
        if (! is_array($correction)) {
            throw new RuntimeException('estimate_decision_result_invalid');
        }
        $id = (int) ($correction['id'] ?? 0);

        return new EstimateDecision(
            id: $id,
            sessionId: $sessionId,
            decisionKey: $decisionKey,
            version: $this->versionAt($sessionId, $decisionKey, $id),
            before: $this->object($correction['previous_canonical_value'] ?? null),
            after: $this->object($correction['new_canonical_value'] ?? null),
            reason: (string) ($correction['reason'] ?? ''),
            actor: $actor,
            sourceCommand: (string) ($correction['operation'] ?? ''),
            occurredAt: (string) ($correction['created_at'] ?? ''),
            revertedDecisionId: $this->nullableInt($correction['reverted_correction_id'] ?? null),
            idempotent: ($result['idempotent'] ?? false) === true,
            stableKey: (string) ($correction['stable_key'] ?? ''),
            dependencyImpacts: $this->list($correction['dependency_impacts'] ?? []),
        );
    }

    private function fromRow(stdClass $row, int $knownVersion, bool $idempotent): EstimateDecision
    {
        $payload = $this->object($row->payload ?? null);
        $audit = $this->object($payload['audit'] ?? null);
        $before = $this->object($audit['previous_canonical_value'] ?? null);
        $after = $this->object($audit['new_canonical_value'] ?? ($payload['canonical_value'] ?? null));
        $id = (int) $row->id;
        $sessionId = (string) $row->session_id;
        $decisionKey = (string) $row->assertion_stable_key;

        return new EstimateDecision(
            id: $id,
            sessionId: $sessionId,
            decisionKey: $decisionKey,
            version: $knownVersion > 0 ? $knownVersion : $this->versionAt($sessionId, $decisionKey, $id),
            before: $before,
            after: $after,
            reason: (string) $row->reason,
            actor: new ActorContext(
                (int) $row->organization_id,
                (int) $row->project_id,
                (int) $row->actor_id,
                'decision-history-'.$id,
                (string) $row->source_version,
                hash('sha256', json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ),
            sourceCommand: (string) ($audit['operation'] ?? ''),
            occurredAt: (string) $row->created_at,
            revertedDecisionId: $this->nullableInt($audit['reverted_correction_id'] ?? null),
            idempotent: $idempotent,
            stableKey: (string) $row->stable_key,
            dependencyImpacts: $this->list($audit['dependency_impacts'] ?? []),
        );
    }

    private function versionAt(string $sessionId, string $decisionKey, int $id): int
    {
        return $this->query($sessionId, $decisionKey)->where('correction.id', '<=', $id)->count();
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('estimate_decision_payload_invalid');
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('estimate_decision_payload_invalid');
        }

        return $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
