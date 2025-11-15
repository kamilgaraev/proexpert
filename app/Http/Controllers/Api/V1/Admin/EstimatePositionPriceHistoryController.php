<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\EstimatePositionCatalog\PriceHistoryService;
use App\Http\Resources\Api\V1\Admin\EstimatePosition\PriceHistoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EstimatePositionPriceHistoryController extends Controller
{
    public function __construct(
        private readonly PriceHistoryService $service
    ) {}

    /**
     * Получить историю цен для позиции
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $dateFrom = $request->has('date_from') 
                ? Carbon::parse($request->input('date_from')) 
                : null;
            
            $dateTo = $request->has('date_to') 
                ? Carbon::parse($request->input('date_to')) 
                : null;

            $history = $this->service->getPriceHistory($id, $dateFrom, $dateTo);

            return response()->json([
                'success' => true,
                'data' => PriceHistoryResource::collection($history),
                'statistics' => $this->service->getPriceStatistics($id),
            ]);
        } catch (\Exception $e) {
            Log::error('estimate_position_price_history.show.error', [
                'catalog_item_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить историю цен',
            ], 500);
        }
    }

    /**
     * Сравнить цены на две даты
     */
    public function compare(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'catalog_item_id' => 'required|integer|exists:estimate_position_catalog,id',
                'date1' => 'required|date',
                'date2' => 'required|date',
            ]);

            $catalogItemId = $request->input('catalog_item_id');
            $date1 = Carbon::parse($request->input('date1'));
            $date2 = Carbon::parse($request->input('date2'));

            $comparison = $this->service->comparePrice($catalogItemId, $date1, $date2);

            return response()->json([
                'success' => true,
                'data' => $comparison,
            ]);
        } catch (\Exception $e) {
            Log::error('estimate_position_price_history.compare.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось сравнить цены',
            ], 500);
        }
    }
}

