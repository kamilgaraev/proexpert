<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use DomainException;

final class LegalWorkflowDocumentProjection
{
    /** @return array{approval_status: string, lifecycle_status: string} */
    public static function forInstanceStatus(string $status): array
    {
        return match ($status) {
            'in_progress' => ['approval_status' => 'pending', 'lifecycle_status' => 'under_review'],
            'approved' => ['approval_status' => 'approved', 'lifecycle_status' => 'approved'],
            'rejected' => ['approval_status' => 'rejected', 'lifecycle_status' => 'rejected'],
            'returned' => ['approval_status' => 'revision_required', 'lifecycle_status' => 'revision_required'],
            'cancelled' => ['approval_status' => 'cancelled', 'lifecycle_status' => 'terminated'],
            'expired' => ['approval_status' => 'expired', 'lifecycle_status' => 'expired'],
            default => throw new DomainException('legal_workflow_projection_status_invalid'),
        };
    }
}
