<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Contract;
use BackedEnum;
use DateTimeInterface;
use Throwable;

final class ContractRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'contract';
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
        $query = Contract::query()
            ->with(['contractor', 'project', 'activeAllocations.project'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static function ($query) use ($projectId): void {
                $query->where(static function ($scope) use ($projectId): void {
                    $scope
                        ->where('project_id', $projectId)
                        ->orWhereHas('activeAllocations', static fn ($allocationQuery) => $allocationQuery->where('project_id', $projectId));
                });
            })
            ->orderBy('id');

        foreach ($query->cursor() as $contract) {
            yield $this->chunk($contract, $projectId);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'contract') {
            return [];
        }

        $contract = Contract::query()
            ->with(['contractor', 'project', 'activeAllocations.project'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $contract instanceof Contract ? [$this->chunk($contract)] : [];
    }

    private function chunk(Contract $contract, ?int $requestedProjectId = null): RagChunkData
    {
        $projectNames = $this->projectNames($contract);
        $content = implode("\n", array_filter([
            'Договор: '.$this->stringValue($contract->number),
            'Предмет: '.$this->stringValue($contract->subject),
            'Подрядчик: '.$this->stringValue($contract->contractor?->name),
            'Статус: '.$this->stringValue($contract->status),
            'Сумма: '.$this->moneyValue($contract->total_amount ?? $contract->base_amount),
            'Дата договора: '.$this->dateValue($contract->date),
            'Период: '.$this->dateValue($contract->start_date).' - '.$this->dateValue($contract->end_date),
            'Проекты: '.implode(', ', $projectNames),
        ], static fn (string $line): bool => ! str_ends_with($line, ': ') && ! str_ends_with($line, ': -')));

        return new RagChunkData(
            organizationId: (int) $contract->organization_id,
            projectId: $requestedProjectId ?? ($contract->project_id !== null ? (int) $contract->project_id : null),
            sourceType: $this->sourceType(),
            entityType: 'contract',
            entityId: (int) $contract->id,
            title: 'Договор: '.$this->stringValue($contract->number),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($contract->status),
                'contractor_id' => $contract->contractor_id,
                'project_ids' => $this->projectIds($contract),
            ],
            updatedAt: $contract->updated_at
        );
    }

    /**
     * @return array<int, string>
     */
    private function projectNames(Contract $contract): array
    {
        $names = [];

        if ($contract->project?->name !== null) {
            $names[] = $contract->project->name;
        }

        foreach ($contract->activeAllocations as $allocation) {
            if ($allocation->project?->name !== null) {
                $names[] = $allocation->project->name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, int>
     */
    private function projectIds(Contract $contract): array
    {
        $ids = [];

        if ($contract->project_id !== null) {
            $ids[] = (int) $contract->project_id;
        }

        foreach ($contract->activeAllocations as $allocation) {
            if ($allocation->project_id !== null) {
                $ids[] = (int) $allocation->project_id;
            }
        }

        return array_values(array_unique($ids));
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

    private function moneyValue(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', ' ') : '';
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
