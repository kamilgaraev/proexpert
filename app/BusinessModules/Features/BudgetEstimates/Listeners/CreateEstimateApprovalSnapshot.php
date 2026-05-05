<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Listeners;

use App\BusinessModules\Features\BudgetEstimates\Events\EstimateApproved;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;

class CreateEstimateApprovalSnapshot
{
    public function __construct(
        private readonly EstimateVersioningService $versioningService,
    ) {
    }

    public function handle(EstimateApproved $event): void
    {
        $actorId = $event->estimate->approved_by_user_id;

        if ($actorId === null) {
            return;
        }

        $this->versioningService->createApprovalSnapshot($event->estimate, (int) $actorId);
    }
}
