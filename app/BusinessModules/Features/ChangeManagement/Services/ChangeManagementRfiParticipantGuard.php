<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Services;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use DomainException;

final class ChangeManagementRfiParticipantGuard
{
    public function assertCanDeactivate(int $projectId, int $organizationId): void
    {
        $hasOpenRfi = ChangeManagementRfi::query()
            ->where('project_id', $projectId)
            ->where(function ($query) use ($organizationId): void {
                $query->where('recipient_organization_id', $organizationId)
                    ->orWhere('organization_id', $organizationId);
            })
            ->whereIn('status', ['sent', 'answered', 'accepted', 'clarification_requested', 'overdue'])
            ->exists();

        if ($hasOpenRfi) {
            throw new DomainException(trans_message('change_management.errors.rfi_reassign_before_deactivation'));
        }
    }
}
