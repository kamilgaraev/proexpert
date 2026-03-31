<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ContractorReportRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Report\ContractorReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function trans_message;

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
        try {
            $reportOutput = $this->contractorReportService->getContractorSummaryReport($request);

            if ($reportOutput instanceof StreamedResponse) {
                return $reportOutput;
            }

            return AdminResponse::success($reportOutput);
        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('contract.report_error') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Детальный отчет по конкретному подрядчику в рамках проекта.
     *
     * @param ContractorReportRequest $request
     * @param int $contractorId
     * @return JsonResponse|StreamedResponse
     */
    public function contractorDetailReport(ContractorReportRequest $request, ?int $contractorId = null): JsonResponse | StreamedResponse
    {
        try {
            $resolvedContractorId = $contractorId ?? $request->validated('contractor_id');

            if (!$resolvedContractorId) {
                return AdminResponse::error(trans_message('reports.contractor_required'), 422);
            }

            $reportOutput = $this->contractorReportService->getContractorDetailReport($request, (int) $resolvedContractorId);

            if ($reportOutput instanceof StreamedResponse) {
                return $reportOutput;
            }

            return AdminResponse::success($reportOutput);
        } catch (\Exception $e) {
            Log::error('reports.contractor_detail.error', [
                'user_id' => $request->user()?->id,
                'project_id' => $request->input('project_id'),
                'contractor_id' => $contractorId ?? $request->input('contractor_id'),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('reports.generation_failed'), 500);
        }
    }
}
