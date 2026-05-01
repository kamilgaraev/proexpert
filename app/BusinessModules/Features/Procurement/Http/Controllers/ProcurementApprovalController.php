<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Resources\ProcurementApprovalResource;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Services\ProcurementApprovalService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ProcurementApprovalController extends Controller
{
    public function __construct(
        private readonly ProcurementApprovalService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => ['nullable', 'string', 'max:50'],
                'reason_code' => ['nullable', 'string', 'max:100'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $perPage = min((int) ($validated['per_page'] ?? 15), 100);

            $query = ProcurementApproval::query()
                ->forOrganization($organizationId)
                ->with([
                    'approvable.winningProposal',
                    'approvable.cheapestProposal',
                    'requestedBy',
                    'approvedBy',
                    'rejectedBy',
                ]);

            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            if (isset($validated['reason_code'])) {
                $query->where('reason_code', $validated['reason_code']);
            }

            $approvals = $query
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('requested_at')
                ->paginate($perPage);

            return AdminResponse::paginated(
                ProcurementApprovalResource::collection($approvals->items()),
                [
                    'current_page' => $approvals->currentPage(),
                    'per_page' => $approvals->perPage(),
                    'total' => $approvals->total(),
                    'last_page' => $approvals->lastPage(),
                ],
                trans_message('procurement.approvals.index_loaded')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('procurement.approvals.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'query' => $request->query(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.approvals.index_error'), 500);
        }
    }

    public function approve(Request $request, int $approval): JsonResponse
    {
        try {
            $validated = $request->validate([
                'comment' => ['nullable', 'string', 'max:5000'],
            ]);

            $approvalModel = $this->findApproval($request, $approval);
            $approved = $this->service->approve(
                $approvalModel,
                $this->actorId($request),
                $validated['comment'] ?? null
            );

            return AdminResponse::success(
                new ProcurementApprovalResource($approved),
                trans_message('procurement.approvals.approved')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.approvals.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.approvals.approve.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'approval_id' => $approval,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.approvals.approve_error'), 500);
        }
    }

    public function reject(Request $request, int $approval): JsonResponse
    {
        try {
            $validated = $request->validate([
                'comment' => ['nullable', 'string', 'max:5000'],
            ]);

            $approvalModel = $this->findApproval($request, $approval);
            $rejected = $this->service->reject(
                $approvalModel,
                $this->actorId($request),
                $validated['comment'] ?? null
            );

            return AdminResponse::success(
                new ProcurementApprovalResource($rejected),
                trans_message('procurement.approvals.rejected')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.approvals.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('procurement.approvals.reject.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'approval_id' => $approval,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.approvals.reject_error'), 500);
        }
    }

    private function findApproval(Request $request, int $approval): ProcurementApproval
    {
        return ProcurementApproval::query()
            ->forOrganization((int) $request->attributes->get('current_organization_id'))
            ->findOrFail($approval);
    }

    private function actorId(Request $request): int
    {
        $userId = $request->user()?->id;

        if ($userId === null) {
            throw new \DomainException(trans_message('procurement.access_denied'));
        }

        return (int) $userId;
    }
}
