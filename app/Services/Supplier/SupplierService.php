<?php

namespace App\Services\Supplier;

use App\Repositories\Interfaces\SupplierRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;

class SupplierService
{
    protected SupplierRepositoryInterface $supplierRepository;

    public function __construct(SupplierRepositoryInterface $supplierRepository)
    {
        $this->supplierRepository = $supplierRepository;
    }

    public function getAllSuppliersForCurrentOrg()
    {
        $organizationId = Auth::user()->getCurrentOrganizationId();
        // Используем findBy из BaseRepository
        return $this->supplierRepository->findBy('organization_id', $organizationId);
    }

    public function getActiveSuppliersForCurrentOrg()
    {
        $organizationId = Auth::user()->getCurrentOrganizationId();
        return $this->supplierRepository->getActiveSuppliers($organizationId);
    }

    public function getAllActive(): Collection
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $organizationId = $user->current_organization_id;
        return $this->supplierRepository->getActiveSuppliers($organizationId);
    }

    public function createSupplier(array $data)
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $data['organization_id'] = $user->current_organization_id;
        // TODO: Валидация
        return $this->supplierRepository->create($data);
    }

    public function findSupplierById(int $id)
    {
        // TODO: Проверка принадлежности организации
        return $this->supplierRepository->find($id);
    }

    public function updateSupplier(int $id, array $data)
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $organizationId = $user->current_organization_id;
        // Доп. проверка, что запись принадлежит организации пользователя
        $supplier = $this->supplierRepository->find($id);
        if (!$supplier || $supplier->organization_id !== $organizationId) {
            throw new BusinessLogicException('Поставщик не найден или не принадлежит вашей организации.', 404);
        }
        // TODO: Валидация
        return $this->supplierRepository->update($id, $data);
    }

    public function deleteSupplier(int $id)
    {
        // TODO: Проверка принадлежности организации
        // TODO: Проверка использования
        return $this->supplierRepository->delete($id);
    }
} 