<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\ContractLegalDocumentContext;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

class ContractDossierReplayAccess
{
    public function __construct(
        private readonly LegalDocumentAuthorizer $documents,
        private readonly ContractLegalDocumentViewAccess $contracts,
        private readonly UserProjectAccessService $projects,
    ) {}

    public function authorizeContract(User $actor, int $organizationId, int $projectId, Contract $contract, LegalArchiveDocument $document): void
    {
        $actor = $this->authorizedActor($actor, $organizationId, $contract, $document, 'contracts.view');
        $this->contracts->authorize($actor, new ContractLegalDocumentContext($contract, $document), $projectId);
    }

    public function authorizeProcurement(User $actor, int $organizationId, Contract $contract, LegalArchiveDocument $document): void
    {
        $this->authorizedActor($actor, $organizationId, $contract, $document, 'procurement.contracts.view');
    }

    private function authorizedActor(User $actor, int $organizationId, Contract $contract, LegalArchiveDocument $document, string $permission): User
    {
        $currentActor = User::query()->find($actor->id);
        if (
            ! $currentActor instanceof User
            || ! $currentActor->is_active
            || $organizationId < 1
            || ! Organization::query()->whereKey($organizationId)->where('is_active', true)->exists()
            || (int) $contract->organization_id !== $organizationId
            || (int) $document->organization_id !== $organizationId
            || (int) $document->id < 1
            || (int) $contract->legal_archive_document_id !== (int) $document->id
        ) {
            throw new AuthorizationException;
        }
        $currentActor->setAttribute('current_organization_id', $organizationId);
        if ($document->primary_project_id !== null) {
            $project = $this->projects->queryAccessibleProjects($currentActor, $organizationId)
                ->whereKey((int) $document->primary_project_id)->first();
            if ($project === null) {
                throw new AuthorizationException;
            }
            AuthorizationContext::getProjectContext((int) $project->id, $organizationId);
        }
        $this->documents->authorizePermission($currentActor, $document, $permission);
        if (in_array((string) $document->confidentiality_level, ['restricted', 'secret'], true)) {
            $this->documents->authorizePermission($currentActor, $document, 'legal_archive.view');
        }

        return $currentActor;
    }
}
