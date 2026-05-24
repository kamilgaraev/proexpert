<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use BackedEnum;
use DateTimeInterface;

final class PaymentRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'payment';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = PaymentDocument::query()
            ->with([
                'project',
                'contractor',
                'payerOrganization',
                'payerContractor',
                'payeeOrganization',
                'payeeContractor',
                'counterpartyOrganization',
                'siteRequests',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');

        foreach ($query->cursor() as $document) {
            yield $this->chunk($document);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'payment_document') {
            return [];
        }

        $document = PaymentDocument::query()
            ->with([
                'project',
                'contractor',
                'payerOrganization',
                'payerContractor',
                'payeeOrganization',
                'payeeContractor',
                'counterpartyOrganization',
                'siteRequests',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $document instanceof PaymentDocument ? [$this->chunk($document)] : [];
    }

    private function chunk(PaymentDocument $document): RagChunkData
    {
        $siteRequests = $document->siteRequests
            ->take(5)
            ->map(fn ($request): string => $this->stringValue($request->title ?? $request->request_number ?? $request->id))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Платежный документ: '.$this->stringValue($document->document_number),
            'Тип документа: '.$this->stringValue($document->document_type),
            'Направление: '.$this->stringValue($document->direction),
            'Проект: '.$this->stringValue($document->project?->name),
            'Контрагент: '.$this->stringValue($document->contractor?->name ?? $document->counterpartyOrganization?->name),
            'Плательщик: '.$this->payerName($document),
            'Получатель: '.$this->payeeName($document),
            'Статус: '.$this->stringValue($document->status),
            'Этап согласования: '.$this->stringValue($document->workflow_stage),
            'Сумма: '.$this->moneyValue($document->amount, $document->currency),
            'Оплачено: '.$this->moneyValue($document->paid_amount, $document->currency),
            'Остаток: '.$this->moneyValue($document->remaining_amount, $document->currency),
            'Дата документа: '.$this->dateValue($document->document_date),
            'Срок оплаты: '.$this->dateValue($document->due_date),
            'Просрочен с: '.$this->dateValue($document->overdue_since),
            'Источник: '.$this->stringValue($document->source_type).' '.$this->stringValue($document->source_id),
            'Связанные заявки: '.implode('; ', $siteRequests),
            'Назначение платежа: '.$this->stringValue($document->payment_purpose),
            'Описание: '.$this->stringValue($document->description),
            'Примечания: '.$this->stringValue($document->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $document->organization_id,
            projectId: $document->project_id !== null ? (int) $document->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'payment_document',
            entityId: (int) $document->id,
            title: 'Платеж: '.$this->stringValue($document->document_number),
            content: $content,
            metadata: [
                'project_id' => $document->project_id,
                'status' => $this->scalarValue($document->status),
                'workflow_stage' => $document->workflow_stage,
                'document_type' => $this->scalarValue($document->document_type),
                'direction' => $this->scalarValue($document->direction),
                'amount' => $this->numericValue($document->amount),
                'remaining_amount' => $this->numericValue($document->remaining_amount),
                'due_date' => $this->dateValue($document->due_date),
            ],
            updatedAt: $document->updated_at
        );
    }

    private function payerName(PaymentDocument $document): string
    {
        return $this->stringValue($document->payerOrganization?->name ?? $document->payerContractor?->name);
    }

    private function payeeName(PaymentDocument $document): string
    {
        return $this->stringValue($document->payeeOrganization?->name ?? $document->payeeContractor?->name);
    }

    /**
     * @param  array<int, string>  $lines
     */
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

    private function moneyValue(mixed $amount, mixed $currency = null): string
    {
        if (! is_numeric($amount)) {
            return '';
        }

        $suffix = $currency !== null ? ' '.$this->stringValue($currency) : '';

        return number_format((float) $amount, 2, '.', ' ').$suffix;
    }

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
