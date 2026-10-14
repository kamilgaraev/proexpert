<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services;

use DomainException;

final class RequestWorkflow
{
    public static function next(string $status, string $action): string
    {
        if ($action === 'comment' && in_array($status, ['submitted', 'awaiting_hr'], true)) {
            return $status;
        }

        return match ($status.':'.$action) {
            'submitted:approve' => 'awaiting_hr',
            'submitted:reject', 'awaiting_hr:hr_reject' => 'rejected',
            'awaiting_hr:hr_approve' => 'fulfilled',
            default => throw new DomainException(trans_message('workforce.errors.request_transition_invalid')),
        };
    }
}
