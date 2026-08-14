<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use InvalidArgumentException;

final readonly class EstimateComposerInputFactory
{
    public function __construct(
        private ProjectModelRepository $models,
        private int $maxFacts,
    ) {
        if ($maxFacts < 1 || $maxFacts > 10000) {
            throw new InvalidArgumentException('estimate_composer_fact_limit_invalid');
        }
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param list<array<string, mixed>> $derivedQuantities
     * @param list<array<string, mixed>> $missingDocuments
     */
    public function capture(
        int $organizationId,
        int $projectId,
        int $sessionId,
        array $candidates,
        array $derivedQuantities,
        array $missingDocuments,
    ): EstimateComposerInput {
        $capture = $this->models->snapshotForPlanning(
            $organizationId,
            $projectId,
            $sessionId,
            $this->maxFacts + 1,
        );
        $snapshot = $capture['snapshot'];
        if (count($snapshot->facts) > $this->maxFacts) {
            throw new InvalidArgumentException('estimate_composer_fact_limit_exceeded');
        }
        $factIds = array_map(static fn (Fact $fact): string => $fact->id, $snapshot->facts);
        $factIdSet = array_fill_keys($factIds, true);
        foreach ($candidates as $candidate) {
            foreach (is_array($candidate['source_fact_ids'] ?? null) ? $candidate['source_fact_ids'] : [] as $factId) {
                if (! is_string($factId) || ! isset($factIdSet[$factId])) {
                    throw new InvalidArgumentException('estimate_composer_candidate_source_invalid');
                }
            }
        }
        $decisions = $this->models->decisionsForSelectedFacts(
            $organizationId,
            $projectId,
            $sessionId,
            $factIds,
        );
        $verified = $this->models->snapshotForPlanning(
            $organizationId,
            $projectId,
            $sessionId,
            $this->maxFacts + 1,
        );
        if (! hash_equals((string) $capture['token'], (string) $verified['token'])) {
            throw new InvalidArgumentException('estimate_composer_snapshot_changed');
        }

        return new EstimateComposerInput(
            organizationId: $organizationId,
            projectId: $projectId,
            sessionId: $sessionId,
            snapshotToken: (string) $capture['token'],
            facts: array_map($this->fact(...), $snapshot->facts),
            derivedQuantities: $derivedQuantities,
            decisions: array_map($this->decision(...), $decisions),
            candidates: $candidates,
            missingDocuments: $missingDocuments,
            contractVersion: RunEstimateComposer::PROMPT_CONTRACT,
        );
    }

    /** @return array<string, mixed> */
    private function fact(Fact $fact): array
    {
        return [
            'id' => $fact->id,
            'source_version' => $fact->sourceVersion,
            'entity_id' => $fact->entityId,
            'type' => $fact->type,
            'value' => $fact->value,
            'unit' => $fact->unit,
            'origin' => $fact->origin,
            'status' => $fact->status,
            'evidence_ids' => $fact->evidenceIds,
            'version' => $fact->version,
        ];
    }

    /** @return array<string, mixed> */
    private function decision(Decision $decision): array
    {
        return [
            'id' => $decision->id,
            'source_version' => $decision->sourceVersion,
            'target_type' => $decision->targetType,
            'target_id' => $decision->targetId,
            'selected_fact_id' => $decision->selectedFactId,
            'actor_type' => $decision->actorType,
            'reason' => $decision->reason,
            'version' => $decision->version,
            'evidence_ids' => $decision->evidenceIds,
        ];
    }
}
