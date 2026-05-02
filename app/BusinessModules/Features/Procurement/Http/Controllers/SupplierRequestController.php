<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Requests\StoreSupplierRequestRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\SupplierRequestResource;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\SupplierRequestService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use function trans_message;

class SupplierRequestController extends Controller
{
    public function __construct(
        private readonly SupplierRequestService $service
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $perPage = min((int) $request->input('per_page', 15), 100);
            $query = $this->service->queryForOrganization($organizationId);

            if ($request->filled('status')) {
                $query->withStatus((string) $request->input('status'));
            }

            if ($request->filled('purchase_request_id')) {
                $query->where('purchase_request_id', (int) $request->input('purchase_request_id'));
            }

            $supplierRequests = $query->paginate($perPage);

            return AdminResponse::paginated(
                SupplierRequestResource::collection($supplierRequests->getCollection()),
                [
                    'current_page' => $supplierRequests->currentPage(),
                    'per_page' => $supplierRequests->perPage(),
                    'total' => $supplierRequests->total(),
                    'last_page' => $supplierRequests->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('procurement.supplier_requests.index.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.supplier_requests.index_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $supplierRequest = SupplierRequest::query()
                ->forOrganization($organizationId)
                ->with([
                    'supplier',
                    'externalSupplierContact',
                    'supplierParty',
                    'purchaseRequest',
                    'lines',
                    'currentVersion',
                ])
                ->find($id);

            if (!$supplierRequest) {
                return AdminResponse::error(trans_message('procurement.supplier_requests.not_found'), 404);
            }

            return AdminResponse::success(new SupplierRequestResource($supplierRequest));
        } catch (\Exception $e) {
            Log::error('procurement.supplier_requests.show.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.supplier_requests.show_error'), 500);
        }
    }

    public function store(StoreSupplierRequestRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $supplierRequest = $this->service->create($organizationId, $request->validated(), $request->user()?->id);

            return AdminResponse::success(
                new SupplierRequestResource($supplierRequest),
                trans_message('procurement.supplier_requests.created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('procurement.supplier_requests.store.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.supplier_requests.store_error'), 500);
        }
    }

    public function send(Request $request, int $id): JsonResponse
    {
        try {
            $supplierRequest = $this->findForOrganization($request, $id);

            if (!$supplierRequest) {
                return AdminResponse::error(trans_message('procurement.supplier_requests.not_found'), 404);
            }

            return AdminResponse::success(
                new SupplierRequestResource($this->service->send($supplierRequest, $request->user()?->id)),
                trans_message('procurement.supplier_requests.sent')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('procurement.supplier_requests.send.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.supplier_requests.send_error'), 500);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $supplierRequest = $this->findForOrganization($request, $id);

            if (!$supplierRequest) {
                return AdminResponse::error(trans_message('procurement.supplier_requests.not_found'), 404);
            }

            return AdminResponse::success(
                new SupplierRequestResource($this->service->cancel($supplierRequest, $request->user()?->id)),
                trans_message('procurement.supplier_requests.cancelled')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('procurement.supplier_requests.cancel.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.supplier_requests.cancel_error'), 500);
        }
    }

    private function findForOrganization(Request $request, int $id): ?SupplierRequest
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');

        return SupplierRequest::query()
            ->forOrganization($organizationId)
            ->with(['supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest', 'lines', 'currentVersion'])
            ->find($id);
    }
}
