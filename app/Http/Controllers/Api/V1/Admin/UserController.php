<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdvanceAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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
        $this->middleware('can:access-admin-panel');
    }

    /**
     * Получить баланс подотчетных средств пользователя.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function getAdvanceBalance(Request $request, User $user): JsonResponse
    {
        // Проверяем, что пользователь принадлежит текущей организации
        if (!$this->checkUserOrganization($user)) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or access denied'
            ], 404);
        }

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'current_balance' => (float)$user->current_balance,
                'total_issued' => (float)$user->total_issued,
                'total_reported' => (float)$user->total_reported,
                'has_overdue_balance' => (bool)$user->has_overdue_balance,
                'last_transaction_at' => $user->last_transaction_at ? $user->last_transaction_at->format('Y-m-d H:i:s') : null
            ]
        ]);
    }

    /**
     * Получить историю транзакций подотчетных средств для пользователя.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function getAdvanceTransactions(Request $request, User $user): JsonResponse
    {
        // Проверяем, что пользователь принадлежит текущей организации
        if (!$this->checkUserOrganization($user)) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or access denied'
            ], 404);
        }

        $filters = $request->only(['date_from', 'date_to', 'type', 'reporting_status']);
        $filters['user_id'] = $user->id;
        $filters['organization_id'] = Auth::user()->current_organization_id;

        $perPage = $request->input('per_page', 15);
        $transactions = $this->advanceService->getTransactions($filters, $perPage);

        return response()->json($transactions);
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
        // Проверяем, что пользователь принадлежит текущей организации
        if (!$this->checkUserOrganization($user)) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or access denied'
            ], 404);
        }

        // Валидация запроса
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'project_id' => 'nullable|exists:projects,id',
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
        ]);

        try {
            $transactionData = $request->all();
            $transactionData['user_id'] = $user->id;
            $transactionData['organization_id'] = Auth::user()->current_organization_id;
            $transactionData['type'] = 'issue';

            $transaction = $this->advanceService->createTransaction($transactionData);
            
            return response()->json([
                'success' => true,
                'message' => 'Funds issued successfully',
                'data' => $transaction
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue funds: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Оформить возврат подотчетных средств от пользователя.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function returnFunds(Request $request, User $user): JsonResponse
    {
        // Проверяем, что пользователь принадлежит текущей организации
        if (!$this->checkUserOrganization($user)) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or access denied'
            ], 404);
        }

        // Валидация запроса
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'project_id' => 'nullable|exists:projects,id',
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
        ]);

        try {
            $transactionData = $request->all();
            $transactionData['user_id'] = $user->id;
            $transactionData['organization_id'] = Auth::user()->current_organization_id;
            $transactionData['type'] = 'return';

            // Проверяем, что у пользователя достаточно средств для возврата
            if ((float)$user->current_balance < (float)$request->input('amount')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient funds for return'
                ], 400);
            }

            $transaction = $this->advanceService->createTransaction($transactionData);
            
            return response()->json([
                'success' => true,
                'message' => 'Funds returned successfully',
                'data' => $transaction
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to return funds: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить, что пользователь принадлежит текущей организации.
     *
     * @param User $user
     * @return bool
     */
    protected function checkUserOrganization(User $user): bool
    {
        $currentOrgId = Auth::user()->current_organization_id;
        
        return $user->organizations()->where('organization_id', $currentOrgId)->exists();
    }
} 