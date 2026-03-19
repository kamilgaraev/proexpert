<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Requests\StorePurchaseOrderRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\ProcurementAuditLogResource;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseContractResource;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseOrderItemResource;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseOrderResource;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditLog;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use function trans_message;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $service
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $perPage = min((int) $request->input('per_page', 15), 100);

            $query = PurchaseOrder::forOrganization($organizationId)
                ->with(['supplier', 'purchaseRequest', 'contract', 'items', 'organization']);

            if ($request->filled('status')) {
                $query->withStatus((string) $request->input('status'));
            }

            if ($request->filled('supplier_id')) {
                $query->where('supplier_id', (int) $request->input('supplier_id'));
            }

            if ($request->filled('purchase_request_id')) {
                $query->where('purchase_request_id', (int) $request->input('purchase_request_id'));
            }

            $sortBy = (string) $request->input('sort_by', 'created_at');
            $sortDir = (string) $request->input('sort_dir', 'desc');

            $orders = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

            return AdminResponse::paginated(
                PurchaseOrderResource::collection($orders->getCollection()),
                [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'last_page' => $orders->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.index.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.index_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $order = PurchaseOrder::forOrganization($organizationId)
                ->with(['supplier', 'purchaseRequest', 'contract', 'proposals', 'items', 'organization'])
                ->find($id);

            if (!$order) {
                return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
            }

            return AdminResponse::success(new PurchaseOrderResource($order));
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.show.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.show_error'), 500);
        }
    }

    public function items(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $order = PurchaseOrder::forOrganization($organizationId)
                ->with(['items.material'])
                ->find($id);

            if (!$order) {
                return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
            }

            return AdminResponse::success(
                PurchaseOrderItemResource::collection($order->items),
                trans_message('procurement.purchase_orders.items_loaded')
            );
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.items.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.show_error'), 500);
        }
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $purchaseRequest = PurchaseRequest::forOrganization($organizationId)
                ->findOrFail((int) $request->input('purchase_request_id'));

            $order = $this->service->create($purchaseRequest, (int) $request->input('supplier_id'), $request->validated());

            return AdminResponse::success(
                new PurchaseOrderResource($order),
                trans_message('procurement.purchase_orders.created'),
                201
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.purchase_requests.not_found'), 404);
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.store.error', [
                'user_id' => auth()->id(),
                'payload' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.store_error'), 500);
        }
    }

    public function send(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $order = PurchaseOrder::forOrganization($organizationId)
                ->with(['items', 'supplier', 'purchaseRequest', 'organization'])
                ->find($id);

            if (!$order) {
                return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
            }

            $sent = $this->service->sendToSupplier($order);

            return AdminResponse::success(
                new PurchaseOrderResource($sent),
                trans_message('procurement.purchase_orders.sent')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.send.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.send_error'), 500);
        }
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $order = PurchaseOrder::forOrganization($organizationId)
                ->with(['items', 'supplier', 'organization'])
                ->find($id);

            if (!$order) {
                return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
            }

            $validated = $request->validate([
                'total_amount' => ['sometimes', 'numeric', 'min:0'],
                'items' => ['sometimes', 'array'],
            ]);

            $confirmed = $this->service->confirm($order, $validated);

            return AdminResponse::success(
                new PurchaseOrderResource($confirmed),
                trans_message('procurement.purchase_orders.confirmed')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.confirm.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.confirm_error'), 500);
        }
    }

    public function createContract(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $order = PurchaseOrder::forOrganization($organizationId)->find($id);

            if (!$order) {
                return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
            }

            $contract = $this->service->createContractFromOrder($order);

            return AdminResponse::success(
                [
                    'purchase_order' => (new PurchaseOrderResource($order->fresh(['supplier', 'purchaseRequest', 'contract', 'items'])))->resolve(),
                    'contract' => (new PurchaseContractResource($contract))->resolve(),
                ],
                trans_message('procurement.purchase_orders.contract_created'),
                201
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.create_contract.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.create_contract_error'), 500);
        }
    }

    public function receiveMaterials(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $order = PurchaseOrder::forOrganization($organizationId)
                ->with(['items', 'supplier', 'purchaseRequest'])
                ->find($id);

            if (!$order) {
                return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
            }

            $validated = $request->validate([
                'warehouse_id' => [
                    'required',
                    'integer',
                    Rule::exists('organization_warehouses', 'id')->where(static function ($query) use ($organizationId) {
                        $query->where('organization_id', $organizationId)
                            ->where('is_active', true)
                            ->whereNull('deleted_at');
                    }),
                ],
                'items' => ['required', 'array', 'min:1'],
                'items.*.item_id' => [
                    'required',
                    'integer',
                    Rule::exists('purchase_order_items', 'id')->where(static function ($query) use ($order) {
                        $query->where('purchase_order_id', $order->id);
                    }),
                ],
                'items.*.quantity_received' => ['required', 'numeric', 'min:0.001'],
                'items.*.price' => ['required', 'numeric', 'min:0'],
            ]);

            $received = $this->service->receiveMaterials(
                $order,
                (int) $validated['warehouse_id'],
                $validated['items'],
                (int) auth()->id()
            );

            return AdminResponse::success(
                new PurchaseOrderResource($received),
                trans_message('procurement.purchase_orders.received')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.receive_materials.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.receive_error'), 500);
        }
    }

    public function markInDelivery(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $order = PurchaseOrder::forOrganization($organizationId)
                ->with(['items', 'supplier', 'organization'])
                ->find($id);

            if (!$order) {
                return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
            }

            $updated = $this->service->markInDelivery($order);

            return AdminResponse::success(
                new PurchaseOrderResource($updated),
                trans_message('procurement.purchase_orders.marked_in_delivery')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.purchase_orders.mark_in_delivery.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.purchase_orders.mark_delivery_error'), 500);
        }
    }

    public function auditLogs(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'auditable_type' => ['required', 'string'],
                'auditable_id' => ['required', 'integer', 'min:1'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $auditableType = $this->resolveAuditableType($validated['auditable_type']);
            if (!$auditableType) {
                return AdminResponse::error(trans_message('procurement.audit_logs.unsupported_type'), 422);
            }

            $page = (int) ($validated['page'] ?? 1);
            $perPage = (int) ($validated['per_page'] ?? 100);

            $logs = ProcurementAuditLog::query()
                ->where('organization_id', $organizationId)
                ->where('auditable_type', $auditableType)
                ->where('auditable_id', (int) $validated['auditable_id'])
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->forPage($page, $perPage)
                ->get();

            return AdminResponse::success(
                ProcurementAuditLogResource::collection($logs),
                trans_message('procurement.purchase_orders.audit_logs_loaded')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('procurement.audit_logs.index.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.audit_logs.index_error'), 500);
        }
    }

    private function resolveAuditableType(string $type): ?string
    {
        return match ($type) {
            'PurchaseRequest', PurchaseRequest::class => PurchaseRequest::class,
            'PurchaseOrder', PurchaseOrder::class => PurchaseOrder::class,
            'SupplierProposal', SupplierProposal::class => SupplierProposal::class,
            default => null,
        };
    }
}
