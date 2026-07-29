<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ComparableProposalVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProposalComparabilityPartition;
use DomainException;

final readonly class SupplierProposalComparabilityPolicy
{
    public function comparable(array $proposals, int $selectedProposalVersionId): array
    {
        return $this->partition($proposals, $selectedProposalVersionId)->comparable;
    }

    public function partition(array $proposals, int $selectedProposalVersionId): ProposalComparabilityPartition
    {
        $selected = null;
        foreach ($proposals as $proposal) {
            if (! $proposal instanceof ComparableProposalVersion) {
                throw new DomainException('Proposal comparability requires typed proposal versions.');
            }
            if ($proposal->proposalVersionId === $selectedProposalVersionId) {
                $selected = $proposal;
            }
        }
        if (! $selected instanceof ComparableProposalVersion) {
            return new ProposalComparabilityPartition([], []);
        }

        $comparable = [];
        $excluded = [];
        foreach ($proposals as $proposal) {
            $reason = $this->exclusionReason($selected, $proposal);
            if ($reason === null) {
                $comparable[] = $proposal;
            } else {
                $excluded[$proposal->proposalVersionId] = $reason;
            }
        }

        return new ProposalComparabilityPartition($comparable, $excluded);
    }

    private function exclusionReason(
        ComparableProposalVersion $selected,
        ComparableProposalVersion $candidate,
    ): ?string {
        if (! hash_equals($selected->materialSpecificationHash, $candidate->materialSpecificationHash)) {
            return 'specification_mismatch';
        }
        if (! hash_equals($selected->quantity, $candidate->quantity)) {
            return 'quantity_mismatch';
        }
        if ($selected->unit !== $candidate->unit) {
            return 'unit_mismatch';
        }
        if ($selected->unitDimension !== $candidate->unitDimension
            || $selected->conversionVersion !== $candidate->conversionVersion) {
            return 'unit_conversion_basis_mismatch';
        }
        if ($selected->vatBasis !== $candidate->vatBasis) {
            return 'vat_basis_mismatch';
        }
        if ($selected->freightBasis !== $candidate->freightBasis) {
            return 'freight_basis_mismatch';
        }
        if ($selected->currency !== $candidate->currency) {
            return 'currency_mismatch';
        }

        return null;
    }
}
