<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use DomainException;

final class LegalWorkflowPermissions
{
    public const VIEW = 'legal_archive.workflow.view';

    public const SUBMIT = 'legal_archive.workflow.submit';

    public const APPROVE = 'legal_archive.workflow.approve';

    public const REJECT = 'legal_archive.workflow.reject';

    public const RETURN = 'legal_archive.workflow.return';

    public const REASSIGN = 'legal_archive.workflow.reassign';

    public const CANCEL = 'legal_archive.workflow.cancel';

    public const MANAGE_TEMPLATES = 'legal_archive.workflow_templates.manage';

    public static function forAction(string $action): string
    {
        return match ($action) {
            'submit' => self::SUBMIT,
            'approve' => self::APPROVE,
            'reject' => self::REJECT,
            'return' => self::RETURN,
            'reassign' => self::REASSIGN,
            'cancel' => self::CANCEL,
            default => throw new DomainException('legal_workflow_action_invalid'),
        };
    }
}
