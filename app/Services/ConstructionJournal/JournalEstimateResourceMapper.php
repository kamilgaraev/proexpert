<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use Illuminate\Support\Collection;

final class JournalEstimateResourceMapper
{
    public function map(EstimateItem $parent, int $organizationId): array
    {
        $parent->loadMissing(['resources.measurementUnit', 'resources.material', 'childItems.measurementUnit', 'childItems.material', 'measurementUnit']);
        $parentUnit = $this->scopedUnit($parent->measurementUnit, $organizationId);
        $parentBasisValid = $parentUnit && $this->hasMatchingSourceUnit($parent, $parentUnit);
        $plannedQuantity = $this->number($parent->quantity_total ?? $parent->quantity);
        $children = $parent->childItems->filter(fn (EstimateItem $child): bool => (int) $child->estimate_id === (int) $parent->estimate_id
            && (int) $child->parent_work_id === (int) $parent->id
            && $this->resourceTypeForItem($child, $organizationId) !== null);
        $validChildIds = $children->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $represented = [];
        $signatures = [];
        $result = [];
        foreach ($parent->resources as $resource) {
            $unit = $this->scopedUnit($resource->measurementUnit, $organizationId);
            $childId = in_array((int) $resource->represented_by_item_id, $validChildIds, true) ? (int) $resource->represented_by_item_id : null;
            $material = $resource->material && (int) $resource->material->organization_id === $organizationId ? $resource->material : null;
            $type = $this->normalizeType($resource->resource_type, $unit);
            $representedChild = $childId !== null ? $children->firstWhere('id', $childId) : null;
            $childUnit = $representedChild ? $this->scopedUnit($representedChild->measurementUnit, $organizationId) : null;
            if (in_array($resource->resource_type, ['equipment', 'machinery'], true) && $representedChild && $unit && $childUnit
                && $this->normalizeUnit($unit->short_name ?: $unit->name) === $this->normalizeUnit($childUnit->short_name ?: $childUnit->name)) {
                $childType = $this->resourceTypeForItem($representedChild, $organizationId);
                if ($childType === 'material') {
                    $type = 'material';
                } elseif (in_array($representedChild->item_type?->value, ['equipment', 'machinery'], true)) {
                    $type = $this->normalizeType($resource->resource_type, $unit, $representedChild);
                }
            }
            $mapped = [
                'id' => $resource->id,
                'source_key' => 'estimate_item_resource:'.$resource->id,
                'resource_type' => $type,
                'name' => $resource->name ?: $material?->name,
                'measurement_unit_id' => $unit?->id,
                'measurementUnit' => $this->unitPayload($unit),
                'unit_name' => $unit?->short_name ?: $unit?->name,
                'quantity_per_unit' => $unit && $parentBasisValid && $this->canForecastInUnit($type, $unit) ? $this->number($resource->quantity_per_unit) : null,
                'total_quantity' => $this->number($resource->total_quantity),
                'estimate_item_id' => $childId,
                'material_id' => $material?->id,
            ];
            $result[] = $mapped;
            if ($childId !== null) {
                $represented[$childId] = true;
            } else {
                $signature = $this->signature($mapped);
                $signatures[$signature] = ($signatures[$signature] ?? 0) + 1;
            }
        }
        $excelLayout = $this->isVerifiedExcelLayout($parent, $children, $organizationId);
        foreach ($children as $child) {
            if (isset($represented[(int) $child->id])) {
                continue;
            }
            $unit = $this->scopedUnit($child->measurementUnit, $organizationId);
            $material = $child->material && (int) $child->material->organization_id === $organizationId ? $child->material : null;
            $metadata = $child->metadata ?? [];
            $raw = is_array($metadata['raw_data'] ?? null) ? $metadata['raw_data'] : [];
            $norm = $unit && $parentBasisValid && $this->hasMatchingSourceUnit($child, $unit)
                ? $this->number($metadata['quantity_per_unit'] ?? null)
                : null;
            $explicitNorm = $norm !== null;
            if ($norm === null && $excelLayout) {
                $norm = $this->number($this->number($raw['I']) * ($this->number($raw['J'] ?? 1) ?? 1));
            }
            $type = $this->resourceTypeForItem($child, $organizationId);
            $mapped = [
                'id' => -(int) $child->id,
                'source_key' => 'estimate_item:'.$child->id,
                'resource_type' => $type,
                'name' => $child->name,
                'measurement_unit_id' => $unit?->id,
                'measurementUnit' => $this->unitPayload($unit),
                'unit_name' => $unit?->short_name ?: ($unit?->name ?: ($metadata['original_unit'] ?? null)),
                'quantity_per_unit' => $unit && $this->canForecastInUnit($type, $unit) ? $norm : null,
                'total_quantity' => $explicitNorm && $plannedQuantity !== null ? $this->number($norm * $plannedQuantity)
                    : ($excelLayout ? $this->number($raw['K']) : null),
                'estimate_item_id' => $child->id,
                'material_id' => $material?->id,
            ];
            $signature = $this->signature($mapped);
            if (($signatures[$signature] ?? 0) > 0) {
                $signatures[$signature]--;

                continue;
            }
            $result[] = $mapped;
        }

        return $result;
    }

