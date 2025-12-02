<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    /**
     * Получить список транзакций
     * 
     * GET /api/v1/admin/payments/transactions
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $query = PaymentTransaction::where('organization_id', $organizationId)
                ->with(['paymentDocument']);
            
            // Фильтры
            if ($request->has('payment_document_id')) {
                $query->where('payment_document_id', $request->input('payment_document_id'));
            }
            
            // Поддержка старого параметра для обратной совместимости (если нужно)
            if ($request->has('invoice_id')) {
                $query->where('payment_document_id', $request->input('invoice_id'));
            }
            
            if ($request->has('project_id')) {
                $query->where('project_id', $request->input('project_id'));
            }
            
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }
            
            if ($request->has('payment_method')) {
                $query->where('payment_method', $request->input('payment_method'));
            }
            
            if ($request->has('date_from')) {
                $query->where('transaction_date', '>=', $request->input('date_from'));
            }
            
            if ($request->has('date_to')) {
                $query->where('transaction_date', '<=', $request->input('date_to'));
            }
            
            $perPage = min($request->input('per_page', 15), 100);
            $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $transactions->items(),
                'meta' => [
                    'total' => $transactions->total(),
                    'per_page' => $transactions->perPage(),
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.transactions.index.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить транзакции',
            ], 500);
        }
    }
    
    /**
     * Получить транзакцию
     * 
     * GET /api/v1/admin/payments/transactions/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $transaction = PaymentTransaction::where('organization_id', $organizationId)
                ->with(['paymentDocument', 'createdByUser', 'approvedByUser'])
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $transaction,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Транзакция не найдена',
            ], 404);
        }
    }
    
    /**
     * Утвердить транзакцию
     * 
     * POST /api/v1/admin/payments/transactions/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $user = $request->user();
            
            $transaction = PaymentTransaction::where('organization_id', $organizationId)
                ->findOrFail($id);
            
            if ($transaction->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'error' => 'Только транзакции в статусе "pending" могут быть утверждены',
                ], 422);
            }
            
            $transaction->update([
                'status' => 'completed',
                'approved_by_user_id' => $user->id,
            ]);
            
            if ($request->has('notes')) {
                $transaction->notes = ($transaction->notes ? $transaction->notes . "\n" : '') . $request->input('notes');
                $transaction->save();
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Транзакция успешно утверждена',
                'data' => $transaction,
            ]);
        } catch (\Exception $e) {
            Log::error('payments.transaction.approve.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось утвердить транзакцию',
            ], 500);
        }
    }
    
    /**
     * Отклонить транзакцию
     * 
     * POST /api/v1/admin/payments/transactions/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
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
            
            $transaction = PaymentTransaction::where('organization_id', $organizationId)
                ->findOrFail($id);
            
            if ($transaction->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'error' => 'Только транзакции в статусе "pending" могут быть отклонены',
                ], 422);
            }
            
            $transaction->update([
                'status' => 'failed',
            ]);
            
            $transaction->notes = ($transaction->notes ? $transaction->notes . "\n" : '') . 
                "Причина отклонения: " . $request->input('reason');
            $transaction->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Транзакция отклонена',
                'data' => $transaction,
            ]);
        } catch (\Exception $e) {
            Log::error('payments.transaction.reject.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось отклонить транзакцию',
            ], 500);
        }
    }
    
    /**
     * Возврат платежа
     * 
     * POST /api/v1/admin/payments/transactions/{id}/refund
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0',
            'reason' => 'required|string|max:500',
            'refund_date' => 'nullable|date',
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
            $user = $request->user();
            
            $originalTransaction = PaymentTransaction::where('organization_id', $organizationId)
                ->findOrFail($id);
            
            if ($originalTransaction->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'error' => 'Возврат возможен только для завершённых транзакций',
                ], 422);
            }
            
            $refundAmount = $request->input('amount', $originalTransaction->amount);
            
            if ($refundAmount > $originalTransaction->amount) {
                return response()->json([
                    'success' => false,
                    'error' => 'Сумма возврата не может превышать сумму оригинальной транзакции',
                ], 422);
            }
            
            // Создать транзакцию возврата
            $refundTransaction = PaymentTransaction::create([
                'payment_document_id' => $originalTransaction->payment_document_id,
                'organization_id' => $organizationId,
                'project_id' => $originalTransaction->project_id,
                'amount' => -$refundAmount,
                'currency' => $originalTransaction->currency,
                'payment_method' => $originalTransaction->payment_method,
                'transaction_date' => $request->input('refund_date', now()->toDateString()),
                'status' => 'completed',
                'notes' => 'Возврат платежа. ' . $request->input('reason'),
                'created_by_user_id' => $user->id,
                'approved_by_user_id' => $user->id,
                'metadata' => [
                    'original_transaction_id' => $originalTransaction->id,
                    'refund_reason' => $request->input('reason'),
                ],
            ]);
            
            // Обновить оригинальную транзакцию
            $originalTransaction->update([
                'status' => 'refunded',
            ]);
            
            // Обновить документ
            $document = $originalTransaction->paymentDocument;
            if ($document) {
                $document->paid_amount -= $refundAmount;
                $document->remaining_amount += $refundAmount;
                
                // Обновить статус через сервис
                $paymentDocumentService = app(\App\BusinessModules\Core\Payments\Services\PaymentDocumentService::class);
                $paymentDocumentService->updateStatus($document);
                
                $document->save();
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Платёж успешно возвращён',
                'data' => [
                    'original_transaction' => $originalTransaction,
                    'refund_transaction' => $refundTransaction,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('payments.transaction.refund.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось выполнить возврат',
            ], 500);
        }
    }
}

