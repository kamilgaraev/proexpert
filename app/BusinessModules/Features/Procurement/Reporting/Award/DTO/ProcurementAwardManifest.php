<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardCompleteness;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;

final readonly class ProcurementAwardManifest
{
    public int $candidateCount;

    public int $comparableCount;

    public function __construct(
        public array $candidates,
        public ProcurementAwardCompleteness $completeness,
        public int $selectedProposalId,
        public ?int $selectedProposalVersionId,
        public ?int $cheapestProposalId,
        public ?int $cheapestProposalVersionId,
        public ?int $selectedRank,
        public ?int $cheapestRank,
        public array $quarantineCodes,
    ) {
        $this->candidateCount = count($candidates);
        $this->comparableCount = count(array_filter(
            $candidates,
            static fn (ProcurementAwardCandidateEvidence $candidate): bool => $candidate->comparable,
        ));
    }

    public function canonicalPayload(): array
    {
        return [
            'candidates' => array_map(
                static fn (ProcurementAwardCandidateEvidence $candidate): array => $candidate->canonicalPayload(),
                $this->candidates,
            ),
            'completeness' => $this->completeness->value,
            'selected_proposal_id' => $this->selectedProposalId,
            'selected_proposal_version_id' => $this->selectedProposalVersionId,
            'cheapest_proposal_id' => $this->cheapestProposalId,
            'cheapest_proposal_version_id' => $this->cheapestProposalVersionId,
            'selected_rank' => $this->selectedRank,
            'cheapest_rank' => $this->cheapestRank,
            'quarantine_codes' => $this->quarantineCodes,
        ];
    }

    public function contentHash(): string
    {
        return ProcurementAwardCanonicalizer::framedHash([
            $this->completeness->value,
            $this->selectedProposalId,
            $this->selectedProposalVersionId,
            $this->cheapestProposalId,
            $this->cheapestProposalVersionId,
            $this->selectedRank,
            $this->cheapestRank,
            implode(',', $this->quarantineCodes),
            implode(',', array_map(
                static fn (ProcurementAwardCandidateEvidence $candidate): string => $candidate->contentHash(),
                $this->candidates,
            )),
        ]);
    }
}
