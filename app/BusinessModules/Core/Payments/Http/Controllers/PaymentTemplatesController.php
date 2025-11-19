<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentTemplatesController extends Controller
{
    /**
     * Получить шаблоны типов платежей
     * 
     * GET /api/v1/admin/payments/templates
     */
    public function index(): JsonResponse
    {
        try {
            $templates = [
                [
                    'id' => 'advance_30',
                    'name' => 'Аванс (30%)',
                    'invoice_type' => 'advance',
                    'percentage' => 30,
                    'auto_calculate' => true,
                    'description' => 'Авансовый платеж 30% от суммы контракта',
                ],
                [
                    'id' => 'advance_50',
                    'name' => 'Аванс (50%)',
                    'invoice_type' => 'advance',
                    'percentage' => 50,
                    'auto_calculate' => true,
                    'description' => 'Авансовый платеж 50% от суммы контракта',
                ],
                [
                    'id' => 'advance_70',
                    'name' => 'Аванс (70%)',
                    'invoice_type' => 'advance',
                    'percentage' => 70,
                    'auto_calculate' => true,
                    'description' => 'Авансовый платеж 70% от суммы контракта',
                ],
                [
                    'id' => 'progress',
                    'name' => 'Промежуточный платеж',
                    'invoice_type' => 'progress',
                    'percentage' => null,
                    'auto_calculate' => false,
                    'description' => 'Промежуточный платеж (сумма вводится вручную)',
                ],
                [
                    'id' => 'final',
                    'name' => 'Окончательный расчет',
                    'invoice_type' => 'final',
                    'percentage' => null,
                    'auto_calculate' => false,
                    'description' => 'Финальный расчет после выполнения работ (сумма вводится вручную)',
                ],
                [
                    'id' => 'act',
                    'name' => 'По акту выполненных работ',
                    'invoice_type' => 'act',
                    'percentage' => null,
                    'auto_calculate' => false,
                    'description' => 'Оплата по факту выполненных работ (сумма вводится вручную)',
                ],
            ];
            
            return response()->json([
                'success' => true,
                'data' => $templates,
            ]);
        } catch (\Exception $e) {
            Log::error('payments.templates.index.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить шаблоны',
            ], 500);
        }
    }

    /**
     * Рассчитать сумму платежа по шаблону
     * 
     * POST /api/v1/admin/payments/calculate
     * 
     * Body:
     * {
     *   "contract_id": 72,
     *   "template_id": "advance_30"
     * }
     */
    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
            'template_id' => 'required|string|in:advance_30,advance_50,advance_70',
        ]);

        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $contractId = $request->input('contract_id');
            $templateId = $request->input('template_id');

            // Получить сумму контракта
            $contract = DB::table('contracts')
                ->where('id', $contractId)
                ->where('organization_id', $organizationId)
                ->first(['id', 'total_amount']);

            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'error' => 'Контракт не найден',
                ], 404);
            }

            // Определить процент по шаблону
            $percentageMap = [
                'advance_30' => 30,
                'advance_50' => 50,
                'advance_70' => 70,
            ];

            $percentage = $percentageMap[$templateId];
            $calculatedAmount = round(($contract->total_amount * $percentage) / 100, 2);

            return response()->json([
                'success' => true,
                'data' => [
                    'contract_id' => $contractId,
                    'contract_total_amount' => (float) $contract->total_amount,
                    'template_id' => $templateId,
                    'percentage' => $percentage,
                    'calculated_amount' => $calculatedAmount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.calculate.error', [
                'error' => $e->getMessage(),
                'contract_id' => $request->input('contract_id'),
                'template_id' => $request->input('template_id'),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось рассчитать сумму',
            ], 500);
        }
    }
}

