<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    /**
     * Получить графики платежей
     * 
     * GET /api/v1/admin/payments/schedules
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = PaymentSchedule::with(['invoice']);
            
            if ($request->has('invoice_id')) {
                $query->where('invoice_id', $request->input('invoice_id'));
            }
            
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }
            
            $schedules = $query->orderBy('due_date', 'asc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $schedules,
            ]);
        } catch (\Exception $e) {
            Log::error('payments.schedules.index.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить графики платежей',
            ], 500);
        }
    }
    
    /**
     * Создать график платежей
     * 
     * POST /api/v1/admin/payments/schedules
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|integer|exists:invoices,id',
            'installments' => 'required|array|min:1',
            'installments.*.installment_number' => 'required|integer|min:1',
            'installments.*.due_date' => 'required|date',
            'installments.*.amount' => 'required|numeric|min:0',
            'installments.*.notes' => 'nullable|string|max:500',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $invoice = Invoice::where('organization_id', $organizationId)
                ->findOrFail($request->input('invoice_id'));
            
            // Проверка суммы
            $totalScheduleAmount = collect($request->input('installments'))
                ->sum('amount');
            
            if ($totalScheduleAmount != $invoice->total_amount) {
                return response()->json([
                    'success' => false,
                    'error' => 'Сумма графика платежей должна равняться сумме счёта',
                ], 422);
            }
            
            $schedules = [];
            
            DB::transaction(function () use ($request, &$schedules) {
                foreach ($request->input('installments') as $installment) {
                    $schedule = PaymentSchedule::create([
                        'invoice_id' => $request->input('invoice_id'),
                        'installment_number' => $installment['installment_number'],
                        'due_date' => $installment['due_date'],
                        'amount' => $installment['amount'],
                        'status' => 'pending',
                        'notes' => $installment['notes'] ?? null,
                    ]);
                    
                    $schedules[] = $schedule;
                }
            });
            
            return response()->json([
                'success' => true,
                'message' => 'График платежей создан',
                'data' => $schedules,
            ], 201);
        } catch (\Exception $e) {
            Log::error('payments.schedule.store.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать график платежей',
            ], 500);
        }
    }
}

