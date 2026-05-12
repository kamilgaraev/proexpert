<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct as Act;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

/**
 * РЎРµСЂРІРёСЃ РґР»СЏ СЂР°Р±РѕС‚С‹ СЃ РїР»Р°С‚РµР¶РЅС‹РјРё С‚СЂРµР±РѕРІР°РЅРёСЏРјРё
 * РЎС†РµРЅР°СЂРёР№: РџРѕРґСЂСЏРґС‡РёРє -> Р“РµРЅРїРѕРґСЂСЏРґС‡РёРє (Р—Р°РєР°Р·С‡РёРє)
 */
class PaymentRequestService
{
    public function __construct(
        private readonly PaymentDocumentService $documentService,
        private readonly ApprovalWorkflowService $approvalWorkflow,
        private readonly PaymentDocumentStateMachine $stateMachine
    ) {}

    /**
     * РЎРѕР·РґР°С‚СЊ РїР»Р°С‚РµР¶РЅРѕРµ С‚СЂРµР±РѕРІР°РЅРёРµ РѕС‚ РїРѕРґСЂСЏРґС‡РёРєР°
     */
    public function createFromContractor(array $data): PaymentDocument
    {
        DB::beginTransaction();

        try {
            // РћР±СЏР·Р°С‚РµР»СЊРЅР°СЏ РёРЅС„РѕСЂРјР°С†РёСЏ РґР»СЏ РїР»Р°С‚РµР¶РЅРѕРіРѕ С‚СЂРµР±РѕРІР°РЅРёСЏ
            $this->validateRequestData($data);

            // РџРѕР»СѓС‡Р°РµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ РєРѕРЅС‚СЂР°РєС‚Рµ
            $contract = null;
            if (isset($data['contract_id'])) {
                $contract = Contract::query()
                    ->forOrganization($data['organization_id'])
                    ->findOrFail($data['contract_id']);
            }

            // Р¤РѕСЂРјРёСЂСѓРµРј РґР°РЅРЅС‹Рµ РґРѕРєСѓРјРµРЅС‚Р°
            $documentData = [
                'organization_id' => $data['organization_id'], // РѕСЂРіР°РЅРёР·Р°С†РёСЏ-Р·Р°РєР°Р·С‡РёРє
                'project_id' => $data['project_id'] ?? $contract?->project_id,
                'document_type' => PaymentDocumentType::PAYMENT_REQUEST->value,
                'document_date' => $data['document_date'] ?? now(),
                'due_date' => $data['due_date'] ?? now()->addDays($contract?->payment_terms_days ?? 14),
                
                // РџР»Р°С‚РµР»СЊС‰РёРє - РѕСЂРіР°РЅРёР·Р°С†РёСЏ-Р·Р°РєР°Р·С‡РёРє
                'payer_organization_id' => $data['organization_id'],
                'payer_contractor_id' => null,
                
                // РџРѕР»СѓС‡Р°С‚РµР»СЊ - РїРѕРґСЂСЏРґС‡РёРє
                'payee_organization_id' => null,
                'payee_contractor_id' => $data['contractor_id'],
                
                // Р¤РёРЅР°РЅСЃС‹
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'RUB',
                'vat_rate' => $data['vat_rate'] ?? 20,
                
                // РСЃС‚РѕС‡РЅРёРє
                'source_type' => $data['source_type'] ?? Contract::class,
                'source_id' => $data['source_id'] ?? $contract?->id,
                
                // Р”РµС‚Р°Р»Рё
                'description' => $data['description'] ?? 'РџР»Р°С‚РµР¶РЅРѕРµ С‚СЂРµР±РѕРІР°РЅРёРµ РѕС‚ РїРѕРґСЂСЏРґС‡РёРєР°',
                'payment_purpose' => $data['payment_purpose'] ?? $this->generatePaymentPurpose($data, $contract),
                
                // Р‘Р°РЅРєРѕРІСЃРєРёРµ СЂРµРєРІРёР·РёС‚С‹ РїРѕРґСЂСЏРґС‡РёРєР°
                'bank_account' => $data['bank_account'] ?? null,
                'bank_bik' => $data['bank_bik'] ?? null,
                'bank_correspondent_account' => $data['bank_correspondent_account'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                
                // Р”РѕРєСѓРјРµРЅС‚С‹-РѕСЃРЅРѕРІР°РЅРёСЏ
                'attached_documents' => $data['attached_documents'] ?? [],
                
                // РњРµС‚Р°РґР°РЅРЅС‹Рµ
                'metadata' => array_merge($data['metadata'] ?? [], [
                    'request_type' => 'contractor_to_customer',
                    'created_from' => 'contractor_portal',
                ]),
                
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
            ];

            // РЎРѕР·РґР°РµРј РґРѕРєСѓРјРµРЅС‚
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
     * РЎРѕР·РґР°С‚СЊ РїР»Р°С‚РµР¶РЅРѕРµ С‚СЂРµР±РѕРІР°РЅРёРµ РЅР° РѕСЃРЅРѕРІРµ Р°РєС‚Р°
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
            'description' => "РћРїР»Р°С‚Р° РїРѕ Р°РєС‚Сѓ {$act->act_number} РѕС‚ " . $act->act_date->format('d.m.Y'),
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
     * РћС‚РїСЂР°РІРёС‚СЊ РїР»Р°С‚РµР¶РЅРѕРµ С‚СЂРµР±РѕРІР°РЅРёРµ РЅР° СЂР°СЃСЃРјРѕС‚СЂРµРЅРёРµ
     */
    public function submitRequest(PaymentDocument $document): PaymentDocument
    {
        if ($document->document_type !== PaymentDocumentType::PAYMENT_REQUEST) {
            throw new \DomainException(trans_message('payments.validation.request_submit_only_payment_requests'));
        }

        // РћС‚РїСЂР°РІР»СЏРµРј РЅР° СѓС‚РІРµСЂР¶РґРµРЅРёРµ
        return $this->documentService->submit($document);
    }

    /**
     * РџСЂРёРЅСЏС‚СЊ РїР»Р°С‚РµР¶РЅРѕРµ С‚СЂРµР±РѕРІР°РЅРёРµ (СЃРѕ СЃС‚РѕСЂРѕРЅС‹ Р·Р°РєР°Р·С‡РёРєР°)
     */
    public function acceptRequest(PaymentDocument $document, array $data = []): PaymentDocument
    {
        DB::beginTransaction();

        try {
            // РЈС‚РІРµСЂР¶РґР°РµРј РґРѕРєСѓРјРµРЅС‚ (РµСЃР»Рё С‚СЂРµР±СѓРµС‚СЃСЏ workflow)
            if ($document->requiresApproval()) {
                // Workflow СѓС‚РІРµСЂР¶РґРµРЅРёСЏ Р±С‹Р» РёРЅРёС†РёРёСЂРѕРІР°РЅ РїСЂРё submit
                // Р—РґРµСЃСЊ РјС‹ РїСЂРѕСЃС‚Рѕ РїСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РѕРЅ СѓС‚РІРµСЂР¶РґРµРЅ
                if ($document->status->value !== 'approved') {
                    throw new \DomainException(trans_message('payments.validation.request_must_be_approved'));
                }
            }

            // РЎРѕР·РґР°РµРј РїР»Р°С‚РµР¶РЅРѕРµ РїРѕСЂСѓС‡РµРЅРёРµ РЅР° РѕСЃРЅРѕРІРµ С‚СЂРµР±РѕРІР°РЅРёСЏ
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
     * РћС‚РєР»РѕРЅРёС‚СЊ РїР»Р°С‚РµР¶РЅРѕРµ С‚СЂРµР±РѕРІР°РЅРёРµ
     */
    public function rejectRequest(PaymentDocument $document, string $reason, ?\App\Models\User $user = null): PaymentDocument
    {
        if ($document->document_type !== PaymentDocumentType::PAYMENT_REQUEST) {
            throw new \DomainException(trans_message('payments.validation.request_reject_only_payment_requests'));
        }

        return $this->stateMachine->reject($document, $reason)->fresh();
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РІС…РѕРґСЏС‰РёРµ РїР»Р°С‚РµР¶РЅС‹Рµ С‚СЂРµР±РѕРІР°РЅРёСЏ РґР»СЏ РѕСЂРіР°РЅРёР·Р°С†РёРё
     */
    public function getIncomingRequests(int $organizationId, array $filters = []): Collection
    {
        $filters['document_type'] = PaymentDocumentType::PAYMENT_REQUEST->value;
        $filters['payer_organization_id'] = $organizationId;

        return $this->documentService->getForOrganization($organizationId, $filters);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РёСЃС…РѕРґСЏС‰РёРµ РїР»Р°С‚РµР¶РЅС‹Рµ С‚СЂРµР±РѕРІР°РЅРёСЏ (РѕС‚РїСЂР°РІР»РµРЅРЅС‹Рµ РєРѕРЅС‚СЂР°РіРµРЅС‚Р°Рј)
     */
    public function getOutgoingRequests(int $organizationId, array $filters = []): Collection
    {
        $filters['document_type'] = PaymentDocumentType::PAYMENT_REQUEST->value;
        $filters['payee_organization_id'] = $organizationId;

        return $this->documentService->getForOrganization($organizationId, $filters);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ С‚СЂРµР±РѕРІР°РЅРёСЏ РѕС‚ РєРѕРЅРєСЂРµС‚РЅРѕРіРѕ РїРѕРґСЂСЏРґС‡РёРєР°
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
     * РџРѕР»СѓС‡РёС‚СЊ С‚СЂРµР±РѕРІР°РЅРёСЏ РїРѕ РїСЂРѕРµРєС‚Сѓ
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
     * РЎС‚Р°С‚РёСЃС‚РёРєР° РїРѕ РїР»Р°С‚РµР¶РЅС‹Рј С‚СЂРµР±РѕРІР°РЅРёСЏРј
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
                    'contractor_name' => $contractor?->name ?? 'РќРµРёР·РІРµСЃС‚РЅРѕ',
                    'count' => $items->count(),
                    'total_amount' => $items->sum('amount'),
                    'pending_amount' => $items->whereIn('status.value', ['pending_approval', 'approved'])->sum('amount'),
                ];
            })->values(),
        ];
    }

    /**
     * РЎРѕР·РґР°С‚СЊ РїР»Р°С‚РµР¶РЅРѕРµ РїРѕСЂСѓС‡РµРЅРёРµ РЅР° РѕСЃРЅРѕРІРµ С‚СЂРµР±РѕРІР°РЅРёСЏ
     */
    private function createPaymentOrderFromRequest(PaymentDocument $request, array $additionalData = []): PaymentDocument
    {
        $orderData = [
            'organization_id' => $request->organization_id,
            'project_id' => $request->project_id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER->value,
            'document_date' => $additionalData['document_date'] ?? now(),
            'due_date' => $additionalData['due_date'] ?? $request->due_date,
            
            // РљРѕРїРёСЂСѓРµРј СЃС‚РѕСЂРѕРЅС‹
            'payer_organization_id' => $request->payer_organization_id,
            'payer_contractor_id' => $request->payer_contractor_id,
            'payee_organization_id' => $request->payee_organization_id,
            'payee_contractor_id' => $request->payee_contractor_id,
            
            // Р¤РёРЅР°РЅСЃС‹
            'amount' => $request->amount,
            'currency' => $request->currency,
            'vat_rate' => $request->vat_rate,
            
            // РСЃС‚РѕС‡РЅРёРє - РїР»Р°С‚РµР¶РЅРѕРµ С‚СЂРµР±РѕРІР°РЅРёРµ
            'source_type' => PaymentDocument::class,
            'source_id' => $request->id,
            
            // Р”РµС‚Р°Р»Рё
            'description' => "РџР»Р°С‚РµР¶РЅРѕРµ РїРѕСЂСѓС‡РµРЅРёРµ РїРѕ С‚СЂРµР±РѕРІР°РЅРёСЋ {$request->document_number}",
            'payment_purpose' => $request->payment_purpose,
            
            // Р РµРєРІРёР·РёС‚С‹
            'bank_account' => $request->bank_account,
            'bank_bik' => $request->bank_bik,
            'bank_correspondent_account' => $request->bank_correspondent_account,
            'bank_name' => $request->bank_name,
            
            // Р”РѕРєСѓРјРµРЅС‚С‹
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
            
            // РњРµС‚Р°РґР°РЅРЅС‹Рµ
            'metadata' => [
                'created_from_request' => $request->id,
                'request_number' => $request->document_number,
            ],
            
            ...$additionalData,
        ];

        return $this->documentService->create($orderData);
    }

    /**
     * Р’Р°Р»РёРґР°С†РёСЏ РґР°РЅРЅС‹С… С‚СЂРµР±РѕРІР°РЅРёСЏ
     */
    private function validateRequestData(array $data): void
    {
        $required = ['organization_id', 'contractor_id', 'amount'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException(sprintf(
                    trans_message('payments.validation.request_required_field'),
                    $field
                ));
            }
        }

        if ($data['amount'] <= 0) {
            throw new \InvalidArgumentException(trans_message('payments.validation.amount_positive'));
        }

        // РџСЂРѕРІРµСЂРєР° РєРѕРЅС‚СЂР°РіРµРЅС‚Р°
        $contractor = Contractor::query()
            ->where('organization_id', $data['organization_id'])
            ->find($data['contractor_id']);
        if (!$contractor) {
            throw new \InvalidArgumentException(trans_message('payments.validation.contractor_not_found'));
        }

        // РџСЂРѕРІРµСЂРєР° Р±Р»РѕРєРёСЂРѕРІРєРё
        $account = DB::table('counterparty_accounts')
            ->where('organization_id', $data['organization_id'])
            ->where('counterparty_contractor_id', $data['contractor_id'])
            ->first();

        if ($account && $account->is_blocked) {
            throw new \DomainException(trans_message('payments.validation.contractor_blocked'));
        }
    }

    /**
     * Р“РµРЅРµСЂР°С†РёСЏ РЅР°Р·РЅР°С‡РµРЅРёСЏ РїР»Р°С‚РµР¶Р°
     */
    private function generatePaymentPurpose(array $data, ?Contract $contract): string
    {
        $parts = [];

        if ($contract) {
            $parts[] = "РћРїР»Р°С‚Р° РїРѕ РґРѕРіРѕРІРѕСЂСѓ {$contract->contract_number} РѕС‚ " . $contract->contract_date->format('d.m.Y');
        }

        if (isset($data['description'])) {
            $parts[] = $data['description'];
        }

        if (isset($data['act_number'])) {
            $parts[] = "РђРєС‚ {$data['act_number']}";
        }

        if ($data['vat_rate'] ?? 20 > 0) {
            $parts[] = "Р’ С‚РѕРј С‡РёСЃР»Рµ РќР”РЎ {$data['vat_rate']}%";
        } else {
            $parts[] = "Р‘РµР· РќР”РЎ";
        }

        return implode('. ', $parts);
    }

    /**
     * РњР°СЃСЃРѕРІРѕРµ СЃРѕР·РґР°РЅРёРµ С‚СЂРµР±РѕРІР°РЅРёР№ РЅР° РѕСЃРЅРѕРІРµ Р°РєС‚РѕРІ
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
