<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdvanceTransaction\CreateAdvanceTransactionRequest;
use App\Http\Requests\AdvanceTransaction\UpdateAdvanceTransactionRequest;
use App\Http\Requests\AdvanceTransaction\TransactionReportRequest;
use App\Http\Requests\AdvanceTransaction\TransactionApprovalRequest;
use App\Http\Resources\AdvanceTransaction\AdvanceTransactionResource;
use App\Http\Resources\AdvanceTransaction\AdvanceTransactionCollection;
use App\Models\AdvanceAccountTransaction;
use App\Services\AdvanceAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
     * Получить список транзакций с фильтрацией.
     *
     * @param Request $request
     * @return AdvanceTransactionCollection
     */
    public function index(Request $request)
    {
        $filters = $request->only([
            'user_id', 'organization_id', 'project_id', 'type', 
            'reporting_status', 'date_from', 'date_to'
        ]);

        $perPage = $request->input('per_page', 15);
        $transactions = $this->advanceService->getTransactions($filters, $perPage);

        return new AdvanceTransactionCollection($transactions);
    }

    /**
     * Получить детальную информацию о транзакции.
     *
     * @param AdvanceAccountTransaction $transaction
     * @return AdvanceTransactionResource
     */
    public function show(AdvanceAccountTransaction $transaction)
    {
        return new AdvanceTransactionResource($transaction);
    }

    /**
     * Создать новую транзакцию подотчетных средств.
     *
     * @param CreateAdvanceTransactionRequest $request
     * @return AdvanceTransactionResource
     */
    public function store(CreateAdvanceTransactionRequest $request)
    {
        $validatedData = $request->validated();
        $transaction = $this->advanceService->createTransaction($validatedData);

        return new AdvanceTransactionResource($transaction);
    }

    /**
     * Обновить транзакцию подотчетных средств.
     *
     * @param UpdateAdvanceTransactionRequest $request
     * @param AdvanceAccountTransaction $transaction
     * @return AdvanceTransactionResource
     */
    public function update(UpdateAdvanceTransactionRequest $request, AdvanceAccountTransaction $transaction)
    {
        $validatedData = $request->validated();
        $updatedTransaction = $this->advanceService->updateTransaction($transaction, $validatedData);

        return new AdvanceTransactionResource($updatedTransaction);
    }

    /**
     * Удалить транзакцию подотчетных средств.
     *
     * @param AdvanceAccountTransaction $transaction
     * @return Response
     */
    public function destroy(AdvanceAccountTransaction $transaction)
    {
        $this->advanceService->deleteTransaction($transaction);

        return response()->json(['message' => 'Транзакция успешно удалена'], 200);
    }

    /**
     * Отметить транзакцию как отчитанную.
     *
     * @param TransactionReportRequest $request
     * @param AdvanceAccountTransaction $transaction
     * @return AdvanceTransactionResource
     */
    public function report(TransactionReportRequest $request, AdvanceAccountTransaction $transaction)
    {
        $validatedData = $request->validated();
        $reportedTransaction = $this->advanceService->reportTransaction($transaction, $validatedData);

        return new AdvanceTransactionResource($reportedTransaction);
    }

    /**
     * Утвердить отчет по транзакции.
     *
     * @param TransactionApprovalRequest $request
     * @param AdvanceAccountTransaction $transaction
     * @return AdvanceTransactionResource
     */
    public function approve(TransactionApprovalRequest $request, AdvanceAccountTransaction $transaction)
    {
        $validatedData = $request->validated();
        $approvedTransaction = $this->advanceService->approveTransaction($transaction, $validatedData);

        return new AdvanceTransactionResource($approvedTransaction);
    }

    /**
     * Прикрепить файлы к транзакции.
     *
     * @param Request $request
     * @param AdvanceAccountTransaction $transaction
     * @return AdvanceTransactionResource
     */
    public function attachFiles(Request $request, AdvanceAccountTransaction $transaction)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'file|max:10240',
        ]);

        $transaction = $this->advanceService->attachFilesToTransaction($transaction, $request->file('files'));

        return new AdvanceTransactionResource($transaction);
    }

    /**
     * Открепить файл от транзакции.
     *
     * @param AdvanceAccountTransaction $transaction
     * @param int $fileId
     * @return AdvanceTransactionResource
     */
    public function detachFile(AdvanceAccountTransaction $transaction, $fileId)
    {
        $transaction = $this->advanceService->detachFileFromTransaction($transaction, $fileId);

        return new AdvanceTransactionResource($transaction);
    }
} 