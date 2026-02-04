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
use App\Http\Resources\Api\V1\Admin\AdvanceTransaction\AdvanceTransactionResource;
use App\Http\Resources\Api\V1\Admin\AdvanceTransaction\AdvanceTransactionCollection;
use App\Http\Responses\AdminResponse;

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
        // Берем ID организации из контекста авторизованного пользователя
        $organizationId = Auth::user()->current_organization_id;
        $search = $request->input('search');

        if (!$organizationId) {
            // Эта ситуация не должна возникать, если middleware отработал корректно
            return AdminResponse::error('Organization context not found for the user.');
        }

        // Используем сервис для получения пользователей
        $users = $this->advanceService->getAvailableUsers((int)$organizationId, $search);

        return AdminResponse::success($users);
    }

    /**
     * Получить список доступных проектов для транзакций подотчетных средств.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAvailableProjects(Request $request): JsonResponse
    {
        // Берем ID организации из контекста авторизованного пользователя
        $organizationId = Auth::user()->current_organization_id;
        $userId = $request->input('user_id');
        $search = $request->input('search');

        if (!$organizationId) {
             // Эта ситуация не должна возникать, если middleware отработал корректно
            return AdminResponse::error('Organization context not found for the user.');
        }

        // Используем сервис для получения проектов
        $projects = $this->advanceService->getAvailableProjects(
            (int)$organizationId, 
            $userId ? (int)$userId : null, 
            $search
        );

        return AdminResponse::success($projects);
    }

    /**
     * Получить статистику по транзакциям.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function stats(Request $request): JsonResponse
    {
        // Берем ID организации из контекста авторизованного пользователя
        $organizationId = Auth::user()->current_organization_id;

        if (!$organizationId) {
            return AdminResponse::error('Organization context not found for the user.');
        }

        try {
            $stats = $this->advanceService->getStatistics((int)$organizationId);
            return AdminResponse::success($stats);
        } catch (\Exception $e) {
            return AdminResponse::error(
                'Failed to get statistics: ' . $e->getMessage(),
                500
            );
        }
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

        // Всегда используем текущую организацию из контекста
        $filters['organization_id'] = Auth::user()->current_organization_id;

        $perPage = $request->input('per_page', 15);
        $transactions = $this->advanceService->getTransactions($filters, $perPage);

        return AdminResponse::success(new AdvanceTransactionCollection($transactions));
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
            // Возвращаем ошибку 404, если ресурс не найден в контексте
            return AdminResponse::error('Transaction not found or access denied', 404);
        }

        // Загружаем связанные данные
        $transaction->load(['user', 'project', 'createdBy', 'approvedBy']);

        // Возвращаем ресурс
        return AdminResponse::success(new AdvanceTransactionResource($transaction));
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
        $validatedData = $request->validated();

        try {
            // Явно добавляем ID организации из контекста пользователя
            $validatedData['organization_id'] = Auth::user()->current_organization_id;
            if (empty($validatedData['organization_id'])) {
                 // Эта ошибка не должна возникать при правильной работе middleware
                 throw new \Exception('Could not determine organization context for the current user.');
            }

            // Передаем данные в сервис
            $transaction = $this->advanceService->createTransaction($validatedData);
            
            // Загружаем связи перед возвратом через ресурс
            $transaction->load(['user', 'project', 'createdBy']); 

            // Возвращаем ресурс созданной транзакции
            return AdminResponse::success(
                new AdvanceTransactionResource($transaction),
                'Transaction created successfully',
                201
            );

        } catch (\Exception $e) {
            return AdminResponse::error(
                'Failed to create transaction: ' . $e->getMessage(),
                500
            );
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
            return AdminResponse::error('Transaction not found or access denied', 404);
        }

        // Валидация запроса происходит в UpdateAdvanceTransactionRequest

        try {
            $updatedTransaction = $this->advanceService->updateTransaction($transaction, $request->validated());
            
            return AdminResponse::success(
                new AdvanceTransactionResource($updatedTransaction),
                'Transaction updated successfully'
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                'Failed to update transaction: ' . $e->getMessage(),
                500
            );
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
            return AdminResponse::error('Transaction not found or access denied', 404);
        }

        try {
            $this->advanceService->deleteTransaction($transaction);
            
            return AdminResponse::success(null, 'Transaction deleted successfully');
        } catch (\Exception $e) {
            return AdminResponse::error(
                'Failed to delete transaction: ' . $e->getMessage(),
                500
            );
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
            return AdminResponse::error('Transaction not found or access denied', 404);
        }

        // Валидация запроса происходит в TransactionReportRequest

        try {
            $reportedTransaction = $this->advanceService->reportTransaction($transaction, $request->validated());
            
            return AdminResponse::success(
                new AdvanceTransactionResource($reportedTransaction),
                'Transaction reported successfully'
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                'Failed to report transaction: ' . $e->getMessage(),
                500
            );
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
            return AdminResponse::error('Transaction not found or access denied', 404);
        }

        // Валидация запроса происходит в TransactionApprovalRequest

        try {
            $approvedTransaction = $this->advanceService->approveTransaction($transaction, $request->validated());
            
            return AdminResponse::success(
                new AdvanceTransactionResource($approvedTransaction),
                'Transaction approved successfully'
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                'Failed to approve transaction: ' . $e->getMessage(),
                500
            );
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
            return AdminResponse::error('Transaction not found or access denied', 404);
        }

        // Валидация запроса
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'file|max:10240',
        ]);

        try {
            $transaction = $this->advanceService->attachFilesToTransaction($transaction, $request->file('files'));
            
            return AdminResponse::success(
                new AdvanceTransactionResource($transaction),
                'Files attached successfully'
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                'Failed to attach files: ' . $e->getMessage(),
                500
            );
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
            return AdminResponse::error('Transaction not found or access denied', 404);
        }

        try {
            $transaction = $this->advanceService->detachFileFromTransaction($transaction, $fileId);
            
            return AdminResponse::success(
                new AdvanceTransactionResource($transaction),
                'File detached successfully'
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                'Failed to detach file: ' . $e->getMessage(),
                500
            );
        }
    }
}