    public function resourceTypeForItem(EstimateItem $item, int $organizationId): ?string
    {
        $metadata = $item->metadata ?? [];
        if (filter_var($metadata['is_informative_row'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        $type = $item->item_type?->value;
        if (! in_array($type, ['material', 'labor', 'equipment', 'machinery'], true)) {
            return null;
        }
        $item->loadMissing('measurementUnit');

        return $this->normalizeType($type, $this->scopedUnit($item->measurementUnit, $organizationId), $item);
    }

    private function isVerifiedExcelLayout(EstimateItem $parent, Collection $children, int $organizationId): bool
    {
        $parentUnit = $this->scopedUnit($parent->measurementUnit, $organizationId);
        $raw = $parent->metadata['raw_data'] ?? null;
        $quantity = $this->number($parent->quantity_total ?? $parent->quantity);
        if (! $parentUnit || ! is_array($raw) || $quantity === null || $quantity <= 0
            || ! $this->hasMatchingSourceUnit($parent, $parentUnit, requireRaw: true)
            || $this->number($raw['J'] ?? null) !== 1.0
            || ! $this->sameDecimal($this->number($raw['I'] ?? null), $quantity)
            || ! $this->sameDecimal($this->number($raw['K'] ?? null), $quantity)
            || $children->isEmpty()) {
            return false;
        }
        $positive = false;
        foreach ($children as $child) {
            $unit = $this->scopedUnit($child->measurementUnit, $organizationId);
            $raw = $child->metadata['raw_data'] ?? null;
            if (! $unit || ! is_array($raw) || ! $this->hasMatchingSourceUnit($child, $unit, requireRaw: true)) {
                return false;
            }
            $norm = $this->number($raw['I'] ?? null);
            $coefficient = $this->number($raw['J'] ?? 1);
            $total = $this->number($raw['K'] ?? null);
            $stored = $this->number($child->quantity_total ?? $child->quantity);
            if ($norm === null || $coefficient === null || $coefficient <= 0 || $total === null
                || ! $this->sameDecimal($total, $stored)
                || ! $this->sameDecimal($this->number($norm * $coefficient * $quantity), $total)) {
                return false;
            }
            $positive = $positive || $total > 0;
        }

        return $positive;
    }

    private function hasMatchingSourceUnit(EstimateItem $item, MeasurementUnit $unit, bool $requireRaw = false): bool
    {
        $metadata = $item->metadata ?? [];
        $original = $metadata['original_unit'] ?? null;
        $scoped = $this->normalizeUnit($unit->short_name ?: $unit->name);
        if ($original !== null && $this->normalizeUnit((string) $original) !== $scoped) {
            return false;
        }

        return ! $requireRaw || ($original !== null && $scoped !== ''
            && $this->normalizeUnit((string) ($metadata['raw_data']['H'] ?? '')) === $scoped);
    }

    private function normalizeType(string $type, ?MeasurementUnit $unit, ?EstimateItem $source = null): string
    {
        if (in_array($type, ['equipment', 'machinery'], true)) {
            if (! $unit) {
                return 'equipment';
            }
            $normalized = $this->normalizeUnit($unit->short_name ?: $unit->name);
            $code = $source ? trim((string) $source->normative_rate_code) : '';
            $sourceProven = $source && $this->hasMatchingSourceUnit($source, $unit, requireRaw: true)
                && trim((string) ($source->metadata['raw_data']['B'] ?? '')) === $code;
            if ($sourceProven && preg_match('/^91\.[0-9]{2}\.[0-9]{2}-[0-9]{3}$/D', $code) === 1) {
                return 'equipment';
            }
            if (preg_match('/^(?:[1-9][0-9]*(?:[.,][0-9]+)?)?(?:т|кг|г|мм|см|дм|м|км|м2|м3|л|мл)$/uD', $normalized) === 1) {
                return 'material';
            }
            if ($sourceProven && in_array($normalized, ['шт', 'штука', 'штук'], true)
                && preg_match('/^(?:0[1-9]|[1-8][0-9])\.[0-9]\.[0-9]{2}\.[0-9]{2}-[0-9]{4}$/D', $code) === 1) {
                return 'material';
            }

            return 'equipment';
        }

        return $type;
    }

    private function canForecastInUnit(string $type, MeasurementUnit $unit): bool
    {
        $normalized = $this->normalizeUnit($unit->short_name ?: $unit->name);

        return match ($type) {
            'labor' => in_array($normalized, ['челч', 'ч', 'час', 'часов', 'h', 'hour', 'hours'], true),
            'equipment' => in_array($normalized, ['машч', 'ч', 'час', 'часов', 'h', 'hour', 'hours'], true),
            default => true,
        };
    }

    private function scopedUnit(?MeasurementUnit $unit, int $organizationId): ?MeasurementUnit
    {
        return $unit && (int) $unit->organization_id === $organizationId ? $unit : null;
    }

    private function unitPayload(?MeasurementUnit $unit): ?array
    {
        return $unit ? ['id' => $unit->id, 'name' => $unit->name, 'short_name' => $unit->short_name] : null;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower(strtr($unit, ['²' => '2', '³' => '3']), 'UTF-8');
        $unit = (string) preg_replace('/[\s.\-]/u', '', $unit);
        $unit = (string) preg_replace('/^([0-9]+(?:[.,][0-9]+)?)?m([23]?)$/', '$1м$2', $unit);

        return match ($unit) {
            'm2' => 'м2', '100m2' => '100м2', 'm3' => 'м3',
            'погм', 'погонныйметр' => 'м',
            'челчас', 'человекочас' => 'челч',
            'машчас', 'машиночас' => 'машч',
            'тонна', 'тонн', 't' => 'т',
            default => $unit,
        };
    }

    private function number(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) && $value >= 0 ? round($value, 8) : null;
    }

    private function sameDecimal(?float $left, ?float $right): bool
    {
        return $left !== null && $right !== null && number_format($left, 8, '.', '') === number_format($right, 8, '.', '');
    }

    private function signature(array $resource): string
    {
        return $resource['resource_type'].'|'.mb_strtolower(trim((string) $resource['name']), 'UTF-8').'|'.(string) $resource['measurement_unit_id'].'|'.(string) $resource['material_id'];
    }
}
