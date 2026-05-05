<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Resources\ProcurementIssueResource;
use App\BusinessModules\Features\Procurement\Services\ProcurementIssueService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class ProcurementIssueController extends Controller
{
    public function __construct(
        private readonly ProcurementIssueService $service
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'scope' => ['sometimes', 'nullable', 'string', 'in:all,purchase_requests,purchase_orders'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $page = max((int) ($validated['page'] ?? 1), 1);
            $perPage = min((int) ($validated['per_page'] ?? 15), 100);
            $scope = isset($validated['scope']) ? (string) $validated['scope'] : null;

            $result = $this->service->paginate($organizationId, $scope, $page, $perPage);

            return AdminResponse::paginated(
                ProcurementIssueResource::collection($result['items']),
                $result['meta'],
                null,
                200,
                $result['summary']
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('procurement.issues.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.issues.index_error'), 500);
        }
    }
}
