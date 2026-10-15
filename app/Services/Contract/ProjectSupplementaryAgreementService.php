<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\DTOs\SupplementaryAgreementDTO;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Project;
use App\Models\SupplementaryAgreement;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use function trans_message;

class ProjectSupplementaryAgreementService
{
    public function __construct(
        private readonly SupplementaryAgreementService $agreements,
        private readonly ContractService $contracts,
        private readonly UserProjectAccessService $projectAccess,
        private readonly AuthorizationService $authorization,
    ) {}

    public function paginate(User $actor, int $organizationId, int $projectId,
        int $perPage, array $filters, string $sortBy, string $sortDirection): LengthAwarePaginator
    {
        $this->authorize($actor, $organizationId, $projectId, 'agreements.view');
        return $this->agreements->paginateByProject($projectId, $organizationId, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function create(User $actor, int $organizationId, int $projectId, SupplementaryAgreementDTO $dto): SupplementaryAgreement
    {
        $this->authorize($actor, $organizationId, $projectId, 'agreements.create');
        $this->contract($dto->contract_id, $organizationId, $projectId);

        return $this->agreements->create($dto);
    }

    public function find(User $actor, int $organizationId, int $projectId, int $agreementId): SupplementaryAgreement
    {
        $this->authorize($actor, $organizationId, $projectId, 'agreements.view');

        return $this->agreement($agreementId, $organizationId, $projectId);
    }

    public function findForUpdate(User $actor, int $organizationId, int $projectId, int $agreementId): SupplementaryAgreement
    {
        $this->authorize($actor, $organizationId, $projectId, 'agreements.edit');

        return $this->agreement($agreementId, $organizationId, $projectId);
    }

    public function update(User $actor, int $organizationId, int $projectId, int $agreementId, SupplementaryAgreementDTO $dto): ?SupplementaryAgreement
    {
        $agreement = $this->findForUpdate($actor, $organizationId, $projectId, $agreementId);
        if ((int) $agreement->contract_id !== $dto->contract_id) {
            throw (new ModelNotFoundException)->setModel(Contract::class, [$dto->contract_id]);
        }

        return $this->agreements->update($agreementId, $dto) ? $this->agreements->getById($agreementId) : null;
    }

    public function delete(User $actor, int $organizationId, int $projectId, int $agreementId): bool
    {
        $this->authorize($actor, $organizationId, $projectId, 'agreements.delete');
        $this->agreement($agreementId, $organizationId, $projectId);

        return $this->agreements->delete($agreementId);
    }

    public function applyChangesToContract(User $actor, int $organizationId, int $projectId, int $agreementId): void
    {
        $agreement = $this->findForUpdate($actor, $organizationId, $projectId, $agreementId);
        $this->agreements->applyOnce($agreement, (int) $actor->id);
    }

    private function authorize(User $actor, int $organizationId, int $projectId, string $permission): void
    {
        $project = Project::query()->useWritePdo()->find($projectId);
        if ($organizationId <= 0 || (int) $actor->current_organization_id !== $organizationId
            || ! $project instanceof Project || ! $this->projectAccess->canAccessCurrentOrganizationProject($actor, $project)) {
            throw new AuthorizationException(trans_message('agreements.access_denied'));
        }

        AuthorizationContext::getProjectContext($projectId, $organizationId);
        if (! $this->authorization->canCurrent($actor, $permission, [
            'organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true,
        ])) {
            throw new AuthorizationException(trans_message('agreements.access_denied'));
        }
    }

    private function agreement(int $agreementId, int $organizationId, int $projectId): SupplementaryAgreement
    {
        $agreement = $this->agreements->getById($agreementId);
        if (! $agreement instanceof SupplementaryAgreement) {
            throw (new ModelNotFoundException)->setModel(SupplementaryAgreement::class, [$agreementId]);
        }
        $this->contract((int) $agreement->contract_id, $organizationId, $projectId);

        return $agreement;
    }

    private function contract(int $contractId, int $organizationId, int $projectId): Contract
    {
        $contract = $this->contracts->getContractById($contractId, $organizationId, $projectId);
        if (! $contract instanceof Contract) {
            throw (new ModelNotFoundException)->setModel(Contract::class, [$contractId]);
        }

        return $contract;
    }
}
