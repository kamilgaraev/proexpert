<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class MarketFallbackPricingService
{
    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $workItem
     * @return array{materials: array<int, array<string, mixed>>, labor: array<int, array<string, mixed>>, machinery: array<int, array<string, mixed>>}
     */
    public function resourcesForTemplate(array $template, array $workItem): array
    {
        $quantity = max((float) ($workItem['quantity'] ?? 0), 0.0);
        $price = max((float) ($template['fallback_unit_price'] ?? 0), 0.0);
        $mix = $this->normalizeMix($template['fallback_mix'] ?? []);

        return [
            'materials' => $this->resourcePack($workItem, 'material', $quantity, $price, $mix['material'], $template['fallback_material_name'] ?? null),
            'labor' => $this->resourcePack($workItem, 'labor', $quantity, $price, $mix['labor'], $template['fallback_labor_name'] ?? null),
            'machinery' => $this->resourcePack($workItem, 'machinery', $quantity, $price, $mix['machinery'], $template['fallback_machinery_name'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $workItem
     * @return array<int, array<string, mixed>>
     */
    private function resourcePack(array $workItem, string $type, float $quantity, float $unitPrice, float $share, ?string $name): array
    {
        if ($quantity <= 0 || $unitPrice <= 0 || $share <= 0) {
            return [];
        }

        $resourceUnitPrice = round($unitPrice * $share, 2);

        return [
            [
                'key' => ($workItem['key'] ?? 'work') . '-market-' . $type,
                'name' => $name ?: $this->defaultName($workItem, $type),
                'resource_type' => $type,
                'unit' => $workItem['unit'] ?? 'ед',
                'quantity' => $quantity,
                'quantity_per_unit' => 1,
                'quantity_basis' => 'market_estimate',
                'unit_price' => $resourceUnitPrice,
                'total_price' => round($quantity * $resourceUnitPrice, 2),
                'source' => 'market_estimate',
                'confidence' => 0.45,
                'normative_ref' => null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $mix
     * @return array{material: float, labor: float, machinery: float}
     */
    private function normalizeMix(array $mix): array
    {
        $material = max((float) ($mix['material'] ?? 0.6), 0.0);
        $labor = max((float) ($mix['labor'] ?? 0.3), 0.0);
        $machinery = max((float) ($mix['machinery'] ?? 0.1), 0.0);
        $total = $material + $labor + $machinery;

        if ($total <= 0) {
            return ['material' => 0.6, 'labor' => 0.3, 'machinery' => 0.1];
        }

        return [
            'material' => $material / $total,
            'labor' => $labor / $total,
            'machinery' => $machinery / $total,
        ];
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function defaultName(array $workItem, string $type): string
    {
        $workName = (string) ($workItem['name'] ?? 'работа');

        return match ($type) {
            'material' => 'Материалы: ' . $workName,
            'labor' => 'Работы: ' . $workName,
            'machinery' => 'Машины и механизмы: ' . $workName,
            default => $workName,
        };
    }
}
