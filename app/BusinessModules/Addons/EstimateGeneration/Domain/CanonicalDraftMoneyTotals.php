<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain;

use Brick\Math\BigDecimal;

/** Preserves exact validated money through summary/review projection, before the final business seal. */
final class CanonicalDraftMoneyTotals
{
    public function project(array $draft): array
    {
        if (($draft['generation_contract'] ?? null) !== 'most_ordinary_estimate:v1') {
            return $draft;
        }
        $base = BigDecimal::zero();
        $count = 0;
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $estimate) {
            $local = BigDecimal::zero();
            foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                $subtotal = BigDecimal::zero();
                foreach ($section['work_items'] ?? [] as $item) {
                    $count++;
                    if (in_array($item['item_type'] ?? 'priced_work', ['operation', 'resource_note', 'review_note', 'quantity_review'], true)) {
                        continue;
                    }
                    if (($item['total_cost'] ?? null) === null) {
                        throw new \DomainException('canonical_draft_money_unknown');
                    }
                    $subtotal = $subtotal->plus(OrdinaryEstimateDecimal::money($item['total_cost']));
                }
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['section_totals'] = [
                    'total_cost' => OrdinaryEstimateDecimal::money($subtotal), 'items_count' => count($section['work_items'] ?? [])];
                $local = $local->plus($subtotal);
            }
            $draft['local_estimates'][$localIndex]['totals'] = ['total_cost' => OrdinaryEstimateDecimal::money($local),
                'sections_count' => count($estimate['sections'] ?? [])];
            $base = $base->plus($local);
        }
        $contingency = (new EstimateContingencyCalculator)->amount($draft, (string) $base);
        $draft['contingency_amount'] = $contingency;
        $draft['totals'] = ['total_cost' => OrdinaryEstimateDecimal::money($base->plus($contingency)),
            'base_total_cost' => OrdinaryEstimateDecimal::money($base),
            'contingency' => [...($draft['totals']['contingency'] ?? []), 'amount' => $contingency],
            'local_estimates_count' => count($draft['local_estimates'] ?? []), 'work_items_count' => $count];

        return $draft;
    }
}
