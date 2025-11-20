<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Http\Requests\PayInvoiceRequest;
use App\BusinessModules\Core\Payments\Http\Requests\StoreInvoiceRequest;
use App\BusinessModules\Core\Payments\Http\Requests\UpdateInvoiceRequest;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Services\InvoiceService;
use App\BusinessModules\Core\Payments\Services\PaymentAccessControl;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionService;
use App\BusinessModules\Core\Payments\Services\LegacyPaymentAdapter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly PaymentTransactionService $transactionService,
        private readonly PaymentAccessControl $accessControl,
        private readonly LegacyPaymentAdapter $legacyAdapter,
    ) {}

    /**
     * Список счетов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            $projectId = $request->input('project_id');
            
            $query = Invoice::query();
            
            // Применить контроль доступа
            $query = $this->accessControl->applyAccessScope($query, $orgId);
            
            // Фильтры
            if ($projectId) {
                $query->where('project_id', $projectId);
            }
            
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            if ($direction = $request->input('direction')) {
                $query->where('direction', $direction);
            }
            
            if ($contractId = $request->input('contract_id')) {
                $query->where('invoiceable_type', 'App\\Models\\Contract')
                      ->where('invoiceable_id', $contractId);
            }
            
            // Пагинация
            $perPage = min($request->input('per_page', 15), 100);
            $invoices = $query->with(['project', 'organization', 'counterpartyOrganization', 'contractor'])
                ->orderBy('invoice_date', 'desc')
                ->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $invoices->items(),
                'meta' => [
                    'total' => $invoices->total(),
                    'per_page' => $invoices->perPage(),
                    'current_page' => $invoices->currentPage(),
                    'last_page' => $invoices->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.index.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить список счетов',
            ], 500);
        }
    }

    /**
     * Просмотр счёта
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            
            $invoice = Invoice::with([
                'project',
                'organization',
                'counterpartyOrganization',
                'contractor',
                'invoiceable',
                'transactions',
                'schedules',
            ])->findOrFail($id);
            
            // Проверка доступа
            if (!$this->accessControl->canAccessInvoice($orgId, $invoice)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Нет доступа к данному счёту',
                ], 403);
            }
            
            return response()->json([
                'success' => true,
                'data' => $invoice,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Счёт не найден',
            ], 404);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить счёт',
            ], 500);
        }
    }

    /**
     * Создать счёт
     */
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        try {
            $invoice = $this->invoiceService->createInvoice($request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Счёт успешно создан',
                'data' => $invoice->load(['project', 'organization']),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.store.error', [
                'data' => $request->validated(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать счёт',
            ], 500);
        }
    }

    /**
     * Обновить счёт
     */
    public function update(UpdateInvoiceRequest $request, int $id): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            $invoice = Invoice::findOrFail($id);
            
            // Проверка прав
            if (!$this->accessControl->canUpdateInvoice($orgId, $invoice)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Нет прав на обновление счёта',
                ], 403);
            }
            
            $updated = $this->invoiceService->updateInvoice($invoice, $request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Счёт успешно обновлён',
                'data' => $updated,
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить счёт',
            ], 500);
        }
    }

    /**
     * Удалить счёт
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            $invoice = Invoice::findOrFail($id);
            
            if (!$this->accessControl->canDeleteInvoice($orgId, $invoice)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Нет прав на удаление счёта',
                ], 403);
            }
            
            $invoice->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Счёт успешно удалён',
            ]);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить счёт',
            ], 500);
        }
    }

    /**
     * Выставить счёт (draft -> issued)
     */
    public function issue(Request $request, int $id): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            $invoice = Invoice::findOrFail($id);
            
            if (!$this->accessControl->canUpdateInvoice($orgId, $invoice)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Нет прав на выставление счёта',
                ], 403);
            }
            
            $this->invoiceService->issueInvoice($invoice);
            
            return response()->json([
                'success' => true,
                'message' => 'Счёт успешно выставлен',
                'data' => $invoice->fresh(),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.issue.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось выставить счёт',
            ], 500);
        }
    }

    /**
     * Оплатить счёт
     */
    public function pay(PayInvoiceRequest $request, int $id): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            $invoice = Invoice::findOrFail($id);
            
            if (!$this->accessControl->canAccessInvoice($orgId, $invoice)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Нет доступа к данному счёту',
                ], 403);
            }
            
            if (!$invoice->canBePaid()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Счёт не может быть оплачен в текущем статусе',
                ], 422);
            }
            
            $transaction = $this->transactionService->registerPayment($invoice, $request->validated());
            
            return response()->json([
                'success' => true,
                'message' => 'Платёж успешно зарегистрирован',
                'data' => [
                    'transaction' => $transaction,
                    'invoice' => $invoice->fresh(),
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.pay.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось зарегистрировать платёж',
            ], 500);
        }
    }

    /**
     * Отменить счёт
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $orgId = $request->attributes->get('current_organization_id');
            $invoice = Invoice::findOrFail($id);
            
            if (!$this->accessControl->canUpdateInvoice($orgId, $invoice)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Нет прав на отмену счёта',
                ], 403);
            }
            
            $this->invoiceService->cancelInvoice($invoice, $request->input('reason'));
            
            return response()->json([
                'success' => true,
                'message' => 'Счёт успешно отменён',
                'data' => $invoice->fresh(),
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.cancel.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось отменить счёт',
            ], 500);
        }
    }

    /**
     * Отправить счёт на утверждение
     * 
     * @group Payments - Invoices
     * @authenticated
     */
    public function submitForApproval(Request $request, int $id): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            
            $query = Invoice::query();
            $query = $this->accessControl->applyAccessScope($query, $orgId);
            $invoice = $query->findOrFail($id);
            
            // Проверка прав
            if (!$this->accessControl->canUpdateInvoice($orgId, $invoice)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Нет прав на отправку счёта на утверждение',
                ], 403);
            }

            // Отправляем на утверждение через адаптер
            $paymentDocument = $this->legacyAdapter->submitInvoiceForApproval($invoice);
            
            return response()->json([
                'success' => true,
                'message' => 'Счёт отправлен на утверждение',
                'data' => [
                    'invoice' => $invoice->fresh(),
                    'payment_document' => [
                        'id' => $paymentDocument->id,
                        'document_number' => $paymentDocument->document_number,
                        'status' => $paymentDocument->status->value,
                        'status_label' => $paymentDocument->status->label(),
                    ],
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.submit_for_approval.error', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось отправить счёт на утверждение',
            ], 500);
        }
    }

    /**
     * Получить статус утверждения счёта
     * 
     * @group Payments - Invoices
     * @authenticated
     */
    public function getApprovalStatus(Request $request, int $id): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            
            $query = Invoice::query();
            $query = $this->accessControl->applyAccessScope($query, $orgId);
            $invoice = $query->findOrFail($id);
            
            // Получаем PaymentDocument
            $paymentDocument = $invoice->primaryPaymentDocument;
            
            if (!$paymentDocument) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_payment_document' => false,
                        'message' => 'Счёт не отправлен на утверждение',
                    ],
                ]);
            }
            
            // Загружаем апрувалы
            $approvals = $paymentDocument->approvals()
                ->with(['approver'])
                ->orderBy('approval_level')
                ->orderBy('approval_order')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'has_payment_document' => true,
                    'payment_document_id' => $paymentDocument->id,
                    'document_number' => $paymentDocument->document_number,
                    'status' => $paymentDocument->status->value,
                    'status_label' => $paymentDocument->status->label(),
                    'approvals' => $approvals->map(fn($a) => [
                        'id' => $a->id,
                        'role' => $a->approval_role,
                        'role_label' => $a->getRoleLabel(),
                        'approver' => $a->approver ? [
                            'id' => $a->approver->id,
                            'name' => $a->approver->name,
                        ] : null,
                        'level' => $a->approval_level,
                        'order' => $a->approval_order,
                        'status' => $a->status,
                        'status_label' => $a->getStatusLabel(),
                        'comment' => $a->decision_comment,
                        'decided_at' => $a->decided_at?->toDateTimeString(),
                        'created_at' => $a->created_at->toDateTimeString(),
                    ]),
                    'total' => $approvals->count(),
                    'approved' => $approvals->where('status', 'approved')->count(),
                    'rejected' => $approvals->where('status', 'rejected')->count(),
                    'pending' => $approvals->where('status', 'pending')->count(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payments.invoices.approval_status.error', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статус утверждения',
            ], 500);
        }
    }
}

