<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ReconciliationController extends Controller
{
    /**
     * Создать акт сверки
     * 
     * POST /api/v1/admin/payments/reconciliation
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'counterparty_organization_id' => 'required|integer|exists:organizations,id',
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'include_paid' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
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
            $counterpartyOrganizationId = $request->input('counterparty_organization_id');
            
            $counterparty = Organization::findOrFail($counterpartyOrganizationId);
            
            // Получить счета за период
            $query = Invoice::where('organization_id', $organizationId)
                ->where('counterparty_organization_id', $counterpartyOrganizationId)
                ->whereBetween('invoice_date', [
                    $request->input('period_from'),
                    $request->input('period_to'),
                ]);
            
            if (!$request->input('include_paid', false)) {
                $query->whereIn('status', ['issued', 'partially_paid', 'overdue']);
            }
            
            $invoices = $query->get();
            
            // Рассчитать балансы
            $ourDebts = $invoices->where('direction', 'outgoing')->sum('remaining_amount');
            $theirDebts = $invoices->where('direction', 'incoming')->sum('remaining_amount');
            $balance = $theirDebts - $ourDebts;
            
            // Генерация номера акта сверки
            $reconciliationNumber = 'RECON-' . now()->format('Y') . '-' . str_pad(
                Invoice::whereYear('created_at', now()->year)->count() + 1,
                3,
                '0',
                STR_PAD_LEFT
            );
            
            $data = [
                'reconciliation_number' => $reconciliationNumber,
                'counterparty' => $counterparty->name,
                'period_from' => $request->input('period_from'),
                'period_to' => $request->input('period_to'),
                'our_balance' => (string) -$ourDebts,
                'their_balance' => (string) $theirDebts,
                'invoices_count' => $invoices->count(),
                'transactions_count' => $invoices->sum(function ($invoice) {
                    return $invoice->transactions()->count();
                }),
                'document_url' => null, // TODO: генерация PDF
            ];
            
            Log::info('payments.reconciliation.created', $data);
            
            return response()->json([
                'success' => true,
                'message' => 'Акт сверки создан',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('payments.reconciliation.store.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать акт сверки',
            ], 500);
        }
    }
}

