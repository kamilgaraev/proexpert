<?php

declare(strict_types=1);

namespace App\Services\Contractor;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\DTOs\Contractor\ContractorDTO;
use App\Exceptions\BusinessLogicException;
use App\Models\Contractor;
use App\Repositories\Interfaces\ContractorRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use RuntimeException;

class ContractorService
{
    private const FINISHED_CONTRACT_STATUSES = ['completed', 'terminated', 'cancelled'];

    public function __construct(
        protected ContractorRepositoryInterface $contractorRepository,
        protected ContractorSharingInterface $contractorSharing
    ) {}

    public function getAllContractors(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator {
        return $this->contractorRepository->getContractorsForOrganization(
            $organizationId,
            $perPage,
            $filters,
            $sortBy,
            $sortDirection
        );
    }

    public function createContractor(int $organizationId, ContractorDTO $contractorDTO): Contractor
    {
        $this->ensureContactsAreUnique($organizationId, $contractorDTO);

        $contractorData = $contractorDTO->toArray();
        $contractorData['organization_id'] = $organizationId;

        return $this->contractorRepository->create($contractorData);
    }

    public function getContractorById(int $contractorId, int $organizationId): ?Contractor
    {
        $contractor = $this->contractorRepository->find($contractorId);

        if (!$contractor || (int) $contractor->organization_id !== $organizationId) {
            return null;
        }

        return $contractor->load('contracts');
    }

    public function updateContractor(int $contractorId, int $organizationId, ContractorDTO $contractorDTO): Contractor
    {
        $contractor = $this->getContractorById($contractorId, $organizationId);

        if (!$contractor) {
            throw new BusinessLogicException(
                trans_message('contract.contractor_not_found'),
                Response::HTTP_NOT_FOUND
            );
        }

        $this->ensureContactsAreUnique($organizationId, $contractorDTO, $contractorId);

        if (!$this->contractorRepository->update($contractorId, $contractorDTO->toArray())) {
            throw new RuntimeException('Contractor update failed.');
        }

        $updatedContractor = $this->getContractorById($contractorId, $organizationId);

        if (!$updatedContractor) {
            throw new BusinessLogicException(
                trans_message('contract.contractor_not_found'),
                Response::HTTP_NOT_FOUND
            );
        }

        return $updatedContractor;
    }

    public function deleteContractor(int $contractorId, int $organizationId): bool
    {
        $contractor = $this->getContractorById($contractorId, $organizationId);

        if (!$contractor) {
            throw new BusinessLogicException(
                trans_message('contract.contractor_not_found'),
                Response::HTTP_NOT_FOUND
            );
        }

        if ($contractor->contracts()->whereNotIn('status', self::FINISHED_CONTRACT_STATUSES)->exists()) {
            throw new BusinessLogicException(
                trans_message('contract.contractor_has_active_contracts'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->contractorRepository->delete($contractorId);
    }

    public function getAvailableContractors(int $organizationId): Collection
    {
        return $this->contractorSharing->getAvailableContractors($organizationId);
    }

    public function canUseContractor(int $contractorId, int $organizationId): bool
    {
        return $this->contractorSharing->canUseContractor($contractorId, $organizationId);
    }

    private function ensureContactsAreUnique(
        int $organizationId,
        ContractorDTO $contractorDTO,
        ?int $ignoredContractorId = null
    ): void {
        if ($contractorDTO->inn !== null && $contractorDTO->inn !== '') {
            $this->ensureFieldIsUnique(
                $organizationId,
                'inn',
                $contractorDTO->inn,
                trans_message('contract.contractor_duplicate_inn'),
                $ignoredContractorId
            );
        }

        if ($contractorDTO->email !== null && $contractorDTO->email !== '') {
            $this->ensureFieldIsUnique(
                $organizationId,
                'email',
                $contractorDTO->email,
                trans_message('contract.contractor_duplicate_email'),
                $ignoredContractorId
            );
        }
    }

    private function ensureFieldIsUnique(
        int $organizationId,
        string $field,
        string $value,
        string $message,
        ?int $ignoredContractorId
    ): void {
        $conditions = [
            [$field, '=', $value],
            ['organization_id', '=', $organizationId],
        ];

        if ($ignoredContractorId !== null) {
            $conditions[] = ['id', '!=', $ignoredContractorId];
        }

        if ($this->contractorRepository->getAllPaginated($conditions, 1, 'id', 'asc')->isNotEmpty()) {
            throw new BusinessLogicException($message, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
