<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Requests\StorePurchaseRequestRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseOrderResource;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseRequestResource;
use App\BusinessModules\Features\Procurement\Services\PurchaseRequestService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use function trans_message;

class PurchaseRequestController extends Controller
{
    public function __construct(
        private readonly PurchaseRequestService $service
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $perPage = min((int) $request->input('per_page', 15), 100);
            $filters = $request->only([
                'status',
                'site_request_id',
                'assigned_to',
                'sort_by',
                'sort_dir',
            ]);

            $requests = $this->service->paginate($organizationId, $perPage, $filters);

            return AdminResponse::paginated(
                PurchaseRequestResource::collection($requests->getCollection()),
                [
                    'current_page' => $requests->currentPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                    'last_page' => $requests->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('procurement.purchase_requests.index.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_requests.index_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $purchaseRequest = $this->service->find($id, $organizationId);

            if (!$purchaseRequest) {
                return AdminResponse::error(trans_message('procurement.purchase_requests.not_found'), 404);
            }

            return AdminResponse::success(new PurchaseRequestResource($purchaseRequest));
        } catch (\Exception $e) {
            Log::error('procurement.purchase_requests.show.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_requests.show_error'), 500);
        }
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $purchaseRequest = $this->service->create($organizationId, $request->validated());

            return AdminResponse::success(
                new PurchaseRequestResource($purchaseRequest),
                trans_message('procurement.purchase_requests.created'),
                201
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_requests.store.error', [
                'user_id' => auth()->id(),
                'payload' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_requests.store_error'), 500);
        }
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) auth()->id();
            $purchaseRequest = $this->service->find($id, $organizationId);

            if (!$purchaseRequest) {
                return AdminResponse::error(trans_message('procurement.purchase_requests.not_found'), 404);
            }

            $approved = $this->service->approve($purchaseRequest, $userId);

            return AdminResponse::success(
                new PurchaseRequestResource($approved),
                trans_message('procurement.purchase_requests.approved')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_requests.approve.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_requests.approve_error'), 500);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) auth()->id();
            $purchaseRequest = $this->service->find($id, $organizationId);

            if (!$purchaseRequest) {
                return AdminResponse::error(trans_message('procurement.purchase_requests.not_found'), 404);
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:1000'],
            ]);

            $rejected = $this->service->reject($purchaseRequest, $userId, $validated['reason']);

            return AdminResponse::success(
                new PurchaseRequestResource($rejected),
                trans_message('procurement.purchase_requests.rejected')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_requests.reject.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_requests.reject_error'), 500);
        }
    }

    public function createOrder(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $purchaseRequest = $this->service->find($id, $organizationId);

            if (!$purchaseRequest) {
                return AdminResponse::error(trans_message('procurement.purchase_requests.not_found'), 404);
            }

            $validated = $request->validate([
                'supplier_id' => [
                    'required',
                    'integer',
                    Rule::exists('suppliers', 'id')->where(static function ($query) use ($organizationId) {
                        $query->where('organization_id', $organizationId)
                            ->whereNull('deleted_at');
                    }),
                ],
            ]);

            $order = $this->service->assignToSupplier($purchaseRequest, (int) $validated['supplier_id']);

            return AdminResponse::success(
                [
                    'purchase_request' => (new PurchaseRequestResource($purchaseRequest->fresh(['siteRequest.project', 'assignedUser', 'purchaseOrders.supplier'])))->resolve(),
                    'purchase_order' => (new PurchaseOrderResource($order))->resolve(),
                ],
                trans_message('procurement.purchase_requests.order_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_requests.create_order.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_requests.create_order_error'), 500);
        }
    }
}
