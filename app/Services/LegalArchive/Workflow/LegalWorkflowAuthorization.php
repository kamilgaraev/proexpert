<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use DomainException;

final class LegalWorkflowAuthorization
{
    public function can(User $actor, LegalArchiveDocument $document, string $permission): bool
    {
        $organizationId = (int) $document->organization_id;
        if (
            $organizationId < 1
            || (string) $document->getKey() === ''
            || (int) $actor->current_organization_id !== $organizationId
        ) {
            return false;
        }

        $context = ['organization_id' => $organizationId];
        if ($document->primary_project_id !== null) {
            $context['project_id'] = (int) $document->primary_project_id;
        }

        return $actor->hasPermission($permission, $context);
    }

    public function assertCan(User $actor, LegalArchiveDocument $document, string $permission): void
    {
        if (! $this->can($actor, $document, $permission)) {
            throw new DomainException('legal_workflow_access_denied');
        }
    }
}
