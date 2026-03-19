<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function trans_message;

class PaymentTemplatesController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $templates = [
                [
                    'id' => 'advance_30',
                    'name' => 'Аванс 30%',
                    'invoice_type' => 'advance',
                    'percentage' => 30,
                    'auto_calculate' => true,
                    'description' => 'Авансовый платеж 30% от суммы контракта',
                ],
                [
                    'id' => 'advance_50',
                    'name' => 'Аванс 50%',
                    'invoice_type' => 'advance',
                    'percentage' => 50,
                    'auto_calculate' => true,
                    'description' => 'Авансовый платеж 50% от суммы контракта',
                ],
                [
                    'id' => 'advance_70',
                    'name' => 'Аванс 70%',
                    'invoice_type' => 'advance',
                    'percentage' => 70,
                    'auto_calculate' => true,
                    'description' => 'Авансовый платеж 70% от суммы контракта',
                ],
                [
                    'id' => 'advance_100',
                    'name' => 'Аванс 100%',
                    'invoice_type' => 'advance',
                    'percentage' => 100,
                    'auto_calculate' => true,
                    'description' => 'Предоплата 100% от суммы контракта',
                ],
                [
                    'id' => 'custom_advance',
                    'name' => 'Произвольный аванс',
                    'invoice_type' => 'advance',
                    'percentage' => null,
                    'auto_calculate' => false,
                    'description' => 'Авансовый платеж произвольной суммы',
                ],
                [
                    'id' => 'progress',
                    'name' => 'Промежуточный платеж',
                    'invoice_type' => 'progress',
                    'percentage' => null,
                    'auto_calculate' => false,
                    'description' => 'Промежуточный платеж по этапу работ',
                ],
                [
                    'id' => 'final_100',
                    'name' => 'Финальный расчет 100%',
                    'invoice_type' => 'final',
                    'percentage' => 100,
                    'auto_calculate' => true,
                    'description' => 'Окончательный расчет 100% от суммы контракта',
                ],
                [
                    'id' => 'custom_final',
                    'name' => 'Произвольный финальный расчет',
                    'invoice_type' => 'final',
                    'percentage' => null,
                    'auto_calculate' => false,
                    'description' => 'Финальный расчет произвольной суммы',
                ],
                [
                    'id' => 'act',
                    'name' => 'По акту выполненных работ',
                    'invoice_type' => 'act',
                    'percentage' => null,
                    'auto_calculate' => false,
                    'description' => 'Оплата по факту выполненных работ согласно акту',
                ],
            ];

            return AdminResponse::success($templates, trans_message('payments.templates.loaded'));
        } catch (\Exception $e) {
            Log::error('payments.templates.index.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.templates.load_error'), 500);
        }
    }

    public function calculate(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');

        $validated = $request->validate([
            'contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where('organization_id', $organizationId),
            ],
            'template_id' => 'required|string|in:advance_30,advance_50,advance_70,advance_100,final_100',
        ]);

        try {
            $contract = DB::table('contracts')
                ->where('id', $validated['contract_id'])
                ->where('organization_id', $organizationId)
                ->first(['id', 'total_amount']);

            if (!$contract) {
                return AdminResponse::error(trans_message('payments.templates.contract_not_found'), 404);
            }

            $percentageMap = [
                'advance_30' => 30,
                'advance_50' => 50,
                'advance_70' => 70,
                'advance_100' => 100,
                'final_100' => 100,
            ];

            $percentage = $percentageMap[$validated['template_id']] ?? null;

            if ($percentage === null) {
                return AdminResponse::error(trans_message('payments.templates.template_not_supported'), 422);
            }

            $calculatedAmount = round(($contract->total_amount * $percentage) / 100, 2);

            return AdminResponse::success([
                'contract_id' => (int) $validated['contract_id'],
                'contract_total_amount' => (float) $contract->total_amount,
                'template_id' => $validated['template_id'],
                'percentage' => $percentage,
                'calculated_amount' => $calculatedAmount,
            ], trans_message('payments.templates.calculated'));
        } catch (\Exception $e) {
            Log::error('payments.calculate.error', [
                'error' => $e->getMessage(),
                'contract_id' => $request->input('contract_id'),
                'template_id' => $request->input('template_id'),
            ]);

            return AdminResponse::error(trans_message('payments.templates.calculate_error'), 500);
        }
    }
}
