<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdvanceAccountTransaction;
use App\Models\User;
use App\Models\Project;
use App\Services\AdvanceAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Api\V1\Admin\AdvanceTransaction\CreateAdvanceTransactionRequest;
use App\Http\Requests\Api\V1\Admin\AdvanceTransaction\UpdateAdvanceTransactionRequest;
use App\Http\Requests\Api\V1\Admin\AdvanceTransaction\TransactionReportRequest;
use App\Http\Requests\Api\V1\Admin\AdvanceTransaction\TransactionApprovalRequest;

class AdvanceAccountTransactionController extends Controller
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
     * Получить список доступных пользователей для транзакций подотчетных средств.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAvailableUsers(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id');
        $search = $request->input('search');

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization ID is required'
            ], 400);
        }

        // Используем сервис для получения пользователей
        $users = $this->advanceService->getAvailableUsers((int)$organizationId, $search);

        return response()->json([
            'data' => $users
        ]);
    }

    /**
     * Получить список доступных проектов для транзакций подотчетных средств.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAvailableProjects(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id');
        $userId = $request->input('user_id');
        $search = $request->input('search');

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization ID is required'
            ], 400);
        }

        // Используем сервис для получения проектов
        $projects = $this->advanceService->getAvailableProjects(
            (int)$organizationId, 
            $userId ? (int)$userId : null, 
            $search
        );

        return response()->json([
            'data' => $projects
        ]);
    }

    /**
     * Получить список транзакций с фильтрацией.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'user_id', 'organization_id', 'project_id', 'type', 
            'reporting_status', 'date_from', 'date_to'
        ]);

        $perPage = $request->input('per_page', 15);
        $transactions = $this->advanceService->getTransactions($filters, $perPage);

        return response()->json($transactions);
    }

    /**
     * Получить детальную информацию о транзакции.
     *
     * @param Request $request
     * @param AdvanceAccountTransaction $transaction
     * @return JsonResponse
     */
    public function show(Request $request, AdvanceAccountTransaction $transaction): JsonResponse
    {
        // Проверяем, что транзакция принадлежит организации пользователя
        if ($transaction->organization_id !== Auth::user()->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or access denied'
            ], 404);
        }

        // Загружаем связанные данные
        $transaction->load(['user', 'project', 'createdBy', 'approvedBy']);

        // Получаем прикрепленные файлы
        $attachments = $transaction->getAttachments();

        // Добавляем прикрепленные файлы к ответу
        $data = $transaction->toArray();
        $data['attachments'] = $attachments;

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * Создать новую транзакцию подотчетных средств.
     *
     * @param CreateAdvanceTransactionRequest $request
     * @return JsonResponse
     */
    public function store(CreateAdvanceTransactionRequest $request): JsonResponse
    {
        // Валидация запроса происходит в CreateAdvanceTransactionRequest

        try {
            $transaction = $this->advanceService->createTransaction($request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Transaction created successfully',
                'data' => $transaction
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить транзакцию подотчетных средств.
     *
     * @param UpdateAdvanceTransactionRequest $request
     * @param AdvanceAccountTransaction $transaction
     * @return JsonResponse
     */
    public function update(UpdateAdvanceTransactionRequest $request, AdvanceAccountTransaction $transaction): JsonResponse
    {
        // Проверяем, что транзакция принадлежит организации пользователя
        if ($transaction->organization_id !== Auth::user()->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or access denied'
            ], 404);
        }

        // Валидация запроса происходит в UpdateAdvanceTransactionRequest

        try {
            $updatedTransaction = $this->advanceService->updateTransaction($transaction, $request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Transaction updated successfully',
                'data' => $updatedTransaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить транзакцию подотчетных средств.
     *
     * @param AdvanceAccountTransaction $transaction
     * @return JsonResponse
     */
    public function destroy(AdvanceAccountTransaction $transaction): JsonResponse
    {
        // Проверяем, что транзакция принадлежит организации пользователя
        if ($transaction->organization_id !== Auth::user()->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or access denied'
            ], 404);
        }

        try {
            $this->advanceService->deleteTransaction($transaction);
            
            return response()->json([
                'success' => true,
                'message' => 'Transaction deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Отметить транзакцию как отчитанную.
     *
     * @param TransactionReportRequest $request
     * @param AdvanceAccountTransaction $transaction
     * @return JsonResponse
     */
    public function report(TransactionReportRequest $request, AdvanceAccountTransaction $transaction): JsonResponse
    {
        // Проверяем, что транзакция принадлежит организации пользователя
        if ($transaction->organization_id !== Auth::user()->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or access denied'
            ], 404);
        }

        // Валидация запроса происходит в TransactionReportRequest

        try {
            $reportedTransaction = $this->advanceService->reportTransaction($transaction, $request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Transaction reported successfully',
                'data' => $reportedTransaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to report transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Утвердить отчет по транзакции.
     *
     * @param TransactionApprovalRequest $request
     * @param AdvanceAccountTransaction $transaction
     * @return JsonResponse
     */
    public function approve(TransactionApprovalRequest $request, AdvanceAccountTransaction $transaction): JsonResponse
    {
        // Проверяем, что транзакция принадлежит организации пользователя
        if ($transaction->organization_id !== Auth::user()->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or access denied'
            ], 404);
        }

        // Валидация запроса происходит в TransactionApprovalRequest

        try {
            $approvedTransaction = $this->advanceService->approveTransaction($transaction, $request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Transaction approved successfully',
                'data' => $approvedTransaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Прикрепить файлы к транзакции.
     *
     * @param Request $request
     * @param AdvanceAccountTransaction $transaction
     * @return JsonResponse
     */
    public function attachFiles(Request $request, AdvanceAccountTransaction $transaction): JsonResponse
    {
        // Проверяем, что транзакция принадлежит организации пользователя
        if ($transaction->organization_id !== Auth::user()->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or access denied'
            ], 404);
        }

        // Валидация запроса
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'file|max:10240',
        ]);

        try {
            $transaction = $this->advanceService->attachFilesToTransaction($transaction, $request->file('files'));
            
            return response()->json([
                'success' => true,
                'message' => 'Files attached successfully',
                'data' => $transaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to attach files: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Открепить файл от транзакции.
     *
     * @param AdvanceAccountTransaction $transaction
     * @param int $fileId
     * @return JsonResponse
     */
    public function detachFile(AdvanceAccountTransaction $transaction, $fileId): JsonResponse
    {
        // Проверяем, что транзакция принадлежит организации пользователя
        if ($transaction->organization_id !== Auth::user()->current_organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or access denied'
            ], 404);
        }

        try {
            $transaction = $this->advanceService->detachFileFromTransaction($transaction, $fileId);
            
            return response()->json([
                'success' => true,
                'message' => 'File detached successfully',
                'data' => $transaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to detach file: ' . $e->getMessage()
            ], 500);
        }
    }
} 