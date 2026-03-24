<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Requests\HoldingReportRequest;
use App\BusinessModules\Core\MultiOrganization\Services\HoldingReportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function trans_message;

class HoldingReportsController extends Controller
{
    public function __construct(
        protected HoldingReportService $reportService
    ) {
    }

    public function projectsSummary(HoldingReportRequest $request): JsonResponse|StreamedResponse
    {
        return $this->respondWithReport(
            $request,
            'projectsSummary',
            trans_message('holding.reports_projects_error'),
            fn (int $holdingId) => $this->reportService->getProjectsSummaryReport($request, $holdingId)
        );
    }

    public function contractsSummary(HoldingReportRequest $request): JsonResponse|StreamedResponse
    {
        return $this->respondWithReport(
            $request,
            'contractsSummary',
            trans_message('holding.reports_contracts_error'),
            fn (int $holdingId) => $this->reportService->getContractsSummaryReport($request, $holdingId)
        );
    }

    public function intraGroup(HoldingReportRequest $request): JsonResponse|StreamedResponse
    {
        return $this->respondWithReport(
            $request,
            'intraGroup',
            trans_message('holding.reports_intragroup_error'),
            fn (int $holdingId) => $this->reportService->getIntraGroupReport($request, $holdingId)
        );
    }

    public function consolidated(HoldingReportRequest $request): JsonResponse|StreamedResponse
    {
        return $this->respondWithReport(
            $request,
            'consolidated',
            trans_message('holding.reports_consolidated_error'),
            fn (int $holdingId) => $this->reportService->getConsolidatedReport($request, $holdingId)
        );
    }

    public function detailedContracts(HoldingReportRequest $request): JsonResponse|StreamedResponse
    {
        return $this->respondWithReport(
            $request,
            'detailedContracts',
            trans_message('holding.reports_detailed_contracts_error'),
            fn (int $holdingId) => $this->reportService->getDetailedContractsReport($request, $holdingId)
        );
    }

    private function respondWithReport(
        HoldingReportRequest $request,
        string $action,
        string $errorMessage,
        callable $resolver
    ): JsonResponse|StreamedResponse {
        try {
            $holdingId = (int) $request->attributes->get('current_organization_id');
            $result = $resolver($holdingId);

            if ($result instanceof StreamedResponse) {
                return $result;
            }

            return AdminResponse::success($result);
        } catch (\Throwable $e) {
            Log::error("[HoldingReportsController.{$action}] Unexpected error", [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error($errorMessage, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
