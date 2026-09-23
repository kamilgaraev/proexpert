<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehouseStockExportRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseStockExportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WarehouseStockExportController extends Controller
{
    public function __construct(private readonly WarehouseStockExportService $exportService) {}

    public function __invoke(WarehouseStockExportRequest $request, int $id, string $format): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouse = OrganizationWarehouse::query()
                ->where('organization_id', $organizationId)
                ->findOrFail($id);
            $result = $this->exportService->export(
                $warehouse,
                $organizationId,
                (int) $request->user()->id,
                Arr::only($request->validated(), [
                    'asset_type', 'low_stock', 'cell_id', 'zone_id', 'search', 'missing_location',
                ]),
                $format,
            );

            return AdminResponse::success($result);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.warehouse.not_found'), 404);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('Warehouse stock export failed', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()->id,
                'warehouse_id' => $id,
                'format' => $format,
                'exception' => $exception,
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.warehouse.stock_export_failed'), 500);
        }
    }
}
