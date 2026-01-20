<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
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

    public function index(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $suppliers = $this->supplierService->getSuppliersPaginated($request, (int)$perPage);
            return SupplierResource::collection($suppliers);
        } catch (\Throwable $e) {
            Log::error('SupplierController@index Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('supplier.internal_error_list'), 500);
        }
    }

    public function store(StoreSupplierRequest $request): SupplierResource | JsonResponse
    {
        try {
            $validatedData = $request->validated();
            Log::info('SupplierController@store Creating supplier', [
                'user_id' => $request->user()?->id,
            ]);

            $supplier = $this->supplierService->createSupplier($validatedData, $request);
            return new SupplierResource($supplier);
        } catch (\Throwable $e) {
            Log::error('SupplierController@store Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('supplier.internal_error_create'), 500);
        }
    }

    public function show(Request $request, string $id): SupplierResource | JsonResponse
    {
        try {
            $supplier = $this->supplierService->findSupplierById((int)$id, $request);
            if (!$supplier) {
                return AdminResponse::error(trans_message('supplier.not_found'), 404);
            }
            return new SupplierResource($supplier);
        } catch (\Throwable $e) {
            Log::error('SupplierController@show Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('supplier.internal_error_get'), 500);
        }
    }

    public function update(UpdateSupplierRequest $request, string $id): SupplierResource | JsonResponse
    {
        try {
            $success = $this->supplierService->updateSupplier((int)$id, $request->validated(), $request);
            if (!$success) {
                return AdminResponse::error(trans_message('supplier.update_failed'), 404);
            }
            $supplier = $this->supplierService->findSupplierById((int)$id, $request);
            return new SupplierResource($supplier);
        } catch (\Throwable $e) {
            Log::error('SupplierController@update Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('supplier.internal_error_update'), 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $success = $this->supplierService->deleteSupplier((int)$id, $request);
            if (!$success) {
                return AdminResponse::error(trans_message('supplier.delete_failed'), 404);
            }
            return AdminResponse::success(null, trans_message('supplier.deleted'));
        } catch (\Throwable $e) {
            Log::error('SupplierController@destroy Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('supplier.internal_error_delete'), 500);
        }
    }
} 