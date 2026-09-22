<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Models\User;

trait SubmitsWorkVolumeStatements
{
    private function approveReviewed(WorkVolumeStatementService $service, User $actor, WorkVolumeStatement $statement): WorkVolumeStatement
    {
        if ($statement->fresh()->status === WorkVolumeStatement::STATUS_DRAFT) {
            $statement = $service->submitForReview($actor, $statement, $statement->fresh()->review_round);
        }
        return $service->approve($actor, $statement, $statement->fresh()->review_round);
    }
}
