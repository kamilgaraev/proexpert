<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Контроллер дашборда модуля закупок
 */
class ProcurementDashboardController extends Controller
{
    /**
     * Получить данные дашборда
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $data = [
                'purchase_requests' => [
                    'total' => \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::forOrganization($organizationId)->count(),
                    'pending' => \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::forOrganization($organizationId)
                        ->withStatus('pending')->count(),
                    'approved' => \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::forOrganization($organizationId)
                        ->withStatus('approved')->count(),
                ],
                'purchase_orders' => [
                    'total' => \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)->count(),
                    'sent' => \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                        ->withStatus('sent')->count(),
                    'confirmed' => \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                        ->withStatus('confirmed')->count(),
                    'delivered' => \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                        ->withStatus('delivered')->count(),
                ],
                'contracts' => [
                    'total' => \App\Models\Contract::forOrganization($organizationId)
                        ->procurementContracts()->count(),
                    'active' => \App\Models\Contract::forOrganization($organizationId)
                        ->procurementContracts()
                        ->where('status', 'active')->count(),
                ],
                'supplier_proposals' => [
                    'total' => \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)->count(),
                    'submitted' => \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)
                        ->withStatus('submitted')->count(),
                    'accepted' => \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)
                        ->withStatus('accepted')->count(),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.dashboard.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить данные дашборда',
            ], 500);
        }
    }

    /**
     * Получить статистику
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $period = $request->input('period', '30d'); // 7d, 30d, 90d, 1y

            $dateFrom = match ($period) {
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
                '90d' => now()->subDays(90),
                '1y' => now()->subYear(),
                default => now()->subDays(30),
            };

            $stats = [
                'purchase_requests_count' => \App\BusinessModules\Features\Procurement\Models\PurchaseRequest::forOrganization($organizationId)
                    ->where('created_at', '>=', $dateFrom)->count(),
                'purchase_orders_count' => \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                    ->where('created_at', '>=', $dateFrom)->count(),
                'total_amount' => \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                    ->where('created_at', '>=', $dateFrom)
                    ->sum('total_amount'),
                'contracts_count' => \App\Models\Contract::forOrganization($organizationId)
                    ->procurementContracts()
                    ->where('created_at', '>=', $dateFrom)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.dashboard.statistics.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статистику',
            ], 500);
        }
    }
}

