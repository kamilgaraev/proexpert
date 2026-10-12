<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\Models\EstimatePositionCatalog;
use App\Models\WorkType;

final readonly class ResolveAutomaticCommercialPrice
{
    public function __construct(private CataloguePriceSnapshotReader $catalog, private ConvertEvaluationPriceUnit $units = new ConvertEvaluationPriceUnit,
        private AutomaticCommercialPriceMatcher $matcher = new AutomaticCommercialPriceMatcher) {}

    public function resolve(int $organizationId, array $item): ?array
    {
        $name = $this->matcher->name($item['name'] ?? null);
        if ($name === null || ! is_string($item['unit'] ?? null) || $organizationId < 1) {
            return null;
        }
        $prices = [];
        foreach (['catalog', 'work_type'] as $type) {
            $query = ($type === 'catalog' ? EstimatePositionCatalog::query() : WorkType::query())
                ->where('organization_id', $organizationId)->where('is_active', true)
                ->whereRaw("lower(regexp_replace(trim(name), '[[:space:]]+', ' ', 'g')) = ?", [$name]);
            if ($type === 'catalog') {
                $query->where('item_type', $item['metadata']['ordinary_item_type'] ?? 'work');
            } elseif (($item['metadata']['ordinary_item_type'] ?? 'work') !== 'work') {
                continue;
            }
            $rows = $query->orderBy('id')->limit(21)->get(['id']);
            if ($rows->count() > 20) {
                return null;
            }
            foreach ($rows as $row) {
                $price = $this->catalog->read($organizationId, $type.':'.$row->id);
                $converted = $price === null ? null : $this->units->convert($price, $item['unit']);
                if ($converted !== null) {
                    $prices[] = $converted;
                }
            }
        }

        return $this->matcher->select($prices, $item);
    }
}
