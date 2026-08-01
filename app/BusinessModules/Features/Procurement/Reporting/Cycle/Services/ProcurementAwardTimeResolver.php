<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

final class ProcurementAwardTimeResolver
{
    public function resolve(
        SupplierProposalDecisionEnum $status,
        ?DateTimeInterface $selectedAt,
        ?DateTimeInterface $finalApprovalResolvedAt,
    ): DateTimeImmutable {
        $occurredAt = match ($status) {
            SupplierProposalDecisionEnum::SELECTED => $selectedAt,
            SupplierProposalDecisionEnum::APPROVED => $finalApprovalResolvedAt,
            default => null,
        };
        if (! $occurredAt instanceof DateTimeInterface) {
            throw new LogicException('procurement_award_exact_time_required');
        }

        return DateTimeImmutable::createFromInterface($occurredAt);
    }
}
