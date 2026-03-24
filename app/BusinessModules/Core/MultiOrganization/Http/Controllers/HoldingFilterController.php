<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Services\FilterScopeManager;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class HoldingFilterController extends Controller
{
    public function __construct(
        private FilterScopeManager $filterManager
    ) {
    }

    public function getFilterOptions(Request $request): JsonResponse
    {
        try {
            $orgId = (int) $request->attributes->get('current_organization_id');
            $org = Organization::findOrFail($orgId);

            if (!$org->is_holding) {
                return AdminResponse::error(trans_message('holding.access_denied'), Response::HTTP_FORBIDDEN);
            }

            return AdminResponse::success($this->filterManager->getFilterOptions($orgId));
        } catch (\Throwable $e) {
            Log::error('[HoldingFilterController.getFilterOptions] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('holding.filters_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
