<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class NormativeResourcePriceTarget
{
    public const CONTRACT = 'normative-resource-price:v1';

    public function targets(array $item, string $generationId): array
    {
        $normId = $item['normative_match']['norm_id'] ?? null;
        if (! is_int($normId) || $normId < 1 || ! is_string($item['key'] ?? null) || $item['key'] === '') {
            return [];
        }
        $counts = [];
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            foreach ($item[$group] ?? [] as $resource) {
                $id = $resource['normative_ref']['norm_resource_id'] ?? null;
                if (is_int($id)) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }
        $targets = [];
        foreach ($item['materials'] ?? [] as $resource) {
            $reference = $resource['normative_ref'] ?? [];
            $id = $reference['norm_resource_id'] ?? null;
            if (! is_int($id) || $id < 1 || ($counts[$id] ?? 0) !== 1 || ($reference['norm_id'] ?? null) !== $normId
                || ($resource['quantity_basis'] ?? null) !== 'normative_resource' || ($resource['resource_type'] ?? null) !== 'material'
                || ! is_string($resource['name'] ?? null) || trim($resource['name']) === ''
                || ! is_string($resource['unit'] ?? null) || trim($resource['unit']) === ''
                || ! is_string($reference['resource_code'] ?? null) || ! array_key_exists('norm_source_resource_id', $reference)
                || ! $this->positiveDecimal($resource['quantity_per_unit'] ?? null)) {
                continue;
            }
            $identity = ['contract_version' => self::CONTRACT, 'generation_id' => $generationId,
                'work_item_key' => $item['key'], 'norm_id' => $normId, 'norm_resource_id' => $id,
                'resource_code' => $reference['resource_code'], 'norm_source_resource_id' => $reference['norm_source_resource_id'],
                'name' => $resource['name'], 'resource_type' => 'material', 'unit' => $resource['unit'],
                'quantity_per_unit' => (string) BigDecimal::of((string) $resource['quantity_per_unit'])->strippedOfTrailingZeros(),
                'project_resource_selection' => $reference['project_resource_selection'] ?? null,
                'scope_entity_id' => $item['metadata']['scope_entity_id'] ?? null,
                'scope_entity_binding' => $item['metadata']['scope_entity_binding'] ?? null,
                'opening_system' => $item['metadata']['opening_system'] ?? null];
            $targets[] = [...$identity, 'target_hash' => hash('sha256', CanonicalPipelineJson::encode($identity)),
                'quantity' => $resource['quantity'] ?? null, 'resource_key' => $resource['key'] ?? null];
        }

        return $targets;
    }

    public function find(array $item, string $generationId, int $normResourceId): array
    {
        foreach ($this->targets($item, $generationId) as $target) {
            if ($target['norm_resource_id'] === $normResourceId) {
                return $target;
            }
        }

        throw new InvalidArgumentException('normative_resource_price_target_unavailable');
    }

    public static function key(string $workKey, int $normId, int $normResourceId): string
    {
        return hash('sha256', $workKey."\0".$normId."\0".$normResourceId);
    }

    public function matchesCurrentRow(array $target, object $row): bool
    {
        return (int) $row->id === $target['norm_resource_id'] && (int) $row->estimate_norm_id === $target['norm_id']
            && trim((string) $row->resource_code) === $target['resource_code'] && (string) $row->resource_name === $target['name']
            && in_array($row->resource_type, ['material', 'equipment'], true) && (string) $row->unit === $target['unit']
            && ($row->construction_resource_id === null ? null : (int) $row->construction_resource_id) === $target['norm_source_resource_id']
            && $this->positiveDecimal($row->quantity)
            && BigDecimal::of((string) $row->quantity)->isEqualTo($target['quantity_per_unit']);
    }

    private function positiveDecimal(mixed $value): bool
    {
        return (is_string($value) || is_int($value))
            && preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', (string) $value) === 1
            && BigDecimal::of((string) $value)->isGreaterThan(0);
    }
}
