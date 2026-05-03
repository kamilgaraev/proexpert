<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Exceptions\BusinessLogicException;
use App\Models\Supplier;
use App\Repositories\Interfaces\SupplierRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use function trans_message;

class SupplierService
{
    protected SupplierRepositoryInterface $supplierRepository;

    public function __construct(SupplierRepositoryInterface $supplierRepository)
    {
        $this->supplierRepository = $supplierRepository;
    }

    protected function getCurrentOrgId(Request $request): int
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }

        if (!$organizationId) {
            Log::error('Failed to determine organization context in SupplierService', [
                'user_id' => $user?->id,
                'request_attributes' => $request->attributes->all(),
            ]);

            throw new BusinessLogicException(trans_message('catalog.errors.organization_context_missing'), 500);
        }

        return (int) $organizationId;
    }

    public function getAllSuppliersForCurrentOrg()
    {
        $organizationId = Auth::user()->getCurrentOrganizationId();

        return $this->supplierRepository->findBy('organization_id', $organizationId);
    }

    public function getActiveSuppliersForCurrentOrg(Request $request): Collection
    {
        $organizationId = $this->getCurrentOrgId($request);

        return $this->supplierRepository->getActiveSuppliers($organizationId);
    }

    public function getAllActive(): Collection
    {
        $user = Auth::user();

        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException(trans_message('catalog.errors.organization_context_missing'));
        }

        return $this->supplierRepository->getActiveSuppliers($user->current_organization_id);
    }

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

        $filters = array_filter($filters, static fn ($value) => $value !== null && $value !== '');

        $sortBy = $request->query('sort_by', 'name');
        $sortDirection = $request->query('sort_direction', 'asc');

        $allowedSortBy = ['name', 'created_at', 'updated_at'];
        if (!in_array(strtolower((string) $sortBy), $allowedSortBy, true)) {
            $sortBy = 'name';
        }

        if (!in_array(strtolower((string) $sortDirection), ['asc', 'desc'], true)) {
            $sortDirection = 'asc';
        }

        return $this->supplierRepository->getSuppliersForOrganizationPaginated(
            $organizationId,
            $perPage,
            $filters,
            (string) $sortBy,
            (string) $sortDirection
        );
    }

    public function createSupplier(array $data, Request $request)
    {
        $organizationId = $this->getCurrentOrgId($request);
        $data['organization_id'] = $organizationId;

        return $this->supplierRepository->create($data);
    }

    public function findSupplierById(int $id, Request $request): ?Supplier
    {
        $organizationId = $this->getCurrentOrgId($request);
        $supplier = $this->supplierRepository->find($id);

        if (!$supplier || $supplier->organization_id !== $organizationId) {
            return null;
        }

        return $supplier;
    }

    public function updateSupplier(int $id, array $data, Request $request): bool
    {
        $supplier = $this->findSupplierById($id, $request);

        if (!$supplier) {
            throw new BusinessLogicException(trans_message('catalog.errors.supplier_not_found'), 404);
        }

        unset($data['organization_id']);

        return $this->supplierRepository->update($id, $data);
    }

    public function deleteSupplier(int $id, Request $request): bool
    {
        $supplier = $this->findSupplierById($id, $request);

        if (!$supplier) {
            throw new BusinessLogicException(trans_message('catalog.errors.supplier_not_found'), 404);
        }

        if ($this->hasSupplierUsage($supplier)) {
            throw new BusinessLogicException(trans_message('catalog.errors.supplier_in_use'), 422);
        }

        return $this->supplierRepository->delete($id);
    }

    protected function hasSupplierUsage(Supplier $supplier): bool
    {
        $supplierId = (int) $supplier->id;
        $organizationId = (int) $supplier->organization_id;

        foreach ($this->supplierUsageChecks() as $check) {
            if ($this->relatedRowExists($check['table'], $organizationId, $check['column'], $supplierId)) {
                return true;
            }
        }

        return false;
    }

    private function supplierUsageChecks(): array
    {
        return [
            ['table' => 'contracts', 'column' => 'supplier_id'],
            ['table' => 'purchase_orders', 'column' => 'supplier_id'],
            ['table' => 'supplier_requests', 'column' => 'supplier_id'],
            ['table' => 'supplier_proposals', 'column' => 'supplier_id'],
            ['table' => 'supplier_parties', 'column' => 'registered_supplier_id'],
            ['table' => 'auto_reorder_rules', 'column' => 'default_supplier_id'],
            ['table' => 'material_usage_logs', 'column' => 'supplier_id'],
        ];
    }

    private function relatedRowExists(string $table, int $organizationId, string $column, int $id): bool
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return false;
        }

        $query = DB::table($table)->where($column, $id);

        if (Schema::hasColumn($table, 'organization_id')) {
            $query->where('organization_id', $organizationId);
        }

        return $query->exists();
    }
}
