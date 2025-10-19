<?php

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\MultiOrganization\Services\HoldingReportService;
use App\BusinessModules\Core\MultiOrganization\Requests\HoldingReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HoldingReportsController extends Controller
{
    public function __construct(
        protected HoldingReportService $reportService
    ) {}

    public function projectsSummary(HoldingReportRequest $request): JsonResponse|StreamedResponse
    {
        $holdingId = $request->attributes->get('current_organization_id');
        
        $result = $this->reportService->getProjectsSummaryReport($request, $holdingId);

        if ($result instanceof StreamedResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function contractsSummary(HoldingReportRequest $request): JsonResponse|StreamedResponse
    {
        $holdingId = $request->attributes->get('current_organization_id');
        
        $result = $this->reportService->getContractsSummaryReport($request, $holdingId);

        if ($result instanceof StreamedResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}

