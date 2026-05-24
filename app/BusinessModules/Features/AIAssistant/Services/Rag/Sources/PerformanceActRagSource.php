<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use BackedEnum;
use DateTimeInterface;

final class PerformanceActRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'performance_act';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = ContractPerformanceAct::query()
            ->with([
                'contract.contractor',
                'project',
                'lines.completedWork.workType',
                'lines.estimateItem',
            ])
            ->where(static function ($query) use ($organizationId): void {
                $query
                    ->whereHas('project', static fn ($projectQuery) => $projectQuery->where('organization_id', $organizationId))
                    ->orWhereHas('contract', static fn ($contractQuery) => $contractQuery->where('organization_id', $organizationId));
            })
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');

        foreach ($query->cursor() as $act) {
            yield $this->chunk($act, $organizationId);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'performance_act') {
            return [];
        }

        $act = ContractPerformanceAct::query()
            ->with([
                'contract.contractor',
                'project',
                'lines.completedWork.workType',
                'lines.estimateItem',
            ])
            ->where('id', $entityId)
            ->where(static function ($query) use ($organizationId): void {
                $query
                    ->whereHas('project', static fn ($projectQuery) => $projectQuery->where('organization_id', $organizationId))
                    ->orWhereHas('contract', static fn ($contractQuery) => $contractQuery->where('organization_id', $organizationId));
            })
            ->first();

        return $act instanceof ContractPerformanceAct ? [$this->chunk($act, $organizationId)] : [];
    }

    private function chunk(ContractPerformanceAct $act, int $organizationId): RagChunkData
    {
        $lines = $act->lines
            ->take(8)
            ->map(fn (PerformanceActLine $line): string => trim(sprintf(
                '%s %s %s x %s = %s',
                $this->stringValue($line->title ?? $line->completedWork?->workType?->name ?? $line->estimateItem?->name),
                $this->quantityValue($line->quantity),
                $this->stringValue($line->unit),
                $this->moneyValue($line->unit_price),
                $this->moneyValue($line->amount)
            )))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Акт выполненных работ: '.$this->stringValue($act->act_document_number),
            'Проект: '.$this->stringValue($act->project?->name),
            'Договор: '.$this->stringValue($act->contract?->number),
            'Подрядчик: '.$this->stringValue($act->contract?->contractor?->name),
            'Дата акта: '.$this->dateValue($act->act_date),
            'Период: '.$this->dateValue($act->period_start).' - '.$this->dateValue($act->period_end),
            'Статус: '.$this->stringValue($act->status),
            'Утвержден: '.($act->is_approved ? 'да' : 'нет'),
            'Сумма: '.$this->moneyValue($act->amount),
            'Передан: '.$this->dateValue($act->submitted_at),
            'Подписан: '.$this->dateValue($act->signed_at),
            'Причина отклонения: '.$this->stringValue($act->rejection_reason),
            'Описание: '.$this->stringValue($act->description),
            'Строки акта: '.implode('; ', $lines),
        ]);

        return new RagChunkData(
            organizationId: $organizationId,
            projectId: $act->project_id !== null ? (int) $act->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'performance_act',
            entityId: (int) $act->id,
            title: 'Акт: '.$this->stringValue($act->act_document_number),
            content: $content,
            metadata: [
                'project_id' => $act->project_id,
                'contract_id' => $act->contract_id,
                'status' => $this->scalarValue($act->status),
                'amount' => $this->numericValue($act->amount),
                'is_approved' => (bool) $act->is_approved,
            ],
            updatedAt: $act->updated_at
        );
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

    private function quantityValue(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') : '';
    }

    private function moneyValue(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', ' ') : '';
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
