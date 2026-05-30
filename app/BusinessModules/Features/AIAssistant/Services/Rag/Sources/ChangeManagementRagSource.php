<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\Concerns\FormatsRagSourceContent;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use DateTimeInterface;

final class ChangeManagementRagSource implements RagSourceCollectorInterface
{
    use FormatsRagSourceContent;

    public function sourceType(): string
    {
        return 'change_management';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->rfis($organizationId, $projectId) as $rfi) {
            yield $this->rfiChunk($rfi);
        }

        foreach ($this->changeRequests($organizationId, $projectId) as $request) {
            yield $this->changeRequestChunk($request);
        }

        foreach ($this->claims($organizationId, $projectId) as $claim) {
            yield $this->claimChunk($claim);
        }

        foreach ($this->impacts($organizationId, $projectId) as $impact) {
            yield $this->impactChunk($impact);
        }

        foreach ($this->approvals($organizationId, $projectId) as $approval) {
            yield $this->approvalChunk($approval);
        }

        foreach ($this->variationOrders($organizationId, $projectId) as $variationOrder) {
            yield $this->variationOrderChunk($variationOrder);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return match ($entityType) {
            'change_management_rfi' => $this->singleRfi($organizationId, $entityId),
            'change_request' => $this->singleChangeRequest($organizationId, $entityId),
            'change_claim' => $this->singleClaim($organizationId, $entityId),
            'change_impact' => $this->singleImpact($organizationId, $entityId),
            'change_approval' => $this->singleApproval($organizationId, $entityId),
            'variation_order' => $this->singleVariationOrder($organizationId, $entityId),
            default => [],
        };
    }

    private function rfiChunk(ChangeManagementRfi $rfi): RagChunkData
    {
        $content = $this->lines([
            'RFI: '.$this->stringValue($rfi->rfi_number),
            'Проект: '.$this->stringValue($rfi->project?->name),
            'Тема: '.$this->stringValue($rfi->subject),
            'Адресат: '.$this->stringValue($rfi->addressee_type),
            'Статус: '.$this->stringValue($rfi->status),
            'Срок ответа: '.$this->dateValue($rfi->response_due_date),
            'Отправлено: '.$this->dateTimeValue($rfi->sent_at),
            'Отвечено: '.$this->dateTimeValue($rfi->answered_at),
            'Создал: '.$this->stringValue($rfi->createdBy?->name),
            'Вопрос: '.$this->stringValue($rfi->question),
            'Ответ: '.$this->stringValue($rfi->answer),
        ]);

        return $this->chunk(
            $rfi->organization_id,
            $rfi->project_id,
            'change_management_rfi',
            $rfi->id,
            'RFI: '.$this->stringValue($rfi->rfi_number),
            $content,
            [
                'status' => $this->scalarValue($rfi->status),
                'project_id' => $rfi->project_id,
                'response_due_date' => $this->dateValue($rfi->response_due_date),
            ],
            $rfi->updated_at
        );
    }

    private function changeRequestChunk(ChangeRequest $request): RagChunkData
    {
        $content = $this->lines([
            'Изменение: '.$this->stringValue($request->change_number),
            'Проект: '.$this->stringValue($request->project?->name),
            'Название: '.$this->stringValue($request->title),
            'Причина: '.$this->stringValue($request->reason),
            'Инициатор: '.$this->stringValue($request->initiator_type),
            'Статус: '.$this->stringValue($request->status),
            'Связанный RFI: '.$this->stringValue($request->relatedRfi?->rfi_number),
            'Стоимость изменения: '.$this->moneyValue($request->impact?->cost_delta),
            'Влияние на срок, дней: '.$this->numberValue($request->impact?->schedule_delta_days, 0),
            'Требуется изменение договора: '.$this->boolValue($request->impact?->requires_contract_change),
            'Требуется согласование заказчика: '.$this->boolValue($request->impact?->requires_customer_approval),
            'Описание: '.$this->stringValue($request->description),
            'Комментарий внедрения: '.$this->stringValue($request->implementation_comment),
        ]);

        return $this->chunk(
            $request->organization_id,
            $request->project_id,
            'change_request',
            $request->id,
            'Изменение: '.$this->stringValue($request->change_number),
            $content,
            [
                'status' => $this->scalarValue($request->status),
                'project_id' => $request->project_id,
                'related_rfi_id' => $request->related_rfi_id,
                'cost_delta' => $request->impact?->cost_delta,
                'schedule_delta_days' => $request->impact?->schedule_delta_days,
            ],
            $request->updated_at
        );
    }

