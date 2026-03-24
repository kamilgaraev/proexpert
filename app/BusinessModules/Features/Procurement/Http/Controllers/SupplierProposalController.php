<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Requests\StoreSupplierProposalRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\SupplierProposalResource;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class SupplierProposalController extends Controller
{
    public function __construct(
        private readonly SupplierProposalService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $perPage = min((int) $request->input('per_page', 15), 100);

            $query = SupplierProposal::forOrganization($organizationId)
                ->with(['supplier', 'purchaseOrder']);

            if ($request->has('purchase_order_id')) {
                $query->forOrder($request->input('purchase_order_id'));
            }

            if ($request->has('status')) {
                $query->withStatus($request->input('status'));
            }

            $proposals = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return AdminResponse::paginated(
                SupplierProposalResource::collection($proposals->items()),
                [
                    'current_page' => $proposals->currentPage(),
                    'per_page' => $proposals->perPage(),
                    'total' => $proposals->total(),
                    'last_page' => $proposals->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('procurement.supplier_proposals.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'query' => $request->query(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposals.index_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $proposal = SupplierProposal::forOrganization($organizationId)
                ->with(['supplier', 'purchaseOrder'])
                ->find($id);

            if (!$proposal) {
                return AdminResponse::error(trans_message('procurement.proposals.not_found'), 404);
            }

            return AdminResponse::success(new SupplierProposalResource($proposal));
        } catch (\Exception $e) {
            Log::error('procurement.supplier_proposals.show.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'proposal_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposals.show_error'), 500);
        }
    }

    public function store(StoreSupplierProposalRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $order = PurchaseOrder::forOrganization($organizationId)
                ->findOrFail($request->input('purchase_order_id'));

            $proposal = $this->service->createFromOrder($order, $request->validated());

            return AdminResponse::success(
                new SupplierProposalResource($proposal),
                trans_message('procurement.proposals.created'),
                201
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.supplier_proposals.store.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'payload' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposals.store_error'), 500);
        }
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $proposal = SupplierProposal::forOrganization($organizationId)->findOrFail($id);
            $accepted = $this->service->accept($proposal);

            return AdminResponse::success(
                new SupplierProposalResource($accepted),
                trans_message('procurement.proposals.accepted')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.proposals.not_found'), 404);
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.supplier_proposals.accept.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'proposal_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposals.accept_error'), 500);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $proposal = SupplierProposal::forOrganization($organizationId)->findOrFail($id);

            $request->validate([
                'reason' => 'required|string|max:1000',
            ]);

            $rejected = $this->service->reject($proposal, $request->input('reason'));

            return AdminResponse::success(
                new SupplierProposalResource($rejected),
                trans_message('procurement.proposals.rejected')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.proposals.not_found'), 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.supplier_proposals.reject.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'proposal_id' => $id,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposals.reject_error'), 500);
        }
    }
}
