<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Contract\ContractAccessService;
use App\Services\LegalArchive\ContractLegalDocumentContext;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final class ContractLegalDocumentViewAccess
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractAccessService $contracts,
        private readonly UserProjectAccessService $projects,
        private readonly LegalDocumentAuthorizer $documents,
    ) {}

    public function authorize(User $actor, ContractLegalDocumentContext $context, int $projectId, string $ability = 'view'): void
    {
        $organizationId = (int) $actor->current_organization_id;
        $contract = $context->contract;
        $document = $context->document;

        if (
            $organizationId < 1
            || $projectId < 1
            || ! in_array($ability, ['view', 'download'], true)
            || (int) $contract->legal_archive_document_id !== (int) $document->id
            || (int) $contract->organization_id !== (int) $document->organization_id
            || (int) $document->primary_project_id !== $projectId
            || ! $this->authorization->can($actor, 'contracts.view', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
            ])
            || ! $this->projects->queryAccessibleProjects($actor, $organizationId)->whereKey($projectId)->exists()
            || ! $this->contracts->canAccess($contract, $organizationId, $projectId)
        ) {
            throw new AuthorizationException;
        }

        if (in_array((string) $document->confidentiality_level, ['restricted', 'secret'], true)) {
            $this->documents->authorize($actor, $document, $ability);
        }
    }
}
