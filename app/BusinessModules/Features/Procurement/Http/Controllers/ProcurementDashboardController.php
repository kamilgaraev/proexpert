<?php

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\Enums\Contract\ContractStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class ProcurementDashboardController extends Controller
{
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
                        ->where('status', ContractStatusEnum::ACTIVE->value)->count(),
                ],
                'supplier_proposals' => [
                    'total' => \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)->count(),
                    'submitted' => \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)
                        ->withStatus('submitted')->count(),
                    'accepted' => \App\BusinessModules\Features\Procurement\Models\SupplierProposal::forOrganization($organizationId)
                        ->withStatus('accepted')->count(),
                ],
            ];

            return AdminResponse::success($data);
        } catch (\Exception $e) {
            Log::error('procurement.dashboard.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.dashboard_load_error'), 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $period = $request->input('period', '30d');

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

            return AdminResponse::success($stats);
        } catch (\Exception $e) {
            Log::error('procurement.dashboard.statistics.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'period' => $request->input('period'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.statistics_load_error'), 500);
        }
    }
}
