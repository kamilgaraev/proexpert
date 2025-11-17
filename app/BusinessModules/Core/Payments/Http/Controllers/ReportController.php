<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Получить финансовый отчёт
     * 
     * GET /api/v1/admin/payments/reports/financial
     */
    public function financial(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'project_id' => 'nullable|integer|exists:projects,id',
            'report_type' => 'nullable|in:summary,detailed,by_project,by_counterparty',
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
            $periodFrom = $request->input('period_from');
            $periodTo = $request->input('period_to');
            
            $query = Invoice::where('organization_id', $organizationId)
                ->whereBetween('invoice_date', [$periodFrom, $periodTo]);
            
            if ($request->has('project_id')) {
                $query->where('project_id', $request->input('project_id'));
            }
            
            $invoices = $query->get();
            
            // Общая статистика
            $summary = [
                'total_invoiced' => (string) $invoices->sum('total_amount'),
                'total_paid' => (string) $invoices->sum('paid_amount'),
                'total_outstanding' => (string) $invoices->sum('remaining_amount'),
                'invoices_count' => $invoices->count(),
                'transactions_count' => PaymentTransaction::where('organization_id', $organizationId)
                    ->whereBetween('transaction_date', [$periodFrom, $periodTo])
                    ->count(),
            ];
            
            // По статусам
            $byStatus = $invoices->groupBy('status')->map(function ($group) {
                return (string) $group->sum('remaining_amount');
            })->toArray();
            
            // По направлениям
            $byDirection = [
                'incoming' => (string) $invoices->where('direction', 'incoming')->sum('total_amount'),
                'outgoing' => (string) $invoices->where('direction', 'outgoing')->sum('total_amount'),
            ];
            
            // По проектам
            $byProject = $invoices->groupBy('project_id')->map(function ($group, $projectId) {
                $project = $projectId ? Project::find($projectId) : null;
                return [
                    'project_id' => $projectId,
                    'project_name' => $project?->name ?? 'Без проекта',
                    'invoiced' => (string) $group->sum('total_amount'),
                    'paid' => (string) $group->sum('paid_amount'),
                    'outstanding' => (string) $group->sum('remaining_amount'),
                ];
            })->values()->toArray();
            
            // Топ должников
            $topDebtors = Invoice::where('organization_id', $organizationId)
                ->where('direction', 'incoming')
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->with('counterpartyOrganization')
                ->get()
                ->groupBy('counterparty_organization_id')
                ->map(function ($group) {
                    $org = $group->first()->counterpartyOrganization;
                    return [
                        'organization_id' => $org->id,
                        'organization_name' => $org->name,
                        'debt_amount' => (string) $group->sum('remaining_amount'),
                        'overdue_invoices_count' => $group->where('status', 'overdue')->count(),
                    ];
                })
                ->sortByDesc('debt_amount')
                ->take(10)
                ->values()
                ->toArray();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'from' => $periodFrom,
                        'to' => $periodTo,
                    ],
                    'summary' => $summary,
                    'by_status' => $byStatus,
                    'by_direction' => $byDirection,
                    'by_project' => $byProject,
                    'top_debtors' => $topDebtors,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.reports.financial.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось сформировать отчёт',
            ], 500);
        }
    }
    
    /**
     * Экспорт отчёта
     * 
     * POST /api/v1/admin/payments/reports/export
     */
    public function export(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'report_type' => 'required|string',
            'format' => 'required|in:excel,pdf',
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'project_id' => 'nullable|integer|exists:projects,id',
            'filters' => 'nullable|array',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            // TODO: Реализовать экспорт в Excel/PDF
            
            $fileName = sprintf(
                '%s_%s_%s.%s',
                $request->input('report_type'),
                $request->input('period_from'),
                $request->input('period_to'),
                $request->input('format') === 'excel' ? 'xlsx' : 'pdf'
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Отчёт сформирован',
                'data' => [
                    'file_url' => '/storage/reports/' . $fileName,
                    'file_name' => $fileName,
                    'file_size' => 0, // TODO: реальный размер файла
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.reports.export.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось экспортировать отчёт',
            ], 500);
        }
    }
}