    private function claimChunk(ChangeClaim $claim): RagChunkData
    {
        $content = $this->lines([
            'Claim: '.$this->stringValue($claim->claim_number),
            'Проект: '.$this->stringValue($claim->project?->name),
            'Изменение: '.$this->stringValue($claim->changeRequest?->change_number),
            'Название: '.$this->stringValue($claim->title),
            'Статус: '.$this->stringValue($claim->status),
            'Сумма: '.$this->moneyValue($claim->amount),
            'Описание: '.$this->stringValue($claim->description),
            'Доказательства: '.$this->arrayValue($claim->evidence),
        ]);

        return $this->chunk(
            $claim->organization_id,
            $claim->project_id,
            'change_claim',
            $claim->id,
            'Claim: '.$this->stringValue($claim->claim_number),
            $content,
            [
                'status' => $this->scalarValue($claim->status),
                'project_id' => $claim->project_id,
                'change_request_id' => $claim->change_request_id,
                'amount' => $claim->amount,
            ],
            $claim->updated_at
        );
    }

    private function impactChunk(ChangeImpact $impact): RagChunkData
    {
        $request = $impact->changeRequest;
        $content = $this->lines([
            'Влияние изменения: '.$this->stringValue($request?->change_number),
            'Проект: '.$this->stringValue($request?->project?->name),
            'Стоимость изменения: '.$this->moneyValue($impact->cost_delta),
            'Влияние на срок, дней: '.$this->numberValue($impact->schedule_delta_days, 0),
            'Требуется изменение договора: '.$this->boolValue($impact->requires_contract_change),
            'Требуется пересмотр сметы: '.$this->boolValue($impact->requires_estimate_revision),
            'Требуется закупка: '.$this->boolValue($impact->requires_procurement_update),
            'Требуется согласование заказчика: '.$this->boolValue($impact->requires_customer_approval),
            'Задачи графика: '.$this->arrayValue($impact->affected_schedule_task_ids),
            'Сметные позиции: '.$this->arrayValue($impact->affected_estimate_item_ids),
            'Договоры: '.$this->arrayValue($impact->affected_contract_ids),
            'Резюме: '.$this->stringValue($impact->summary),
        ]);

        return $this->chunk(
            $impact->organization_id,
            $request?->project_id !== null ? (int) $request->project_id : null,
            'change_impact',
            $impact->id,
            'Влияние изменения: '.$this->stringValue($request?->change_number),
            $content,
            [
                'change_request_id' => $impact->change_request_id,
                'project_id' => $request?->project_id,
                'cost_delta' => $impact->cost_delta,
                'schedule_delta_days' => $impact->schedule_delta_days,
                'requires_customer_approval' => (bool) $impact->requires_customer_approval,
            ],
            $impact->updated_at
        );
    }

    private function approvalChunk(ChangeApproval $approval): RagChunkData
    {
        $request = $approval->changeRequest;
        $content = $this->lines([
            'Согласование изменения: '.$this->stringValue($request?->change_number),
            'Проект: '.$this->stringValue($request?->project?->name),
            'Тип согласования: '.$this->stringValue($approval->approval_type),
            'Статус: '.$this->stringValue($approval->status),
            'Дата решения: '.$this->dateTimeValue($approval->decided_at),
            'Комментарий: '.$this->stringValue($approval->comment),
        ]);

        return $this->chunk(
            $approval->organization_id,
            $request?->project_id !== null ? (int) $request->project_id : null,
            'change_approval',
            $approval->id,
            'Согласование изменения: '.$this->stringValue($request?->change_number),
            $content,
            [
                'status' => $this->scalarValue($approval->status),
                'approval_type' => $this->scalarValue($approval->approval_type),
                'change_request_id' => $approval->change_request_id,
                'project_id' => $request?->project_id,
            ],
            $approval->updated_at
        );
    }

