<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\EstimatePositionCatalog\ImportExportService;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\ImportPositionsRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use function trans_message;

class EstimatePositionImportExportController extends Controller
{
    public function __construct(
        private readonly ImportExportService $service
    ) {}

    /**
     * Скачать шаблон для импорта
     */
    public function template(): BinaryFileResponse
    {
        try {
            $filePath = $this->service->generateTemplate();

            return response()->download($filePath, 'estimate_positions_template.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('estimate_position_import.template.error', [
                'error' => $e->getMessage(),
            ]);

            abort(500, trans_message('estimate.template_generation_error'));
        }
    }

    /**
     * Импортировать позиции из файла
     */
    public function import(ImportPositionsRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            $userId = $request->user()->id;
            $file = $request->file('file');

            $result = $this->service->importFromExcel($organizationId, $file, $userId);

            $message = __('estimate.positions_import_completed', [
                'imported' => $result['imported'],
                'skipped' => $result['skipped']
            ]);

            $statusCode = $result['skipped'] > 0 ? Response::HTTP_MULTI_STATUS : Response::HTTP_OK;

            return AdminResponse::success($result, $message, $statusCode);
        } catch (\Exception $e) {
            Log::error('estimate_position_import.import.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.positions_import_error') . ': ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Экспортировать позиции в Excel
     */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $filters = $request->only(['category_id', 'item_type', 'is_active']);

            $filePath = $this->service->exportToExcel($organizationId, $filters);

            return response()->download($filePath, 'estimate_positions_' . date('Y-m-d') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('estimate_position_export.excel.error', [
                'error' => $e->getMessage(),
            ]);

            abort(500, trans_message('estimate.positions_export_error'));
        }
    }

    /**
     * Экспортировать позиции в CSV
     */
    public function exportCsv(Request $request): BinaryFileResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;

            $filters = $request->only(['category_id', 'item_type', 'is_active']);

            $filePath = $this->service->exportToCsv($organizationId, $filters);

            return response()->download($filePath, 'estimate_positions_' . date('Y-m-d') . '.csv', [
                'Content-Type' => 'text/csv',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('estimate_position_export.csv.error', [
                'error' => $e->getMessage(),
            ]);

            abort(500, trans_message('estimate.positions_export_error'));
        }
    }
}
