<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Supplier\SupplierService; // Используем существующий сервис
use App\Http\Resources\Api\V1\Mobile\Supplier\MobileSupplierResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    protected SupplierService $supplierService;

    public function __construct(SupplierService $supplierService)
    {
        $this->supplierService = $supplierService;
    }

    /**
     * Получить список поставщиков для текущей организации пользователя (прораба).
     * Возвращает всех активных поставщиков, не пагинированных.
     */
    public function index(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            // Предполагаем, что SupplierService имеет метод для получения активных поставщиков организации
            $suppliers = $this->supplierService->getActiveSuppliersForCurrentOrg($request); // Нужен такой или похожий метод в сервисе
            
            return MobileSupplierResource::collection($suppliers);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('[Mobile\SupplierController@index] Error fetching suppliers for mobile', [
                'user_id' => $request->user()?->id,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении списка поставщиков.'], 500);
        }
    }
} 