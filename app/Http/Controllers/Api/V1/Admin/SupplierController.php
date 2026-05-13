<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Supplier\StoreSupplierRequest;
use App\Http\Requests\Api\V1\Admin\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Api\V1\Admin\SupplierResource;
use App\Http\Responses\AdminResponse;
use App\Services\Supplier\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupplierController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;
    private const LOAD_ALL_PER_PAGE = 1000;
    private const MAX_PER_PAGE = 1000;

    public function __construct(private readonly SupplierService $supplierService)
    {
        $this->middleware('can:admin.catalogs.manage');
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $suppliers = $this->supplierService->getSuppliersPaginated(
                $request,
                $this->normalizePerPage($request->query('per_page'))
            );

            return AdminResponse::paginated(
                SupplierResource::collection($suppliers->getCollection()),
                [
                    'current_page' => $suppliers->currentPage(),
                    'last_page' => $suppliers->lastPage(),
                    'per_page' => $suppliers->perPage(),
                    'total' => $suppliers->total(),
                ],
                null,
                Response::HTTP_OK,
                null,
                [
                    'first' => $suppliers->url(1),
                    'last' => $suppliers->url($suppliers->lastPage()),
                    'prev' => $suppliers->previousPageUrl(),
                    'next' => $suppliers->nextPageUrl(),
                ]
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'supplier_index_failed', $exception);

            return AdminResponse::error(trans_message('supplier.internal_error_list'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        try {
            $supplier = $this->supplierService->createSupplier($request->validated(), $request);

            return AdminResponse::success(new SupplierResource($supplier), null, Response::HTTP_CREATED);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'supplier_create_failed', $exception);

            return AdminResponse::error(trans_message('supplier.internal_error_create'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $supplier = $this->supplierService->findSupplierById((int) $id, $request);

            if (!$supplier) {
                return AdminResponse::error(trans_message('supplier.not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(new SupplierResource($supplier));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'supplier_show_failed', $exception);

            return AdminResponse::error(trans_message('supplier.internal_error_get'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateSupplierRequest $request, string $id): JsonResponse
    {
        try {
            $this->supplierService->updateSupplier((int) $id, $request->validated(), $request);
            $supplier = $this->supplierService->findSupplierById((int) $id, $request);

            return AdminResponse::success(new SupplierResource($supplier));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'supplier_update_failed', $exception);

            return AdminResponse::error(trans_message('supplier.internal_error_update'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->supplierService->deleteSupplier((int) $id, $request);

            return AdminResponse::success(null, trans_message('supplier.deleted'));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'supplier_delete_failed', $exception);

            return AdminResponse::error(trans_message('supplier.internal_error_delete'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function normalizePerPage(mixed $perPage): int
    {
        $perPage = filter_var($perPage, FILTER_VALIDATE_INT);

        if ($perPage === false) {
            return self::DEFAULT_PER_PAGE;
        }

        if ($perPage <= 0) {
            return self::LOAD_ALL_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    private function logUnexpectedException(Request $request, string $message, Throwable $exception): void
    {
        Log::error($message, [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'params' => $request->all(),
            'exception' => $exception,
        ]);
    }
}
