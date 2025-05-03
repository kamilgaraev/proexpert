<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Supplier\SupplierService;
use App\Http\Requests\Api\V1\Admin\Supplier\StoreSupplierRequest;
use App\Http\Requests\Api\V1\Admin\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Api\V1\Admin\SupplierResource;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    protected SupplierService $supplierService;

    public function __construct(SupplierService $supplierService)
    {
        $this->supplierService = $supplierService;
        // TODO: Добавить middleware для проверки прав ('can:manage_suppliers')
    }

    public function index(): JsonResponse
    {
        // TODO: Пагинация, фильтрация, API Resource
        $suppliers = $this->supplierService->getActiveSuppliersForCurrentOrg();
        return SupplierResource::collection($suppliers)->response();
    }

    public function store(StoreSupplierRequest $request): SupplierResource
    {
        $supplier = $this->supplierService->createSupplier($request->validated());
        return new SupplierResource($supplier);
    }

    public function show(string $id): SupplierResource | JsonResponse
    {
        $supplier = $this->supplierService->findSupplierById((int)$id);
        if (!$supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }
        // TODO: Проверка принадлежности (в сервисе)
        // TODO: API Resource
        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, string $id): SupplierResource | JsonResponse
    {
        $success = $this->supplierService->updateSupplier((int)$id, $request->validated());
        if (!$success) {
            return response()->json(['message' => 'Supplier not found or update failed'], 404);
        }
        $supplier = $this->supplierService->findSupplierById((int)$id);
        return new SupplierResource($supplier);
    }

    public function destroy(string $id): JsonResponse
    {
        $success = $this->supplierService->deleteSupplier((int)$id);
        if (!$success) {
             // TODO: Уточнить обработку ошибки (может нельзя удалить из-за связей)
            return response()->json(['message' => 'Supplier not found or delete failed'], 404);
        }
        return response()->json(null, 204);
    }
} 