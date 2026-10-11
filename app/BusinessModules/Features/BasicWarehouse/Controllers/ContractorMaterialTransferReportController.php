<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ContractorMaterialTransferReportRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\ContractorMaterialTransferReportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ContractorMaterialTransferReportController extends Controller
{
    public function __construct(private readonly ContractorMaterialTransferReportService $reports) {}

    public function index(ContractorMaterialTransferReportRequest $request): JsonResponse
    {
        return $this->respond($request, false);
    }

    public function export(ContractorMaterialTransferReportRequest $request): JsonResponse
    {
        return $this->respond($request, true);
    }

    public function catalog(ContractorMaterialTransferReportRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->reports->catalog($request->user()));
        } catch (AuthorizationException) {
            return AdminResponse::error(trans_message('warehouse_basic.access_denied'), 403);
        } catch (Throwable $exception) {
            Log::error('Warehouse report catalog failed', ['user_id' => $request->user()?->id, 'exception' => $exception]);

            return AdminResponse::error(trans_message('warehouse_basic.tolling_report.load_error'), 500);
        }
    }

    private function respond(ContractorMaterialTransferReportRequest $request, bool $export): JsonResponse
    {
        try {
            return AdminResponse::success($export
                ? $this->reports->export($request->user(), $request->validated())
                : $this->reports->report($request->user(), $request->validated()));
        } catch (AuthorizationException) {
            return AdminResponse::error(trans_message('warehouse_basic.access_denied'), 403);
        } catch (ValidationException $exception) {
            return AdminResponse::error(
                collect($exception->errors())->flatten()->first() ?? trans_message('warehouse_basic.tolling_report.invalid_filter'),
                422,
                $exception->errors(),
            );
        } catch (Throwable $exception) {
            Log::error('Contractor material transfer report failed', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'export' => $export,
                'exception' => $exception,
            ]);

            return AdminResponse::error(trans_message('warehouse_basic.tolling_report.load_error'), 500);
        }
    }
}