    private function variationOrderChunk(VariationOrder $variationOrder): RagChunkData
    {
        $request = $variationOrder->changeRequest;
        $content = $this->lines([
            'Variation order: '.$this->stringValue($variationOrder->variation_number),
            'Проект: '.$this->stringValue($request?->project?->name),
            'Изменение: '.$this->stringValue($request?->change_number),
            'Сумма: '.$this->moneyValue($variationOrder->amount),
            'Влияние на срок, дней: '.$this->numberValue($variationOrder->schedule_delta_days, 0),
            'Описание: '.$this->stringValue($variationOrder->description),
        ]);

        return $this->chunk(
            $variationOrder->organization_id,
            $request?->project_id !== null ? (int) $request->project_id : null,
            'variation_order',
            $variationOrder->id,
            'Variation order: '.$this->stringValue($variationOrder->variation_number),
            $content,
            [
                'change_request_id' => $variationOrder->change_request_id,
                'project_id' => $request?->project_id,
                'amount' => $variationOrder->amount,
                'schedule_delta_days' => $variationOrder->schedule_delta_days,
            ],
            $variationOrder->updated_at
        );
    }

    private function rfis(int $organizationId, ?int $projectId): iterable
    {
        return ChangeManagementRfi::query()
            ->with(['project', 'createdBy'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function changeRequests(int $organizationId, ?int $projectId): iterable
    {
        return ChangeRequest::query()
            ->with(['project', 'createdBy', 'relatedRfi', 'impact'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function claims(int $organizationId, ?int $projectId): iterable
    {
        return ChangeClaim::query()
            ->with(['project', 'changeRequest'])
            ->forOrganization($organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function impacts(int $organizationId, ?int $projectId): iterable
    {
        return ChangeImpact::query()
            ->with(['changeRequest.project'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas('changeRequest', static fn ($requestQuery) => $requestQuery->where('project_id', $projectId)))
            ->orderBy('id')
            ->cursor();
    }

    private function approvals(int $organizationId, ?int $projectId): iterable
    {
        return ChangeApproval::query()
            ->with(['changeRequest.project'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas('changeRequest', static fn ($requestQuery) => $requestQuery->where('project_id', $projectId)))
            ->orderBy('id')
            ->cursor();
    }

    private function variationOrders(int $organizationId, ?int $projectId): iterable
    {
        return VariationOrder::query()
            ->with(['changeRequest.project'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->whereHas('changeRequest', static fn ($requestQuery) => $requestQuery->where('project_id', $projectId)))
            ->orderBy('id')
            ->cursor();
    }

    private function singleRfi(int $organizationId, string|int $entityId): array
    {
        $rfi = ChangeManagementRfi::query()->with(['project', 'createdBy'])->forOrganization($organizationId)->find($entityId);

        return $rfi instanceof ChangeManagementRfi ? [$this->rfiChunk($rfi)] : [];
    }

    private function singleChangeRequest(int $organizationId, string|int $entityId): array
    {
        $request = ChangeRequest::query()->with(['project', 'createdBy', 'relatedRfi', 'impact'])->forOrganization($organizationId)->find($entityId);

        return $request instanceof ChangeRequest ? [$this->changeRequestChunk($request)] : [];
    }

    private function singleClaim(int $organizationId, string|int $entityId): array
    {
        $claim = ChangeClaim::query()->with(['project', 'changeRequest'])->forOrganization($organizationId)->find($entityId);

        return $claim instanceof ChangeClaim ? [$this->claimChunk($claim)] : [];
    }

    private function singleImpact(int $organizationId, string|int $entityId): array
    {
        $impact = ChangeImpact::query()->with(['changeRequest.project'])->where('organization_id', $organizationId)->find($entityId);

        return $impact instanceof ChangeImpact ? [$this->impactChunk($impact)] : [];
    }

    private function singleApproval(int $organizationId, string|int $entityId): array
    {
        $approval = ChangeApproval::query()->with(['changeRequest.project'])->where('organization_id', $organizationId)->find($entityId);

        return $approval instanceof ChangeApproval ? [$this->approvalChunk($approval)] : [];
    }

    private function singleVariationOrder(int $organizationId, string|int $entityId): array
    {
        $variationOrder = VariationOrder::query()->with(['changeRequest.project'])->where('organization_id', $organizationId)->find($entityId);

        return $variationOrder instanceof VariationOrder ? [$this->variationOrderChunk($variationOrder)] : [];
    }

    private function chunk(
        int $organizationId,
        ?int $projectId,
        string $entityType,
        int $entityId,
        string $title,
        string $content,
        array $metadata,
        ?DateTimeInterface $updatedAt
    ): RagChunkData {
        return new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: $entityType,
            entityId: $entityId,
            title: $title,
            content: $content,
            metadata: $metadata,
            updatedAt: $updatedAt
        );
    }
}
