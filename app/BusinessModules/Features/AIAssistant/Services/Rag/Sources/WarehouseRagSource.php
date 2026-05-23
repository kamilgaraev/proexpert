<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use BackedEnum;
use DateTimeInterface;
use Throwable;

final class WarehouseRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'warehouse';
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
        $query = ProjectMaterialDelivery::query()
            ->with(['project', 'material', 'warehouse', 'siteRequest', 'purchaseRequest'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');

        foreach ($query->cursor() as $delivery) {
            yield $this->chunk($delivery);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if (! in_array($entityType, ['project_material_delivery', 'warehouse'], true)) {
            return [];
        }

        $delivery = ProjectMaterialDelivery::query()
            ->with(['project', 'material', 'warehouse', 'siteRequest', 'purchaseRequest'])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $delivery instanceof ProjectMaterialDelivery ? [$this->chunk($delivery)] : [];
    }

    private function chunk(ProjectMaterialDelivery $delivery): RagChunkData
    {
        $content = $this->lines([
            'Поставка на проект: '.$this->stringValue($delivery->material?->name),
            'Проект: '.$this->stringValue($delivery->project?->name),
            'Склад: '.$this->stringValue($delivery->warehouse?->name),
            'Статус: '.$this->stringValue($delivery->status),
            'Запрошено: '.$this->quantityValue($delivery->requested_quantity),
            'Зарезервировано: '.$this->quantityValue($delivery->reserved_quantity),
            'Отгружено: '.$this->quantityValue($delivery->shipped_quantity),
            'Принято: '.$this->quantityValue($delivery->accepted_quantity),
            'Плановая доставка: '.$this->dateValue($delivery->planned_delivery_date),
            'Заявка с объекта: '.$this->stringValue($delivery->siteRequest?->title),
            'Закупка: '.$this->stringValue($delivery->purchaseRequest?->request_number),
            'Примечания: '.$this->stringValue($delivery->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $delivery->organization_id,
            projectId: (int) $delivery->project_id,
            sourceType: $this->sourceType(),
            entityType: 'project_material_delivery',
            entityId: (int) $delivery->id,
            title: 'Склад: '.$this->stringValue($delivery->material?->name),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($delivery->status),
                'material_id' => $delivery->material_id,
                'warehouse_id' => $delivery->warehouse_id,
                'site_request_id' => $delivery->site_request_id,
                'purchase_request_id' => $delivery->purchase_request_id,
            ],
            updatedAt: $delivery->updated_at
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

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
