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
use function trans_message;

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
            return AdminResponse::success($stats, trans_message('advance_account.stats_loaded'));
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('advance_account.transaction_failed'), // Или создать отдельный ключ stats_failed
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
            return AdminResponse::error(trans_message('advance_account.transaction_not_found'), 404);
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
                trans_message('advance_account.transaction_created'),
                201
            );

        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('advance_account.transaction_failed') . ': ' . $e->getMessage(),
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
                trans_message('advance_account.transaction_updated')
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('advance_account.transaction_failed') . ': ' . $e->getMessage(),
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

        // Можно удалять только транзакции со статусом "pending" (в ожидании отчета)
        if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_PENDING) {
            return AdminResponse::error(trans_message('advance_account.cannot_delete_reported'), 400);
        }

        try {
            $this->advanceService->deleteTransaction($transaction);
            
            return AdminResponse::success(null, trans_message('advance_account.transaction_deleted'));
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('advance_account.transaction_failed') . ': ' . $e->getMessage(),
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

        // Проверяем статус транзакции
        if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_PENDING) {
            return AdminResponse::error(trans_message('advance_account.must_be_pending_to_report'), 400);
        }

        // Валидация запроса происходит в TransactionReportRequest

        try {
            $reportedTransaction = $this->advanceService->reportTransaction($transaction, $request->validated());
            
            return AdminResponse::success(
                new AdvanceTransactionResource($reportedTransaction),
                trans_message('advance_account.transaction_reported')
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('advance_account.transaction_failed') . ': ' . $e->getMessage(),
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

        // Проверяем статус транзакции
        if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_REPORTED) {
            return AdminResponse::error(trans_message('advance_account.must_be_reported_to_approve'), 400);
        }

        // Валидация запроса происходит в TransactionApprovalRequest

        try {
            $approvedTransaction = $this->advanceService->approveTransaction($transaction, $request->validated());
            
            return AdminResponse::success(
                new AdvanceTransactionResource($approvedTransaction),
                trans_message('advance_account.transaction_approved')
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('advance_account.transaction_failed') . ': ' . $e->getMessage(),
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

        // Если транзакция уже утверждена, запрещаем добавление файлов
        if ($transaction->reporting_status === AdvanceAccountTransaction::STATUS_APPROVED) {
            return AdminResponse::error(trans_message('advance_account.cannot_add_files_approved'), 400);
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
                trans_message('advance_account.files_attached')
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('advance_account.transaction_failed') . ': ' . $e->getMessage(),
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

        // Если транзакция уже утверждена, запрещаем удаление файлов
        if ($transaction->reporting_status === AdvanceAccountTransaction::STATUS_APPROVED) {
            return AdminResponse::error(trans_message('advance_account.cannot_delete_files_approved'), 400);
        }

        try {
            $transaction = $this->advanceService->detachFileFromTransaction($transaction, $fileId);
            
            return AdminResponse::success(
                new AdvanceTransactionResource($transaction),
                trans_message('advance_account.file_detached')
            );
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('advance_account.transaction_failed') . ': ' . $e->getMessage(),
                500
            );
        }
    }
}
