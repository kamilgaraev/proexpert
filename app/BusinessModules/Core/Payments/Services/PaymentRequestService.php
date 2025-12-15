<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Act;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с платежными требованиями
 * Сценарий: Подрядчик -> Генподрядчик (Заказчик)
 */
class PaymentRequestService
{
    public function __construct(
        private readonly PaymentDocumentService $documentService,
        private readonly ApprovalWorkflowService $approvalWorkflow
    ) {}

    /**
     * Создать платежное требование от подрядчика
     */
    public function createFromContractor(array $data): PaymentDocument
    {
        DB::beginTransaction();

        try {
            // Обязательная информация для платежного требования
            $this->validateRequestData($data);

            // Получаем информацию о контракте
            $contract = null;
            if (isset($data['contract_id'])) {
                $contract = Contract::findOrFail($data['contract_id']);
            }

            // Формируем данные документа
            $documentData = [
                'organization_id' => $data['organization_id'], // организация-заказчик
                'project_id' => $data['project_id'] ?? $contract?->project_id,
                'document_type' => PaymentDocumentType::PAYMENT_REQUEST->value,
                'document_date' => $data['document_date'] ?? now(),
                'due_date' => $data['due_date'] ?? now()->addDays($contract?->payment_terms_days ?? 14),
                
                // Плательщик - организация-заказчик
                'payer_organization_id' => $data['organization_id'],
                'payer_contractor_id' => null,
                
                // Получатель - подрядчик
                'payee_organization_id' => null,
                'payee_contractor_id' => $data['contractor_id'],
                
                // Финансы
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'RUB',
                'vat_rate' => $data['vat_rate'] ?? 20,
                
                // Источник
                'source_type' => $data['source_type'] ?? Contract::class,
                'source_id' => $data['source_id'] ?? $contract?->id,
                
                // Детали
                'description' => $data['description'] ?? 'Платежное требование от подрядчика',
                'payment_purpose' => $this->generatePaymentPurpose($data, $contract),
                
                // Банковские реквизиты подрядчика
                'bank_account' => $data['bank_account'] ?? null,
                'bank_bik' => $data['bank_bik'] ?? null,
                'bank_correspondent_account' => $data['bank_correspondent_account'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                
                // Документы-основания
                'attached_documents' => $data['attached_documents'] ?? [],
                
                // Метаданные
                'metadata' => array_merge($data['metadata'] ?? [], [
                    'request_type' => 'contractor_to_customer',
                    'created_from' => 'contractor_portal',
                ]),
                
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ];

            // Создаем документ
            $document = $this->documentService->create($documentData);

            Log::info('payment_request.created', [
                'document_id' => $document->id,
                'contractor_id' => $data['contractor_id'],
                'amount' => $data['amount'],
            ]);

            DB::commit();
            return $document;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_request.creation_failed', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Создать платежное требование на основе акта
     */
    public function createFromAct(Act $act, array $additionalData = []): PaymentDocument
    {
        $contract = $act->contract;

        $data = [
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'contractor_id' => $contract->contractor_id,
            'contract_id' => $contract->id,
            'amount' => $act->total_amount,
            'description' => "Оплата по акту {$act->act_number} от " . $act->act_date->format('d.m.Y'),
            'source_type' => Act::class,
            'source_id' => $act->id,
            'attached_documents' => [
                [
                    'type' => 'act',
                    'id' => $act->id,
                    'number' => $act->act_number,
                    'date' => $act->act_date->format('Y-m-d'),
                ]
            ],
            ...$additionalData,
        ];

        return $this->createFromContractor($data);
    }

    /**
     * Отправить платежное требование на рассмотрение
     */
    public function submitRequest(PaymentDocument $document): PaymentDocument
    {
        if ($document->document_type !== PaymentDocumentType::PAYMENT_REQUEST) {
            throw new \DomainException('Можно отправлять только платежные требования');
        }

        // Отправляем на утверждение
        return $this->documentService->submit($document);
    }

    /**
     * Принять платежное требование (со стороны заказчика)
     */
    public function acceptRequest(PaymentDocument $document, array $data = []): PaymentDocument
    {
        DB::beginTransaction();

        try {
            // Утверждаем документ (если требуется workflow)
            if ($document->requiresApproval()) {
                // Workflow утверждения был инициирован при submit
                // Здесь мы просто проверяем, что он утвержден
                if ($document->status->value !== 'approved') {
                    throw new \DomainException('Требование должно быть утверждено');
                }
            }

            // Создаем платежное поручение на основе требования
            $paymentOrder = $this->createPaymentOrderFromRequest($document, $data);

            Log::info('payment_request.accepted', [
                'request_id' => $document->id,
                'payment_order_id' => $paymentOrder->id,
            ]);

            DB::commit();
            return $paymentOrder;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_request.accept_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Отклонить платежное требование
     */
    public function rejectRequest(PaymentDocument $document, string $reason, ?\App\Models\User $user = null): PaymentDocument
    {
        if ($document->document_type !== PaymentDocumentType::PAYMENT_REQUEST) {
            throw new \DomainException('Можно отклонять только платежные требования');
        }

        return $this->documentService->cancel($document, "Отклонено: {$reason}", $user);
    }

    /**
     * Получить входящие платежные требования для организации
     */
    public function getIncomingRequests(int $organizationId, array $filters = []): Collection
    {
        $filters['document_type'] = PaymentDocumentType::PAYMENT_REQUEST->value;
        $filters['payer_organization_id'] = $organizationId;

        return $this->documentService->getForOrganization($organizationId, $filters);
    }

    /**
     * Получить исходящие платежные требования (отправленные контрагентам)
     */
    public function getOutgoingRequests(int $organizationId, array $filters = []): Collection
    {
        $filters['document_type'] = PaymentDocumentType::PAYMENT_REQUEST->value;
        $filters['payee_organization_id'] = $organizationId;

        return $this->documentService->getForOrganization($organizationId, $filters);
    }

    /**
     * Получить требования от конкретного подрядчика
     */
    public function getRequestsFromContractor(int $organizationId, int $contractorId): Collection
    {
        return PaymentDocument::forOrganization($organizationId)
            ->byType(PaymentDocumentType::PAYMENT_REQUEST)
            ->where('payee_contractor_id', $contractorId)
            ->with(['source', 'approvals'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Получить требования по проекту
     */
    public function getRequestsByProject(int $projectId): Collection
    {
        return PaymentDocument::forProject($projectId)
            ->byType(PaymentDocumentType::PAYMENT_REQUEST)
            ->with(['payeeContractor', 'approvals'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Статистика по платежным требованиям
     */
    public function getStatistics(int $organizationId): array
    {
        $requests = PaymentDocument::forOrganization($organizationId)
            ->byType(PaymentDocumentType::PAYMENT_REQUEST)
            ->get();

        return [
            'total_count' => $requests->count(),
            'total_amount' => $requests->sum('amount'),
            'pending_approval_count' => $requests->where('status.value', 'pending_approval')->count(),
            'pending_approval_amount' => $requests->where('status.value', 'pending_approval')->sum('amount'),
            'approved_count' => $requests->where('status.value', 'approved')->count(),
            'approved_amount' => $requests->where('status.value', 'approved')->sum('amount'),
            'paid_count' => $requests->where('status.value', 'paid')->count(),
            'paid_amount' => $requests->where('status.value', 'paid')->sum('amount'),
            'rejected_count' => $requests->where('status.value', 'rejected')->count(),
            'by_contractor' => $requests->groupBy('payee_contractor_id')->map(function($items, $contractorId) {
                $contractor = Contractor::find($contractorId);
                return [
                    'contractor_id' => $contractorId,
                    'contractor_name' => $contractor?->name ?? 'Неизвестно',
                    'count' => $items->count(),
                    'total_amount' => $items->sum('amount'),
                    'pending_amount' => $items->whereIn('status.value', ['pending_approval', 'approved'])->sum('amount'),
                ];
            })->values(),
        ];
    }

    /**
     * Создать платежное поручение на основе требования
     */
    private function createPaymentOrderFromRequest(PaymentDocument $request, array $additionalData = []): PaymentDocument
    {
        $orderData = [
            'organization_id' => $request->organization_id,
            'project_id' => $request->project_id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'document_date' => $additionalData['document_date'] ?? now(),
            'due_date' => $additionalData['due_date'] ?? $request->due_date,
            
            // Копируем стороны
            'payer_organization_id' => $request->payer_organization_id,
            'payer_contractor_id' => $request->payer_contractor_id,
            'payee_organization_id' => $request->payee_organization_id,
            'payee_contractor_id' => $request->payee_contractor_id,
            
            // Финансы
            'amount' => $request->amount,
            'currency' => $request->currency,
            'vat_rate' => $request->vat_rate,
            
            // Источник - платежное требование
            'source_type' => PaymentDocument::class,
            'source_id' => $request->id,
            
            // Детали
            'description' => "Платежное поручение по требованию {$request->document_number}",
            'payment_purpose' => $request->payment_purpose,
            
            // Реквизиты
            'bank_account' => $request->bank_account,
            'bank_bik' => $request->bank_bik,
            'bank_correspondent_account' => $request->bank_correspondent_account,
            'bank_name' => $request->bank_name,
            
            // Документы
            'attached_documents' => array_merge(
                $request->attached_documents ?? [],
                [
                    [
                        'type' => 'payment_request',
                        'id' => $request->id,
                        'number' => $request->document_number,
                        'date' => $request->document_date->format('Y-m-d'),
                    ]
                ]
            ),
            
            // Метаданные
            'metadata' => [
                'created_from_request' => $request->id,
                'request_number' => $request->document_number,
            ],
            
            ...$additionalData,
        ];

        return $this->documentService->create($orderData);
    }

    /**
     * Валидация данных требования
     */
    private function validateRequestData(array $data): void
    {
        $required = ['organization_id', 'contractor_id', 'amount'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("Поле '{$field}' обязательно для платежного требования");
            }
        }

        if ($data['amount'] <= 0) {
            throw new \InvalidArgumentException('Сумма должна быть больше 0');
        }

        // Проверка контрагента
        $contractor = Contractor::find($data['contractor_id']);
        if (!$contractor) {
            throw new \InvalidArgumentException('Контрагент не найден');
        }

        // Проверка блокировки
        $account = DB::table('counterparty_accounts')
            ->where('organization_id', $data['organization_id'])
            ->where('contractor_id', $data['contractor_id'])
            ->first();

        if ($account && $account->is_blocked) {
            throw new \DomainException('Контрагент заблокирован. Платежные требования от него не принимаются');
        }
    }

    /**
     * Генерация назначения платежа
     */
    private function generatePaymentPurpose(array $data, ?Contract $contract): string
    {
        $parts = [];

        if ($contract) {
            $parts[] = "Оплата по договору {$contract->contract_number} от " . $contract->contract_date->format('d.m.Y');
        }

        if (isset($data['description'])) {
            $parts[] = $data['description'];
        }

        if (isset($data['act_number'])) {
            $parts[] = "Акт {$data['act_number']}";
        }

        if ($data['vat_rate'] ?? 20 > 0) {
            $parts[] = "В том числе НДС {$data['vat_rate']}%";
        } else {
            $parts[] = "Без НДС";
        }

        return implode('. ', $parts);
    }

    /**
     * Массовое создание требований на основе актов
     */
    public function createBulkFromActs(array $actIds, array $commonData = []): Collection
    {
        $acts = Act::whereIn('id', $actIds)->with('contract')->get();
        $documents = collect();

        DB::beginTransaction();

        try {
            foreach ($acts as $act) {
                try {
                    $document = $this->createFromAct($act, $commonData);
                    $documents->push($document);
                } catch (\Exception $e) {
                    Log::error('payment_request.bulk_create_failed', [
                        'act_id' => $act->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DB::commit();

            Log::info('payment_request.bulk_created', [
                'total_acts' => $acts->count(),
                'created_documents' => $documents->count(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $documents;
    }
}

