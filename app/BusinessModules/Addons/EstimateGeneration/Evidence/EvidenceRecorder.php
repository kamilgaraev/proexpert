<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use RuntimeException;

final readonly class EvidenceRecorder
{
    public function __construct(private EvidenceRepository $repository) {}

    /** @param list<EvidenceParent> $parents */
    public function record(EvidenceData $data, array $parents = []): EvidenceNode
    {
        return $this->repository->transaction($data->organizationId, $data->sessionId, function () use ($data, $parents): EvidenceNode {
            $node = $this->repository->insertOrGet($data);
            $this->attach($data, $node->id, $parents);

            return $node;
        });
    }

    /** @param list<EvidenceParent> $parents */
    public function attach(EvidenceData $data, int $childId, array $parents): void
    {
        $child = $this->repository->node($data->organizationId, $data->projectId, $data->sessionId, $childId);
        if ($child === null || $child->invalidatedAt !== null) {
            throw new RuntimeException('estimate_generation.evidence_child_invalid');
        }
        foreach ($parents as $parentLink) {
            if (! $parentLink instanceof EvidenceParent) {
                throw new RuntimeException('estimate_generation.evidence_parent_invalid');
            }
            if ($parentLink->id === $childId) {
                throw new RuntimeException('estimate_generation.evidence_self_edge');
            }
            $parent = $this->repository->node($data->organizationId, $data->projectId, $data->sessionId, $parentLink->id);
            if ($parent === null) {
                throw new RuntimeException('estimate_generation.evidence_parent_scope_invalid');
            }
            if ($parent->invalidatedAt !== null) {
                throw new RuntimeException('estimate_generation.evidence_parent_invalidated');
            }
            if (! EvidenceTransitionPolicy::allows($parent->type, $parentLink->relation, $child->type)) {
                throw new RuntimeException('estimate_generation.evidence_transition_invalid');
            }
            if ($this->repository->pathExists($data->organizationId, $data->projectId, $data->sessionId, $childId, $parent->id)) {
                throw new RuntimeException('estimate_generation.evidence_cycle');
            }
            $this->repository->addEdge(new EvidenceEdge(
                $data->organizationId,
                $data->projectId,
                $data->sessionId,
                $parent->id,
                $childId,
                $parentLink->relation,
            ));
        }
    }
}
