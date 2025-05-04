<?php

namespace App\Services\Supplier;

use App\Repositories\Interfaces\SupplierRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class SupplierService
{
    protected SupplierRepositoryInterface $supplierRepository;

    public function __construct(SupplierRepositoryInterface $supplierRepository)
    {
        $this->supplierRepository = $supplierRepository;
    }

    /**
     * Helper для получения ID организации из запроса.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user(); 
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }
        if (!$organizationId) {
            Log::error('Failed to determine organization context in SupplierService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        return (int)$organizationId;
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

    /**
     * Получить пагинированный список поставщиков.
     */
    public function getSuppliersPaginated(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $filters = [
            'name' => $request->query('name'),
            'is_active' => $request->query('is_active'),
        ];
        if (isset($filters['is_active'])) {
            $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_active']); 
        }
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        $sortBy = $request->query('sort_by', 'name');
        $sortDirection = $request->query('sort_direction', 'asc');

        $allowedSortBy = ['name', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            $sortBy = 'name';
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'asc';
        }

        return $this->supplierRepository->getSuppliersForOrganizationPaginated(
            $organizationId,
            $perPage,
            $filters,
            $sortBy,
            $sortDirection
        );
    }

    public function createSupplier(array $data, Request $request)
    {
        $organizationId = $this->getCurrentOrgId($request);
        $data['organization_id'] = $organizationId;
        // TODO: Валидация
        return $this->supplierRepository->create($data);
    }

    public function findSupplierById(int $id, Request $request)
    {
        $organizationId = $this->getCurrentOrgId($request);
        $supplier = $this->supplierRepository->find($id);
        if (!$supplier || $supplier->organization_id !== $organizationId) {
            return null;
        }
        return $supplier;
    }

    public function updateSupplier(int $id, array $data, Request $request)
    {
        $supplier = $this->findSupplierById($id, $request);
        if (!$supplier) {
            throw new BusinessLogicException('Поставщик не найден или не принадлежит вашей организации.', 404);
        }
        unset($data['organization_id']);
        return $this->supplierRepository->update($id, $data);
    }

    public function deleteSupplier(int $id, Request $request)
    {
        $supplier = $this->findSupplierById($id, $request);
        if (!$supplier) {
            throw new BusinessLogicException('Поставщик не найден или не принадлежит вашей организации.', 404);
        }
        // TODO: Проверка использования
        return $this->supplierRepository->delete($id);
    }
} 