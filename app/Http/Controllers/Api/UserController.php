<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdvanceTransaction\AdvanceTransactionCollection;
use App\Http\Resources\AdvanceTransaction\AdvanceTransactionResource;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use App\Models\AdvanceAccountTransaction;
use App\Services\AdvanceAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    protected $advanceService;

    /**
     * Конструктор контроллера.
     * 
     * @param AdvanceAccountService $advanceService
     */
    public function __construct(AdvanceAccountService $advanceService)
    {
        $this->advanceService = $advanceService;
    }

    /**
     * Получить данные о балансе подотчетных средств пользователя.
     *
     * @param User $user
     * @return JsonResponse
     */
    public function getAdvanceBalance(User $user): JsonResponse
    {
        $data = [
            'user_id' => $user->id,
            'name' => $user->name,
            'current_balance' => (float) $user->current_balance,
            'total_issued' => (float) $user->total_issued,
            'total_reported' => (float) $user->total_reported,
            'has_overdue_balance' => (bool) $user->has_overdue_balance,
            'last_transaction_at' => $user->last_transaction_at ? $user->last_transaction_at->format('Y-m-d H:i:s') : null,
            'employee_id' => $user->employee_id,
            'external_code' => $user->external_code,
        ];

        return response()->json($data);
    }

    /**
     * Получить список транзакций подотчетных средств пользователя.
     *
     * @param Request $request
     * @param User $user
     * @return AdvanceTransactionCollection
     */
    public function getAdvanceTransactions(Request $request, User $user)
    {
        $filters = $request->only(['organization_id', 'project_id', 'type', 'reporting_status', 'date_from', 'date_to']);
        $filters['user_id'] = $user->id;
        
        $perPage = $request->input('per_page', 15);
        $transactions = $this->advanceService->getTransactions($filters, $perPage);
        
        return new AdvanceTransactionCollection($transactions);
    }

    /**
     * Выдать подотчетные средства пользователю.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function issueFunds(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'project_id' => 'nullable|exists:projects,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'external_code' => 'nullable|string|max:100',
            'accounting_data' => 'nullable|array',
        ]);

        $data = $request->all();
        $data['user_id'] = $user->id;
        $data['type'] = AdvanceAccountTransaction::TYPE_ISSUE;

        $transaction = $this->advanceService->createTransaction($data);

        return response()->json([
            'message' => 'Средства успешно выданы пользователю',
            'transaction' => new AdvanceTransactionResource($transaction),
        ]);
    }

    /**
     * Оформить возврат подотчетных средств пользователем.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function returnFunds(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'project_id' => 'nullable|exists:projects,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'external_code' => 'nullable|string|max:100',
            'accounting_data' => 'nullable|array',
        ]);

        // Проверяем, что у пользователя достаточно средств
        if ($user->current_balance < $request->amount) {
            return response()->json([
                'message' => 'Недостаточно средств для возврата',
                'current_balance' => $user->current_balance,
                'requested_amount' => $request->amount,
            ], 422);
        }

        $data = $request->all();
        $data['user_id'] = $user->id;
        $data['type'] = AdvanceAccountTransaction::TYPE_RETURN;

        $transaction = $this->advanceService->createTransaction($data);

        return response()->json([
            'message' => 'Возврат средств успешно оформлен',
            'transaction' => new AdvanceTransactionResource($transaction),
        ]);
    }
} 