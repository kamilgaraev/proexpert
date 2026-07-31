<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose;
use DateTimeImmutable;
use DomainException;

final class BudgetingReportSourceCloseService
{
    public function __construct(private readonly BudgetingReportSourceCloseStore $store)
    {
    }

    public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
    {
        return $this->store->createApproved($request);
    }

    public function validatedCloseForReporting(
        string $closeId,
        BudgetingReportSourceCloseIdentity $identity,
        DateTimeImmutable $at,
    ): BudgetingReportSourceClose {
        $close = $this->store->find($closeId);

        if (!$close instanceof BudgetingReportSourceClose || $close->identity->toArray() !== $identity->toArray()) {
            throw new DomainException('budgeting_report_source_close_not_found');
        }

        if (!$close->isAvailableAt($at)) {
            throw new DomainException('budgeting_report_source_close_not_available');
        }

        return $close;
    }
}
