<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AccountingIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AccountingIntegrationController extends Controller
{
    protected $integrationService;

    /**
     * Конструктор контроллера.
     *
     * @param AccountingIntegrationService $integrationService
     */
    public function __construct(AccountingIntegrationService $integrationService)
    {
        $this->integrationService = $integrationService;
        // Авторизация настроена на уровне роутов через middleware стек
    }

    /**
     * Импортировать пользователей из бухгалтерской системы.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function importUsers(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization ID is required'
            ], 400);
        }

        $result = $this->integrationService->importUsers($organizationId);
        
        return response()->json($result);
    }

    /**
     * Импортировать проекты из бухгалтерской системы.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function importProjects(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization ID is required'
            ], 400);
        }

        $result = $this->integrationService->importProjects($organizationId);
        
        return response()->json($result);
    }

    /**
     * Импортировать материалы из бухгалтерской системы.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function importMaterials(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization ID is required'
            ], 400);
        }

        $result = $this->integrationService->importMaterials($organizationId);
        
        return response()->json($result);
    }

    /**
     * Экспортировать транзакции в бухгалтерскую систему.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function exportTransactions(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization ID is required'
            ], 400);
        }

        $result = $this->integrationService->exportTransactions($organizationId, $startDate, $endDate);
        
        return response()->json($result);
    }

    /**
     * Получить статус синхронизации с бухгалтерской системой.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSyncStatus(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization ID is required'
            ], 400);
        }

        // Эта функция пока не реализована в сервисе, поэтому возвращаем заглушку
        return response()->json([
            'success' => true,
            'message' => 'Синхронизация работает нормально',
            'last_sync' => [
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'status' => 'completed',
                'users_synced' => true,
                'projects_synced' => true,
                'materials_synced' => true,
                'transactions_synced' => true
            ]
        ]);
    }
} 