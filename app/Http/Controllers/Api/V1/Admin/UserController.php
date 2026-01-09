<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdvanceAccountService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Response;

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
        // Авторизация настроена на уровне роутов через middleware стек
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
            return AdminResponse::error(trans_message('user.advance_balance_error'), Response::HTTP_NOT_FOUND);
        }

        return AdminResponse::success([
            'user_id' => $user->id,
            'name' => $user->name,
            'current_balance' => (float)$user->current_balance,
            'total_issued' => (float)$user->total_issued,
            'total_reported' => (float)$user->total_reported,
            'has_overdue_balance' => (bool)$user->has_overdue_balance,
            'last_transaction_at' => $user->last_transaction_at ? $user->last_transaction_at->format('Y-m-d H:i:s') : null
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
            return AdminResponse::error(trans_message('user.advance_transactions_error'), Response::HTTP_NOT_FOUND);
        }

        $filters = $request->only(['date_from', 'date_to', 'type', 'reporting_status']);
        $filters['user_id'] = $user->id;
        $filters['organization_id'] = Auth::user()->current_organization_id;

        $perPage = $request->input('per_page', 15);
        $transactions = $this->advanceService->getTransactions($filters, $perPage);

        return AdminResponse::success($transactions);
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
            return AdminResponse::error(trans_message('user.advance_balance_error'), Response::HTTP_NOT_FOUND);
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
            
            return AdminResponse::success($transaction, trans_message('user.issue_funds_success'), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('user.issue_funds_error') . ': ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
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
            return AdminResponse::error(trans_message('user.advance_balance_error'), Response::HTTP_NOT_FOUND);
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
                return AdminResponse::error(trans_message('user.insufficient_funds'), Response::HTTP_BAD_REQUEST);
            }

            $transaction = $this->advanceService->createTransaction($transactionData);
            
            return AdminResponse::success($transaction, trans_message('user.return_funds_success'), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('user.return_funds_error') . ': ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
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