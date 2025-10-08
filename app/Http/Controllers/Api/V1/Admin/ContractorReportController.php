<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Report\ContractorReportService;
use App\Http\Requests\Api\V1\Admin\ContractorReportRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractorReportController extends Controller
{
    protected ContractorReportService $contractorReportService;

    public function __construct(ContractorReportService $contractorReportService)
    {
        $this->contractorReportService = $contractorReportService;
    }

    /**
     * Отчет по итогам подрядчиков за выбранный проект.
     *
     * @param ContractorReportRequest $request
     * @return JsonResponse|StreamedResponse
     */
    public function contractorSummaryReport(ContractorReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->contractorReportService->getContractorSummaryReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    /**
     * Детальный отчет по конкретному подрядчику в рамках проекта.
     *
     * @param ContractorReportRequest $request
     * @param int $contractorId
     * @return JsonResponse|StreamedResponse
     */
    public function contractorDetailReport(ContractorReportRequest $request, int $contractorId): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->contractorReportService->getContractorDetailReport($request, $contractorId);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }
}