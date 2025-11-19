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
    
    /**
     * Получить предстоящие платежи по графику
     * 
     * GET /api/v1/admin/payments/schedules/upcoming?days=30
     */
    public function upcoming(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $days = (int) $request->input('days', 30);
            
            $schedules = PaymentSchedule::with(['invoice', 'invoice.project', 'invoice.contractor'])
                ->whereHas('invoice', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId);
                })
                ->where('status', 'pending')
                ->whereBetween('due_date', [now(), now()->addDays($days)])
                ->orderBy('due_date', 'asc')
                ->get()
                ->map(function ($schedule) {
                    return [
                        'id' => $schedule->id,
                        'invoice_id' => $schedule->invoice_id,
                        'invoice_number' => $schedule->invoice->invoice_number,
                        'invoice_type' => is_object($schedule->invoice->invoice_type) ? $schedule->invoice->invoice_type->value : $schedule->invoice->invoice_type,
                        'direction' => is_object($schedule->invoice->direction) ? $schedule->invoice->direction->value : $schedule->invoice->direction,
                        'installment_number' => $schedule->installment_number,
                        'due_date' => $schedule->due_date,
                        'amount' => (float) $schedule->amount,
                        'days_until_due' => now()->diffInDays($schedule->due_date, false),
                        'project_name' => $schedule->invoice->project?->name ?? 'Без проекта',
                        'counterparty' => $schedule->invoice->counterpartyOrganization?->name ?? $schedule->invoice->contractor?->name ?? 'Не указано',
                        'notes' => $schedule->notes,
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $schedules,
                'meta' => [
                    'period_days' => $days,
                    'total_count' => $schedules->count(),
                    'total_amount' => $schedules->sum('amount'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.schedules.upcoming.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить предстоящие платежи',
            ], 500);
        }
    }
    
    /**
     * Получить шаблоны графиков платежей
     * 
     * GET /api/v1/admin/payments/schedules/templates
     */
    public function templates(Request $request): JsonResponse
    {
        try {
            $templates = [
                [
                    'id' => 'equal_2',
                    'name' => 'Равными платежами (2 платежа)',
                    'description' => 'График из 2 равных платежей каждые 30 дней',
                    'config' => [
                        'schedule_type' => 'equal_installments',
                        'installments_count' => 2,
                        'interval_days' => 30,
                    ],
                ],
                [
                    'id' => 'equal_3',
                    'name' => 'Равными платежами (3 платежа)',
                    'description' => 'График из 3 равных платежей каждые 30 дней',
                    'config' => [
                        'schedule_type' => 'equal_installments',
                        'installments_count' => 3,
                        'interval_days' => 30,
                    ],
                ],
                [
                    'id' => 'equal_4',
                    'name' => 'Равными платежами (4 платежа)',
                    'description' => 'График из 4 равных платежей каждые 30 дней',
                    'config' => [
                        'schedule_type' => 'equal_installments',
                        'installments_count' => 4,
                        'interval_days' => 30,
                    ],
                ],
                [
                    'id' => 'advance_30',
                    'name' => 'Аванс 30%',
                    'description' => 'Аванс 30%, промежуточный 50%, финальный 20%',
                    'config' => [
                        'schedule_type' => 'percentage_based',
                        'percentages' => [30, 50, 20],
                        'interval_days' => 30,
                    ],
                ],
                [
                    'id' => 'advance_50',
                    'name' => 'Аванс 50%',
                    'description' => 'Аванс 50%, финальный 50%',
                    'config' => [
                        'schedule_type' => 'percentage_based',
                        'percentages' => [50, 50],
                        'interval_days' => 30,
                    ],
                ],
                [
                    'id' => 'advance_30_fact',
                    'name' => 'Аванс 30% + по факту',
                    'description' => 'Аванс 30%, остальное по актам выполненных работ',
                    'config' => [
                        'schedule_type' => 'advance_and_fact',
                        'advance_percentage' => 30,
                        'fact_based' => true,
                    ],
                ],
                [
                    'id' => 'monthly',
                    'name' => 'Ежемесячно равными платежами',
                    'description' => 'График ежемесячных равных платежей',
                    'config' => [
                        'schedule_type' => 'equal_installments',
                        'installments_count' => 12,
                        'interval_days' => 30,
                    ],
                ],
                [
                    'id' => 'quarterly',
                    'name' => 'Ежеквартально',
                    'description' => 'График поквартальных равных платежей',
                    'config' => [
                        'schedule_type' => 'equal_installments',
                        'installments_count' => 4,
                        'interval_days' => 90,
                    ],
                ],
            ];
            
            return response()->json([
                'success' => true,
                'data' => $templates,
            ]);
        } catch (\Exception $e) {
            Log::error('payments.schedules.templates.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить шаблоны',
            ], 500);
        }
    }
    
    /**
     * Получить просроченные платежи по графику
     * 
     * GET /api/v1/admin/payments/schedules/overdue
     */
    public function overdue(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $schedules = PaymentSchedule::with(['invoice', 'invoice.project', 'invoice.contractor'])
                ->whereHas('invoice', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId);
                })
                ->where('status', 'pending')
                ->where('due_date', '<', now())
                ->orderBy('due_date', 'asc')
                ->get()
                ->map(function ($schedule) {
                    return [
                        'id' => $schedule->id,
                        'invoice_id' => $schedule->invoice_id,
                        'invoice_number' => $schedule->invoice->invoice_number,
                        'invoice_type' => is_object($schedule->invoice->invoice_type) ? $schedule->invoice->invoice_type->value : $schedule->invoice->invoice_type,
                        'direction' => is_object($schedule->invoice->direction) ? $schedule->invoice->direction->value : $schedule->invoice->direction,
                        'installment_number' => $schedule->installment_number,
                        'due_date' => $schedule->due_date,
                        'amount' => (float) $schedule->amount,
                        'days_overdue' => now()->diffInDays($schedule->due_date),
                        'project_name' => $schedule->invoice->project?->name ?? 'Без проекта',
                        'counterparty' => $schedule->invoice->counterpartyOrganization?->name ?? $schedule->invoice->contractor?->name ?? 'Не указано',
                        'notes' => $schedule->notes,
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $schedules,
                'meta' => [
                    'total_count' => $schedules->count(),
                    'total_amount' => $schedules->sum('amount'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.schedules.overdue.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить просроченные платежи',
            ], 500);
        }
    }
}

