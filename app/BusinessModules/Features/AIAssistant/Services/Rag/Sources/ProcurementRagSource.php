<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use BackedEnum;
use DateTimeInterface;
use Throwable;

final class ProcurementRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'procurement';
    }

    public function enabled(): bool
    {
        try {
            return (bool) config('ai-assistant.rag.enabled', false);
        } catch (Throwable) {
            return false;
        }
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
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if (! in_array($entityType, ['purchase_request', 'procurement'], true)) {
            return [];
        }

        $request = PurchaseRequest::query()
            ->with(['siteRequest.project', 'assignedUser', 'lines.material'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $request instanceof PurchaseRequest ? [$this->chunk($request)] : [];
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

    private function numberValue(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') : '';
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
