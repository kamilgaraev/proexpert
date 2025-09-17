<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Supplier\SupplierService;
use App\Http\Requests\Api\V1\Admin\Supplier\StoreSupplierRequest;
use App\Http\Requests\Api\V1\Admin\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Api\V1\Admin\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    protected SupplierService $supplierService;

    public function __construct(SupplierService $supplierService)
    {
        $this->supplierService = $supplierService;
        $this->middleware('can:admin.catalogs.manage');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->query('per_page', 15);
        $suppliers = $this->supplierService->getSuppliersPaginated($request, (int)$perPage);
        return SupplierResource::collection($suppliers);
    }

    public function store(StoreSupplierRequest $request): SupplierResource
    {
        $validatedData = $request->validated();
        Log::info('[Admin\SupplierController@store] Validated data for supplier creation:', $validatedData);

        $supplier = $this->supplierService->createSupplier($validatedData, $request);
        return new SupplierResource($supplier);
    }

    public function show(Request $request, string $id): SupplierResource | JsonResponse
    {
        $supplier = $this->supplierService->findSupplierById((int)$id, $request);
        if (!$supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }
        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, string $id): SupplierResource | JsonResponse
    {
        $success = $this->supplierService->updateSupplier((int)$id, $request->validated(), $request);
        if (!$success) {
            return response()->json(['message' => 'Supplier not found or update failed'], 404);
        }
        $supplier = $this->supplierService->findSupplierById((int)$id, $request);
        return new SupplierResource($supplier);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $success = $this->supplierService->deleteSupplier((int)$id, $request);
        if (!$success) {
            return response()->json(['message' => 'Supplier not found or delete failed'], 404);
        }
        return response()->json(null, 204);
    }
} 