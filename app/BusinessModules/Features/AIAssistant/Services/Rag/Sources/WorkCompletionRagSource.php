<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\CompletedWork;
use BackedEnum;
use DateTimeInterface;

final class WorkCompletionRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'work_completion';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = CompletedWork::query()
            ->with(['project', 'workType', 'contractor', 'user'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');

        foreach ($query->cursor() as $work) {
            yield $this->chunk($work);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if (! in_array($entityType, ['completed_work', 'work_completion'], true)) {
            return [];
        }

        $work = CompletedWork::query()
            ->with(['project', 'workType', 'contractor', 'user'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $work instanceof CompletedWork ? [$this->chunk($work)] : [];
    }

    private function chunk(CompletedWork $work): RagChunkData
    {
        $content = $this->lines([
            'Выполненная работа: '.$this->stringValue($work->workType?->name),
            'Проект: '.$this->stringValue($work->project?->name),
            'Статус: '.$this->stringValue($work->status),
            'Количество: '.$this->quantityValue($work->completed_quantity ?? $work->quantity),
            'Цена: '.$this->moneyValue($work->price),
            'Сумма: '.$this->moneyValue($work->total_amount),
            'Дата выполнения: '.$this->dateValue($work->completion_date),
            'Подрядчик: '.$this->stringValue($work->contractor?->name),
            'Исполнитель: '.$this->stringValue($work->user?->name),
            'Примечания: '.$this->stringValue($work->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $work->organization_id,
            projectId: (int) $work->project_id,
            sourceType: $this->sourceType(),
            entityType: 'completed_work',
            entityId: (int) $work->id,
            title: 'Работа: '.$this->stringValue($work->workType?->name),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($work->status),
                'work_type_id' => $work->work_type_id,
                'contract_id' => $work->contract_id,
                'contractor_id' => $work->contractor_id,
            ],
            updatedAt: $work->updated_at
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

    private function quantityValue(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') : '';
    }

    private function moneyValue(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', ' ') : '';
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
