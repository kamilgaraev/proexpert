<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Requests\CreatePurchaseContractRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseContractResource;
use App\BusinessModules\Features\Procurement\Services\PurchaseContractService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class PurchaseContractController extends Controller
{
    public function __construct(
        private readonly PurchaseContractService $contractService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $perPage = min((int) $request->input('per_page', 15), 100);

            $query = Contract::forOrganization($organizationId)
                ->procurementContracts()
                ->with(['supplier', 'project', 'organization']);

            if ($request->has('supplier_id')) {
                $supplierId = $request->input('supplier_id');
                if ($supplierId !== null && $supplierId !== '') {
                    $query->where('supplier_id', (int) $supplierId);
                }
            }

            if ($request->has('project_id')) {
                $projectId = $request->input('project_id');
                if ($projectId !== null && $projectId !== '') {
                    $query->where('project_id', (int) $projectId);
                }
            }

            if ($request->has('status')) {
                $status = $request->input('status');
                if ($status !== null && $status !== '') {
                    $query->where('status', $status);
                }
            }

            $sortBy = $request->input('sort_by', 'created_at');
            $sortDir = $request->input('sort_dir', 'desc');
            $allowedSortFields = ['created_at', 'updated_at', 'date', 'number', 'total_amount', 'status'];

            if (in_array($sortBy, $allowedSortFields, true)) {
                $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $contracts = $query->paginate($perPage);

            return AdminResponse::paginated(
                PurchaseContractResource::collection($contracts->items()),
                [
                    'current_page' => $contracts->currentPage(),
                    'per_page' => $contracts->perPage(),
                    'total' => $contracts->total(),
                    'last_page' => $contracts->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('procurement.contracts.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'query' => $request->query(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.contracts.index_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $contract = Contract::forOrganization($organizationId)
                ->procurementContracts()
                ->with(['supplier', 'project', 'organization'])
                ->find($id);

            if (!$contract) {
                return AdminResponse::error(trans_message('procurement.contracts.not_found'), 404);
            }

            return AdminResponse::success(new PurchaseContractResource($contract));
        } catch (\Exception $e) {
            Log::error('procurement.contracts.show.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'contract_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.contracts.show_error'), 500);
        }
    }

    public function store(CreatePurchaseContractRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $contract = $this->contractService->createManualContract($request->validated(), $organizationId);

            return AdminResponse::success(
                new PurchaseContractResource($contract),
                trans_message('procurement.contracts.created'),
                201
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.contracts.store.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'payload' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.contracts.store_error'), 500);
        }
    }
}
