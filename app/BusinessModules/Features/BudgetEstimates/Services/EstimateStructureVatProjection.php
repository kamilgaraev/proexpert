<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\Request;

final class EstimateStructureVatProjection
{
    public function bindEstimate(Estimate $estimate, iterable $sections = [], iterable $items = []): void
    {
        $visited = new \SplObjectStorage;
        $bind = function (EstimateItem|EstimateSection $node) use (&$bind, $estimate, $visited): void {
            if ($visited->contains($node)) {
                return;
            }
            if ((int) $node->estimate_id !== (int) $estimate->id) {
                throw new DomainException('estimate_structure_scope_mismatch');
            }
            $visited->attach($node);
            $node->setRelation('estimate', $estimate);
            foreach (['items', 'children', 'childItems'] as $relation) {
                if ($node->relationLoaded($relation)) {
                    foreach ($node->getRelation($relation) as $child) {
                        $bind($child);
                    }
                }
            }
        };
        foreach ($sections as $section) {
            $bind($section);
        }
        foreach ($items as $item) {
            $bind($item);
        }
    }

    public function item(Estimate $estimate, EstimateItem $item): float
    {
        return $this->itemAmount($estimate, $item)->toFloat();
    }

    public function section(Request $request, EstimateSection $section): float
    {
        $estimate = $section->estimate;
        if (! $estimate) {
            return (float) $section->section_total_amount;
        }
        if (! $this->usesSources($estimate)) {
            return round((float) $section->section_total_amount * (1 + (float) $estimate->vat_rate / 100), 2);
        }

        $key = self::class.':'.$estimate->organization_id.':'.$estimate->id;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->project($estimate, $estimate->items()->get(), $estimate->sections()->get())['sections']);
        }

        return $request->attributes->get($key)[$section->id] ?? 0.0;
    }

    public function project(Estimate $estimate, iterable $items, iterable $sections): array
    {
        $sourceVat = $this->usesSources($estimate);
        $itemAmounts = [];
        $sectionAmounts = [];
        $children = [];
        foreach ($sections as $section) {
            $id = (int) $section['id'];
            $sectionAmounts[$id] = $sourceVat ? BigDecimal::zero()
                : BigDecimal::of((string) round((float) $section['section_total_amount'] * (1 + (float) $estimate->vat_rate / 100), 2));
            if ($section['parent_section_id'] !== null) {
                $children[(int) $section['parent_section_id']][] = $id;
            }
        }

        foreach ($items as $attributes) {
            if (! $sourceVat) {
                $itemAmounts[(int) $attributes['id']] = round((float) $attributes['total_amount'] * (1 + (float) $estimate->vat_rate / 100), 2);

                continue;
            }
            $item = $attributes instanceof EstimateItem ? $attributes : (new EstimateItem)->newFromBuilder($attributes);
            $amount = $this->itemAmount($estimate, $item);
            $itemAmounts[(int) $item->id] = $amount->toFloat();
            if ($sourceVat && $item->parent_work_id === null && ! $item->is_not_accounted && isset($sectionAmounts[$item->estimate_section_id])) {
                $id = (int) $item->estimate_section_id;
                $sectionAmounts[$id] = $sectionAmounts[$id]->plus($amount);
            }
        }

        $visited = [];
        $visiting = [];
        $sum = function (int $id) use (&$sum, &$sectionAmounts, &$visited, &$visiting, $children): BigDecimal {
            if (isset($visiting[$id])) {
                throw new DomainException('estimate_section_cycle');
            }
            if (! isset($visited[$id])) {
                $visiting[$id] = true;
                foreach ($children[$id] ?? [] as $childId) {
                    $sectionAmounts[$id] = $sectionAmounts[$id]->plus($sum($childId));
                }
                unset($visiting[$id]);
                $visited[$id] = true;
            }

            return $sectionAmounts[$id];
        };
        foreach (array_keys($sectionAmounts) as $id) {
            if ($sourceVat) {
                $sum($id);
            }
        }

        return ['items' => $itemAmounts, 'sections' => array_map(static fn (BigDecimal $amount): float => $amount->toFloat(), $sectionAmounts)];
    }

    private function itemAmount(Estimate $estimate, EstimateItem $item): BigDecimal
    {
        if ($this->usesSources($estimate) && $item->parent_work_id === null && ! $item->is_not_accounted) {
            return BigDecimal::of(app(EstimateSourceVatCalculator::class)->line($estimate, $item)['gross_amount']);
        }

        return BigDecimal::of((string) round((float) $item->total_amount * (1 + (float) $estimate->vat_rate / 100), 2));
    }

    private function usesSources(Estimate $estimate): bool
    {
        return ($estimate->metadata['vat_calculation_method'] ?? null) === EstimateSourceVatCalculator::METHOD;
    }
}
