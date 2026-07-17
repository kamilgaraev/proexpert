<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class NormativeMatchTelemetry
{
    private int $requiredItems = 0;

    private int $pinnedCandidatesMissing = 0;

    private int $pinnedCandidatesFound = 0;

    private int $workflowRejected = 0;

    private int $matchedItems = 0;

    /** @var array<string, int> */
    private array $rejectionReasons = [];

    /** @var array<string, int> */
    private array $blockedReasons = [];

    public function required(): void
    {
        $this->requiredItems++;
    }

    public function missingPinnedCandidate(): void
    {
        $this->pinnedCandidatesMissing++;
        $this->blocked('pinned_candidate_missing');
    }

    public function pinnedCandidatesFound(int $count): void
    {
        $this->pinnedCandidatesFound += max(0, $count);
    }

    /** @param list<string> $reasonCodes */
    public function rejected(array $reasonCodes): void
    {
        $this->workflowRejected++;
        $this->blocked('workflow_rejected');
        foreach ($reasonCodes as $reasonCode) {
            if ($reasonCode === '') {
                continue;
            }
            $this->rejectionReasons[$reasonCode] = ($this->rejectionReasons[$reasonCode] ?? 0) + 1;
        }
    }

    public function matched(): void
    {
        $this->matchedItems++;
    }

    public function blocked(string $reason): void
    {
        if ($reason === '') {
            return;
        }
        $this->blockedReasons[$reason] = ($this->blockedReasons[$reason] ?? 0) + 1;
    }

    /** @return array{required_items_count: int, pinned_candidates_missing_count: int, pinned_candidates_found_count: int, workflow_rejected_count: int, matched_items_count: int, blocked_reason_counts: array<string, int>, rejection_reason_counts: array<string, int>} */
    public function context(): array
    {
        ksort($this->blockedReasons);
        ksort($this->rejectionReasons);

        return [
            'required_items_count' => $this->requiredItems,
            'pinned_candidates_missing_count' => $this->pinnedCandidatesMissing,
            'pinned_candidates_found_count' => $this->pinnedCandidatesFound,
            'workflow_rejected_count' => $this->workflowRejected,
            'matched_items_count' => $this->matchedItems,
            'blocked_reason_counts' => $this->blockedReasons,
            'rejection_reason_counts' => $this->rejectionReasons,
        ];
    }
}
