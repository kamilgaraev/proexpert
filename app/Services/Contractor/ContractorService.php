<?php

namespace App\Services\Contractor;

use App\Repositories\Interfaces\ContractorRepositoryInterface;
use App\DTOs\Contractor\ContractorDTO;
use App\Models\Contractor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Exception;

class ContractorService
{
    protected ContractorRepositoryInterface $contractorRepository;

    public function __construct(ContractorRepositoryInterface $contractorRepository)
    {
        $this->contractorRepository = $contractorRepository;
    }

    public function getAllContractors(int $organizationId, int $perPage = 15, array $filters = [], string $sortBy = 'name', string $sortDirection = 'asc'): LengthAwarePaginator
    {
        // Базовый репозиторий find/getAll может не поддерживать фильтрацию по organization_id напрямую так гибко.
        // Либо расширяем BaseRepository/ContractorRepository, либо фильтруем здесь или в ContractorRepository.
        // Пока предполагаем, что getContractorsForOrganization будет в ContractorRepositoryInterface/ContractorRepository.
        // Если его нет, нужно будет его добавить или использовать $this->contractorRepository->getAll() и фильтровать.
        // Для примера, если бы getAll принимал where условия:
        // $conditions = array_merge($filters, ['organization_id' => $organizationId]);
        // return $this->contractorRepository->getAllPaginated($conditions, $perPage, $sortBy, $sortDirection, ['contracts']);
        
        // Предположим, что в ContractorRepositoryInterface мы добавим такой метод:
        if (method_exists($this->contractorRepository, 'getContractorsForOrganization')) {
             return $this->contractorRepository->getContractorsForOrganization($organizationId, $perPage, $filters, $sortBy, $sortDirection);
        } else {
            // Базовая реализация, если специфичного метода нет (менее эффективно для БД)
            // Это потребует, чтобы getAllPaginated был в BaseRepositoryInterface
            $queryFilters = array_merge($filters, [['organization_id', '=', $organizationId]]);
            return $this->contractorRepository->getAllPaginated($queryFilters, $perPage, $sortBy, $sortDirection);
        }
    }

    public function createContractor(int $organizationId, ContractorDTO $contractorDTO): Contractor
    {
        $contractorData = $contractorDTO->toArray();
        $contractorData['organization_id'] = $organizationId;
        
        // Проверка на уникальность ИНН/email в пределах организации
        if ($contractorDTO->inn && $this->contractorRepository->findByFilters(['inn' => $contractorDTO->inn, 'organization_id' => $organizationId])) {
            throw new Exception('Contractor with this INN already exists in the organization.');
        }
        if ($contractorDTO->email && $this->contractorRepository->findByFilters(['email' => $contractorDTO->email, 'organization_id' => $organizationId])) {
            throw new Exception('Contractor with this email already exists in the organization.');
        }

        return $this->contractorRepository->create($contractorData);
    }

    public function getContractorById(int $contractorId, int $organizationId): ?Contractor
    {
        $contractor = $this->contractorRepository->find($contractorId);
        if ($contractor && $contractor->organization_id === $organizationId) {
            return $contractor->load('contracts'); // Пример загрузки связей
        }
        return null;
    }

    public function updateContractor(int $contractorId, int $organizationId, ContractorDTO $contractorDTO): Contractor
    {
        $contractor = $this->getContractorById($contractorId, $organizationId);
        if (!$contractor) {
            throw new Exception('Contractor not found.');
        }

        $updateData = $contractorDTO->toArray();
        
        // Проверка на уникальность ИНН/email при изменении, исключая текущего подрядчика
        if ($contractorDTO->inn && 
            $this->contractorRepository->findByFilters(['inn' => $contractorDTO->inn, 'organization_id' => $organizationId, ['id', '!=', $contractorId]])
           ) {
            throw new Exception('Another contractor with this INN already exists in the organization.');
        }
        if ($contractorDTO->email && 
            $this->contractorRepository->findByFilters(['email' => $contractorDTO->email, 'organization_id' => $organizationId, ['id', '!=', $contractorId]])
           ) {
            throw new Exception('Another contractor with this email already exists in the organization.');
        }

        $updated = $this->contractorRepository->update($contractorId, $updateData);
        if (!$updated) {
            throw new Exception('Failed to update contractor.');
        }
        return $this->getContractorById($contractorId, $organizationId);
    }

    public function deleteContractor(int $contractorId, int $organizationId): bool
    {
        $contractor = $this->getContractorById($contractorId, $organizationId);
        if (!$contractor) {
            throw new Exception('Contractor not found.');
        }
        // Добавить проверку, если есть связанные активные контракты - не удалять или удалять с осторожностью
        if ($contractor->contracts()->whereNotIn('status', [/* 'completed', 'terminated' */])->exists()) {
             throw new Exception('Cannot delete contractor with active contracts.');
        }
        return $this->contractorRepository->delete($contractorId);
    }
} 