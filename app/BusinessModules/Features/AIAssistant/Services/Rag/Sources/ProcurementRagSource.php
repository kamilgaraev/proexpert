<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class ProcurementRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'procurement';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = PurchaseRequest::query()
            ->with(['siteRequest.project', 'assignedUser', 'lines.material'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas(
                'siteRequest',
                static fn ($siteRequestQuery) => $siteRequestQuery->where('project_id', $projectId)
            ))
            ->orderBy('id');

        foreach ($query->cursor() as $request) {
            yield $this->chunk($request);
        }

        foreach ($this->supplierRequests($organizationId, $projectId) as $supplierRequest) {
            yield $this->supplierRequestChunk($supplierRequest);
        }

        foreach ($this->supplierProposals($organizationId, $projectId) as $proposal) {
            yield $this->supplierProposalChunk($proposal);
        }

        foreach ($this->proposalDecisions($organizationId, $projectId) as $decision) {
            yield $this->proposalDecisionChunk($decision);
        }

        foreach ($this->purchaseOrders($organizationId, $projectId) as $order) {
            yield $this->purchaseOrderChunk($order);
        }

        foreach ($this->purchaseReceipts($organizationId, $projectId) as $receipt) {
            yield $this->purchaseReceiptChunk($receipt);
        }

        foreach ($this->procurementApprovals($organizationId, $projectId) as $approval) {
            yield $this->procurementApprovalChunk($approval);
        }

        foreach ($this->procurementAuditEvents($organizationId, $projectId) as $event) {
            yield $this->procurementAuditEventChunk($event);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if (in_array($entityType, ['purchase_request', 'procurement'], true)) {
            $request = PurchaseRequest::query()
                ->with(['siteRequest.project', 'assignedUser', 'lines.material'])
                ->where('organization_id', $organizationId)
                ->where('id', $entityId)
                ->first();

            return $request instanceof PurchaseRequest ? [$this->chunk($request)] : [];
        }

        return match ($entityType) {
            'supplier_request' => $this->singleSupplierRequest($organizationId, $entityId),
            'supplier_proposal' => $this->singleSupplierProposal($organizationId, $entityId),
            'supplier_proposal_decision' => $this->singleProposalDecision($organizationId, $entityId),
            'purchase_order' => $this->singlePurchaseOrder($organizationId, $entityId),
            'purchase_receipt' => $this->singlePurchaseReceipt($organizationId, $entityId),
            'procurement_approval' => $this->singleProcurementApproval($organizationId, $entityId),
            'procurement_audit_event' => $this->singleProcurementAuditEvent($organizationId, $entityId),
            default => [],
        };
    }

    private function supplierRequestChunk(SupplierRequest $request): RagChunkData
    {
        $lines = $request->lines
            ->take(5)
            ->map(fn ($line): string => trim(sprintf(
                '%s %s %s',
                $this->stringValue($line->material?->name ?? $line->name ?? $line->specification ?? null),
                $this->numberValue($line->quantity ?? null),
                $this->stringValue($line->unit ?? null)
            )))
            ->filter()
            ->values()
            ->all();
        $projectId = $this->supplierRequestProjectId($request);

        $content = $this->lines([
            'Запрос поставщику: '.$this->stringValue($request->request_number),
            'Проект: '.$this->stringValue($request->purchaseRequest?->siteRequest?->project?->name),
            'Заявка на закупку: '.$this->stringValue($request->purchaseRequest?->request_number),
            'Поставщик: '.$this->supplierName($request->supplier?->name, $request->supplierParty?->display_name ?? null, $request->externalSupplierContact?->name ?? null, $request->supplier_snapshot),
            'Статус: '.$this->stringValue($request->status),
            'Отправлен: '.$this->dateValue($request->sent_at),
            'Открыт: '.$this->dateValue($request->public_opened_at),
            'Ответ получен: '.$this->dateValue($request->responded_at),
            'Позиции: '.implode(', ', $lines),
            'Комментарий: '.$this->stringValue($request->comment),
        ]);

        return new RagChunkData(
            organizationId: (int) $request->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'supplier_request',
            entityId: (int) $request->id,
            title: 'Запрос поставщику: '.$this->stringValue($request->request_number),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($request->status),
                'project_id' => $projectId,
                'purchase_request_id' => $request->purchase_request_id,
                'supplier_id' => $request->supplier_id,
                'supplier_party_id' => $request->supplier_party_id,
                'lines_count' => $request->lines->count(),
            ],
            updatedAt: $request->updated_at
        );
    }

    private function supplierProposalChunk(SupplierProposal $proposal): RagChunkData
    {
        $lines = $proposal->lines
            ->take(5)
            ->map(fn ($line): string => trim(sprintf(
                '%s %s %s',
                $this->stringValue($line->material?->name ?? $line->name ?? null),
                $this->numberValue($line->quantity ?? null),
                $this->stringValue($line->unit ?? null)
            )))
            ->filter()
            ->values()
            ->all();
        $projectId = $this->proposalProjectId($proposal);

        $content = $this->lines([
            'Коммерческое предложение: '.$this->stringValue($proposal->proposal_number),
            'Проект: '.$this->stringValue($proposal->supplierRequest?->purchaseRequest?->siteRequest?->project?->name ?? $proposal->purchaseOrder?->purchaseRequest?->siteRequest?->project?->name),
            'Запрос поставщику: '.$this->stringValue($proposal->supplierRequest?->request_number),
            'Заказ поставщику: '.$this->stringValue($proposal->purchaseOrder?->order_number),
            'Поставщик: '.$this->supplierName($proposal->supplier?->name, $proposal->supplierParty?->display_name ?? null, $proposal->externalSupplierContact?->name ?? null, $proposal->supplier_snapshot),
            'Статус: '.$this->stringValue($proposal->status),
            'Дата КП: '.$this->dateValue($proposal->proposal_date),
            'Действительно до: '.$this->dateValue($proposal->valid_until),
            'Срок поставки: '.$this->dateValue($proposal->delivery_due_date),
            'Сумма: '.$this->moneyValue($proposal->total_amount, $proposal->currency),
            'Условия оплаты: '.$this->stringValue($proposal->payment_terms),
            'Условия поставки: '.$this->stringValue($proposal->delivery_terms),
            'Позиции: '.implode(', ', $lines),
            'Примечания: '.$this->stringValue($proposal->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $proposal->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'supplier_proposal',
            entityId: (int) $proposal->id,
            title: 'КП поставщика: '.$this->stringValue($proposal->proposal_number),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($proposal->status),
                'project_id' => $projectId,
                'supplier_request_id' => $proposal->supplier_request_id,
                'purchase_order_id' => $proposal->purchase_order_id,
                'supplier_id' => $proposal->supplier_id,
                'supplier_party_id' => $proposal->supplier_party_id,
                'total_amount' => $proposal->total_amount,
                'currency' => $proposal->currency,
            ],
            updatedAt: $proposal->updated_at
        );
    }

    private function proposalDecisionChunk(SupplierProposalDecision $decision): RagChunkData
    {
        $projectId = $this->supplierRequestProjectId($decision->supplierRequest);
        $content = $this->lines([
            'Решение по предложениям поставщиков: '.$this->stringValue($decision->supplierRequest?->request_number),
            'Проект: '.$this->stringValue($decision->supplierRequest?->purchaseRequest?->siteRequest?->project?->name),
            'Статус: '.$this->stringValue($decision->status),
            'Победитель: '.$this->stringValue($decision->winningProposal?->proposal_number),
            'Дешевейшее КП: '.$this->stringValue($decision->cheapestProposal?->proposal_number),
            'Выбрана минимальная цена: '.($decision->is_lowest_price_selected ? 'yes' : 'no'),
            'Причина выбора: '.$this->stringValue($decision->decision_reason),
            'Выбрал: '.$this->stringValue($decision->selectedBy?->name),
            'Дата выбора: '.$this->dateValue($decision->selected_at),
        ]);

        return new RagChunkData(
            organizationId: (int) $decision->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'supplier_proposal_decision',
            entityId: (int) $decision->id,
            title: 'Решение по КП: '.$this->stringValue($decision->supplierRequest?->request_number),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($decision->status),
                'project_id' => $projectId,
                'supplier_request_id' => $decision->supplier_request_id,
                'winning_supplier_proposal_id' => $decision->winning_supplier_proposal_id,
                'is_lowest_price_selected' => (bool) $decision->is_lowest_price_selected,
            ],
            updatedAt: $decision->updated_at
        );
    }

    private function purchaseOrderChunk(PurchaseOrder $order): RagChunkData
    {
        $items = $order->items
            ->take(5)
            ->map(fn ($item): string => trim(sprintf(
                '%s %s %s',
                $this->stringValue($item->material?->name ?? $item->material_name ?? null),
                $this->numberValue($item->quantity ?? null),
                $this->stringValue($item->unit ?? null)
            )))
            ->filter()
            ->values()
            ->all();
        $projectId = $this->purchaseOrderProjectId($order);

        $content = $this->lines([
            'Заказ поставщику: '.$this->stringValue($order->order_number),
            'Проект: '.$this->stringValue($order->purchaseRequest?->siteRequest?->project?->name),
            'Заявка на закупку: '.$this->stringValue($order->purchaseRequest?->request_number),
            'Поставщик: '.$this->supplierName($order->supplier?->name, $order->supplierParty?->display_name ?? null, $order->externalSupplierContact?->name ?? null, $order->supplier_snapshot),
            'Договор: '.$this->stringValue($order->contract?->number),
            'Статус: '.$this->stringValue($order->status),
            'Дата заказа: '.$this->dateValue($order->order_date),
            'Дата поставки: '.$this->dateValue($order->delivery_date),
            'Отправлен: '.$this->dateValue($order->sent_at),
            'Подтвержден: '.$this->dateValue($order->confirmed_at),
            'Сумма: '.$this->moneyValue($order->total_amount, $order->currency),
            'Источник цены: '.$this->stringValue($order->pricing_source),
            'Позиции: '.implode(', ', $items),
            'Приемок: '.$this->numberValue($order->receipts_count ?? 0, 0),
            'Примечания: '.$this->stringValue($order->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $order->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'purchase_order',
            entityId: (int) $order->id,
            title: 'Заказ поставщику: '.$this->stringValue($order->order_number),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($order->status),
                'project_id' => $projectId,
                'purchase_request_id' => $order->purchase_request_id,
                'contract_id' => $order->contract_id,
                'supplier_id' => $order->supplier_id,
                'supplier_party_id' => $order->supplier_party_id,
                'total_amount' => $order->total_amount,
                'currency' => $order->currency,
            ],
            updatedAt: $order->updated_at
        );
    }

    private function purchaseReceiptChunk(PurchaseReceipt $receipt): RagChunkData
    {
        $projectId = $this->purchaseOrderProjectId($receipt->purchaseOrder);
        $lines = $receipt->lines
            ->take(5)
            ->map(fn ($line): string => trim(sprintf(
                '%s %s',
                $this->stringValue($line->purchaseOrderItem?->material?->name),
                $this->numberValue($line->quantity_received ?? null)
            )))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Приемка закупки: '.$this->stringValue($receipt->receipt_number),
            'Проект: '.$this->stringValue($receipt->purchaseOrder?->purchaseRequest?->siteRequest?->project?->name),
            'Заказ поставщику: '.$this->stringValue($receipt->purchaseOrder?->order_number),
            'Склад: '.$this->stringValue($receipt->warehouse?->name),
            'Статус: '.$this->stringValue($receipt->status),
            'Дата приемки: '.$this->dateValue($receipt->receipt_date),
            'Принял: '.$this->stringValue($receipt->receivedByUser?->name),
            'Позиции: '.implode(', ', $lines),
            'Примечания: '.$this->stringValue($receipt->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $receipt->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'purchase_receipt',
            entityId: (int) $receipt->id,
            title: 'Приемка закупки: '.$this->stringValue($receipt->receipt_number),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($receipt->status),
                'project_id' => $projectId,
                'purchase_order_id' => $receipt->purchase_order_id,
                'warehouse_id' => $receipt->warehouse_id,
                'receipt_date' => $this->dateValue($receipt->receipt_date),
            ],
            updatedAt: $receipt->updated_at
        );
    }

    private function procurementApprovalChunk(ProcurementApproval $approval): RagChunkData
    {
        $projectId = $this->procurementSubjectProjectId($approval->approvable);
        $content = $this->lines([
            'Согласование закупки: '.$this->stringValue(class_basename((string) $approval->approvable_type)).' #'.$this->stringValue($approval->approvable_id),
            'Статус: '.$this->stringValue($approval->status),
            'Причина: '.$this->stringValue($approval->reason_code),
            'Запрошено: '.$this->dateValue($approval->requested_at),
            'Решено: '.$this->dateValue($approval->resolved_at),
            'Запросил: '.$this->stringValue($approval->requestedBy?->name),
            'Утвердил: '.$this->stringValue($approval->approvedBy?->name),
            'Отклонил: '.$this->stringValue($approval->rejectedBy?->name),
            'Комментарий: '.$this->stringValue($approval->comment),
        ]);

        return new RagChunkData(
            organizationId: (int) $approval->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'procurement_approval',
            entityId: (int) $approval->id,
            title: 'Согласование закупки: '.$this->stringValue($approval->reason_code),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($approval->status),
                'project_id' => $projectId,
                'approvable_type' => $approval->approvable_type,
                'approvable_id' => $approval->approvable_id,
                'reason_code' => $approval->reason_code,
            ],
            updatedAt: $approval->updated_at
        );
    }

    private function procurementAuditEventChunk(ProcurementAuditEvent $event): RagChunkData
    {
        $projectId = $this->procurementSubjectProjectId($event->subject);
        $content = $this->lines([
            'Событие закупки: '.$this->stringValue($event->event_type),
            'Субъект: '.$this->stringValue(class_basename((string) $event->subject_type)).' #'.$this->stringValue($event->subject_id),
            'Дата: '.$this->dateValue($event->occurred_at),
            'Автор: '.$this->stringValue($event->actor?->name),
            'Поставщик: '.$this->stringValue($event->supplierParty?->display_name),
            'Данные: '.$this->arraySummary($event->payload),
        ]);

        return new RagChunkData(
            organizationId: (int) $event->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'procurement_audit_event',
            entityId: (int) $event->id,
            title: 'Событие закупки: '.$this->stringValue($event->event_type),
            content: $content,
            metadata: [
                'event_type' => $this->scalarValue($event->event_type),
                'project_id' => $projectId,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'supplier_party_id' => $event->supplier_party_id,
            ],
            updatedAt: $event->updated_at
        );
    }

    private function supplierRequests(int $organizationId, ?int $projectId): iterable
    {
        return SupplierRequest::query()
            ->with([
                'purchaseRequest.siteRequest.project',
                'supplier',
                'supplierParty',
                'externalSupplierContact',
                'lines.material',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas(
                'purchaseRequest.siteRequest',
                static fn ($siteRequestQuery) => $siteRequestQuery->where('project_id', $projectId)
            ))
            ->orderBy('id')
            ->cursor();
    }

    private function supplierProposals(int $organizationId, ?int $projectId): iterable
    {
        return SupplierProposal::query()
            ->with([
                'supplierRequest.purchaseRequest.siteRequest.project',
                'purchaseOrder.purchaseRequest.siteRequest.project',
                'supplier',
                'supplierParty',
                'externalSupplierContact',
                'lines.material',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, function ($query) use ($projectId): void {
                $query->where(function ($projectQuery) use ($projectId): void {
                    $projectQuery
                        ->whereHas(
                            'supplierRequest.purchaseRequest.siteRequest',
                            static fn ($siteRequestQuery) => $siteRequestQuery->where('project_id', $projectId)
                        )
                        ->orWhereHas(
                            'purchaseOrder.purchaseRequest.siteRequest',
                            static fn ($siteRequestQuery) => $siteRequestQuery->where('project_id', $projectId)
                        );
                });
            })
            ->orderBy('id')
            ->cursor();
    }

    private function proposalDecisions(int $organizationId, ?int $projectId): iterable
    {
        return SupplierProposalDecision::query()
            ->with([
                'supplierRequest.purchaseRequest.siteRequest.project',
                'winningProposal',
                'cheapestProposal',
                'selectedBy',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas(
                'supplierRequest.purchaseRequest.siteRequest',
                static fn ($siteRequestQuery) => $siteRequestQuery->where('project_id', $projectId)
            ))
            ->orderBy('id')
            ->cursor();
    }

    private function purchaseOrders(int $organizationId, ?int $projectId): iterable
    {
        return PurchaseOrder::query()
            ->with([
                'purchaseRequest.siteRequest.project',
                'supplier',
                'supplierParty',
                'externalSupplierContact',
                'contract',
                'items.material',
                'receipts',
            ])
            ->withCount('receipts')
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas(
                'purchaseRequest.siteRequest',
                static fn ($siteRequestQuery) => $siteRequestQuery->where('project_id', $projectId)
            ))
            ->orderBy('id')
            ->cursor();
    }

    private function purchaseReceipts(int $organizationId, ?int $projectId): iterable
    {
        return PurchaseReceipt::query()
            ->with([
                'purchaseOrder.purchaseRequest.siteRequest.project',
                'purchaseOrder.items.material',
                'warehouse',
                'receivedByUser',
                'lines.purchaseOrderItem.material',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas(
                'purchaseOrder.purchaseRequest.siteRequest',
                static fn ($siteRequestQuery) => $siteRequestQuery->where('project_id', $projectId)
            ))
            ->orderBy('id')
            ->cursor();
    }

    private function procurementApprovals(int $organizationId, ?int $projectId): iterable
    {
        return ProcurementApproval::query()
            ->with([
                'approvable' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith($this->procurementMorphRelations());
                },
                'requestedBy',
                'approvedBy',
                'rejectedBy',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, function ($query) use ($projectId): void {
                $this->whereProcurementSubjectMatchesProject($query, 'approvable', $projectId);
            })
            ->orderBy('id')
            ->cursor();
    }

    private function procurementAuditEvents(int $organizationId, ?int $projectId): iterable
    {
        return ProcurementAuditEvent::query()
            ->with([
                'subject' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith($this->procurementMorphRelations());
                },
                'actor',
                'supplierParty',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, function ($query) use ($projectId): void {
                $this->whereProcurementSubjectMatchesProject($query, 'subject', $projectId);
            })
            ->orderBy('id')
            ->cursor();
    }

    private function singleSupplierRequest(int $organizationId, string|int $entityId): array
    {
        $request = SupplierRequest::query()
            ->with([
                'purchaseRequest.siteRequest.project',
                'supplier',
                'supplierParty',
                'externalSupplierContact',
                'lines.material',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $request instanceof SupplierRequest ? [$this->supplierRequestChunk($request)] : [];
    }

    private function singleSupplierProposal(int $organizationId, string|int $entityId): array
    {
        $proposal = SupplierProposal::query()
            ->with([
                'supplierRequest.purchaseRequest.siteRequest.project',
                'purchaseOrder.purchaseRequest.siteRequest.project',
                'supplier',
                'supplierParty',
                'externalSupplierContact',
                'lines.material',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $proposal instanceof SupplierProposal ? [$this->supplierProposalChunk($proposal)] : [];
    }

    private function singleProposalDecision(int $organizationId, string|int $entityId): array
    {
        $decision = SupplierProposalDecision::query()
            ->with([
                'supplierRequest.purchaseRequest.siteRequest.project',
                'winningProposal',
                'cheapestProposal',
                'selectedBy',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $decision instanceof SupplierProposalDecision ? [$this->proposalDecisionChunk($decision)] : [];
    }

    private function singlePurchaseOrder(int $organizationId, string|int $entityId): array
    {
        $order = PurchaseOrder::query()
            ->with([
                'purchaseRequest.siteRequest.project',
                'supplier',
                'supplierParty',
                'externalSupplierContact',
                'contract',
                'items.material',
                'receipts',
            ])
            ->withCount('receipts')
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $order instanceof PurchaseOrder ? [$this->purchaseOrderChunk($order)] : [];
    }

    private function singlePurchaseReceipt(int $organizationId, string|int $entityId): array
    {
        $receipt = PurchaseReceipt::query()
            ->with([
                'purchaseOrder.purchaseRequest.siteRequest.project',
                'purchaseOrder.items.material',
                'warehouse',
                'receivedByUser',
                'lines.purchaseOrderItem.material',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $receipt instanceof PurchaseReceipt ? [$this->purchaseReceiptChunk($receipt)] : [];
    }

    private function singleProcurementApproval(int $organizationId, string|int $entityId): array
    {
        $approval = ProcurementApproval::query()
            ->with([
                'approvable' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith($this->procurementMorphRelations());
                },
                'requestedBy',
                'approvedBy',
                'rejectedBy',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $approval instanceof ProcurementApproval ? [$this->procurementApprovalChunk($approval)] : [];
    }

    private function singleProcurementAuditEvent(int $organizationId, string|int $entityId): array
    {
        $event = ProcurementAuditEvent::query()
            ->with([
                'subject' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith($this->procurementMorphRelations());
                },
                'actor',
                'supplierParty',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $event instanceof ProcurementAuditEvent ? [$this->procurementAuditEventChunk($event)] : [];
    }

    private function procurementMorphRelations(): array
    {
        return [
            PurchaseRequest::class => ['siteRequest.project'],
            SupplierRequest::class => ['purchaseRequest.siteRequest.project'],
            SupplierProposal::class => [
                'supplierRequest.purchaseRequest.siteRequest.project',
                'purchaseOrder.purchaseRequest.siteRequest.project',
            ],
            SupplierProposalDecision::class => ['supplierRequest.purchaseRequest.siteRequest.project'],
            PurchaseOrder::class => ['purchaseRequest.siteRequest.project'],
            PurchaseReceipt::class => ['purchaseOrder.purchaseRequest.siteRequest.project'],
        ];
    }

    private function whereProcurementSubjectMatchesProject(mixed $query, string $relation, int $projectId): void
    {
        $projectConstraint = static fn ($siteRequestQuery) => $siteRequestQuery->where('project_id', $projectId);

        $query->where(function ($projectQuery) use ($relation, $projectConstraint): void {
            $projectQuery
                ->whereHasMorph($relation, [PurchaseRequest::class], static function ($subjectQuery) use ($projectConstraint): void {
                    $subjectQuery->whereHas('siteRequest', $projectConstraint);
                })
                ->orWhereHasMorph($relation, [SupplierRequest::class], static function ($subjectQuery) use ($projectConstraint): void {
                    $subjectQuery->whereHas('purchaseRequest.siteRequest', $projectConstraint);
                })
                ->orWhereHasMorph($relation, [SupplierProposal::class], static function ($subjectQuery) use ($projectConstraint): void {
                    $subjectQuery->where(function ($proposalQuery) use ($projectConstraint): void {
                        $proposalQuery
                            ->whereHas('supplierRequest.purchaseRequest.siteRequest', $projectConstraint)
                            ->orWhereHas('purchaseOrder.purchaseRequest.siteRequest', $projectConstraint);
                    });
                })
                ->orWhereHasMorph($relation, [SupplierProposalDecision::class], static function ($subjectQuery) use ($projectConstraint): void {
                    $subjectQuery->whereHas('supplierRequest.purchaseRequest.siteRequest', $projectConstraint);
                })
                ->orWhereHasMorph($relation, [PurchaseOrder::class], static function ($subjectQuery) use ($projectConstraint): void {
                    $subjectQuery->whereHas('purchaseRequest.siteRequest', $projectConstraint);
                })
                ->orWhereHasMorph($relation, [PurchaseReceipt::class], static function ($subjectQuery) use ($projectConstraint): void {
                    $subjectQuery->whereHas('purchaseOrder.purchaseRequest.siteRequest', $projectConstraint);
                });
        });
    }

    private function supplierRequestProjectId(?SupplierRequest $request): ?int
    {
        $projectId = $request?->purchaseRequest?->siteRequest?->project_id;

        return $projectId !== null ? (int) $projectId : null;
    }

    private function proposalProjectId(?SupplierProposal $proposal): ?int
    {
        $projectId = $proposal?->supplierRequest?->purchaseRequest?->siteRequest?->project_id
            ?? $proposal?->purchaseOrder?->purchaseRequest?->siteRequest?->project_id;

        return $projectId !== null ? (int) $projectId : null;
    }

    private function purchaseOrderProjectId(?PurchaseOrder $order): ?int
    {
        $projectId = $order?->purchaseRequest?->siteRequest?->project_id;

        return $projectId !== null ? (int) $projectId : null;
    }

    private function procurementSubjectProjectId(?Model $subject): ?int
    {
        return match (true) {
            $subject instanceof PurchaseRequest => $subject->siteRequest?->project_id !== null
                ? (int) $subject->siteRequest->project_id
                : null,
            $subject instanceof SupplierRequest => $this->supplierRequestProjectId($subject),
            $subject instanceof SupplierProposal => $this->proposalProjectId($subject),
            $subject instanceof SupplierProposalDecision => $this->supplierRequestProjectId($subject->supplierRequest),
            $subject instanceof PurchaseOrder => $this->purchaseOrderProjectId($subject),
            $subject instanceof PurchaseReceipt => $this->purchaseOrderProjectId($subject->purchaseOrder),
            default => null,
        };
    }

    private function supplierName(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                foreach (['display_name', 'name', 'company_name', 'title', 'email'] as $key) {
                    $candidate = $this->stringValue($value[$key] ?? null);

                    if ($candidate !== '') {
                        return $candidate;
                    }
                }

                continue;
            }

            $candidate = $this->stringValue($value);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function arraySummary(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        $parts = [];
        foreach (array_slice($value, 0, 6, true) as $key => $item) {
            if (is_array($item)) {
                $item = implode(', ', array_filter(array_map(
                    fn (mixed $nested): string => $this->stringValue($nested),
                    array_slice($item, 0, 3)
                )));
            }

            $text = $this->stringValue($item);

            if ($text !== '') {
                $parts[] = is_string($key) ? $key.': '.$text : $text;
            }
        }

        return implode('; ', $parts);
    }

    private function chunk(PurchaseRequest $request): RagChunkData
    {
        $lines = $request->lines
            ->take(5)
            ->map(fn ($line): string => trim(sprintf(
                '%s %s %s',
                $this->stringValue($line->name ?? $line->material?->name),
                $this->numberValue($line->quantity),
                $this->stringValue($line->unit)
            )))
            ->filter()
            ->values()
            ->all();

        $projectId = $request->siteRequest?->project_id !== null ? (int) $request->siteRequest->project_id : null;
        $content = $this->lines([
            'Заявка на закупку: '.$this->stringValue($request->request_number),
            'Проект: '.$this->stringValue($request->siteRequest?->project?->name),
            'Заявка с объекта: '.$this->stringValue($request->siteRequest?->title),
            'Статус: '.$this->stringValue($request->status),
            'Ответственный: '.$this->stringValue($request->assignedUser?->name),
            'Плановая дата: '.$this->dateValue($request->needed_by),
            'Бюджет: '.$this->moneyValue($request->budget_amount, $request->budget_currency),
            'Позиции: '.implode(', ', $lines),
            'Примечания: '.$this->stringValue($request->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $request->organization_id,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: 'purchase_request',
            entityId: (int) $request->id,
            title: 'Закупка: '.$this->stringValue($request->request_number),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($request->status),
                'site_request_id' => $request->site_request_id,
                'project_id' => $projectId,
                'lines_count' => $request->lines->count(),
            ],
            updatedAt: $request->updated_at
        );
    }

    private function lines(array $lines): string
    {
        return implode("\n", array_filter($lines, static fn (string $line): bool => ! str_ends_with($line, ': ') && ! str_ends_with($line, ': -')));
    }

    private function scalarValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function stringValue(mixed $value): string
    {
        $value = $this->scalarValue($value);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function numberValue(mixed $value, int $precision = 3): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, $precision, '.', ''), '0'), '.') : '';
    }

    private function moneyValue(mixed $amount, mixed $currency): string
    {
        return is_numeric($amount) ? number_format((float) $amount, 2, '.', ' ').' '.$this->stringValue($currency) : '';
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
