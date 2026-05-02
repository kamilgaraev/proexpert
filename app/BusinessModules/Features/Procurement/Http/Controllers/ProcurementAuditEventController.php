<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Resources\ProcurementAuditEventResource;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ProcurementAuditEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'subject_type' => ['required', 'string'],
                'subject_id' => ['required', 'integer', 'min:1'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $subjectType = $this->resolveSubjectType($validated['subject_type']);
            if ($subjectType === null) {
                return AdminResponse::error(trans_message('procurement.audit_logs.unsupported_type'), 422);
            }

            $perPage = (int) ($validated['per_page'] ?? 100);

            $events = ProcurementAuditEvent::query()
                ->forOrganization($organizationId)
                ->where('subject_type', $subjectType)
                ->where('subject_id', (int) $validated['subject_id'])
                ->with(['actor:id,name', 'supplierParty'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            return AdminResponse::paginated(
                ProcurementAuditEventResource::collection($events->getCollection()),
                [
                    'current_page' => $events->currentPage(),
                    'per_page' => $events->perPage(),
                    'total' => $events->total(),
                    'last_page' => $events->lastPage(),
                ],
                trans_message('procurement.purchase_orders.audit_logs_loaded')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('procurement.audit_events.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'query' => $request->query(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.audit_logs.index_error'), 500);
        }
    }

    private function resolveSubjectType(string $type): ?string
    {
        $normalized = strtolower(str_replace(['-', ' ', '\\'], ['_', '_', '_'], trim($type)));

        $model = match ($normalized) {
            'supplierrequest', 'supplier_request', strtolower(str_replace('\\', '_', SupplierRequest::class)) => new SupplierRequest(),
            'supplierproposal', 'supplier_proposal', strtolower(str_replace('\\', '_', SupplierProposal::class)) => new SupplierProposal(),
            'supplierproposaldecision', 'supplier_proposal_decision', strtolower(str_replace('\\', '_', SupplierProposalDecision::class)) => new SupplierProposalDecision(),
            'purchaseorder', 'purchase_order', strtolower(str_replace('\\', '_', PurchaseOrder::class)) => new PurchaseOrder(),
            default => null,
        };

        if (!$model instanceof Model) {
            return null;
        }

        return $model->getMorphClass();
    }
}
