<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;
use Closure;
use InvalidArgumentException;

final class UniversalDraftProjector
{
    public const CONTRACT = 'most_universal_evaluation:v1';

    public function project(array $draft, Closure $quantityAccepted): array
    {
        $count = 0;
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $estimate) {
            foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                    if (! is_array($item) || ++$count > 10000) {
                        throw new InvalidArgumentException('evaluation_rows_require_section_split');
                    }
                    if (($item['item_type'] ?? null) === 'quantity_review'
                        && is_string($item['key'] ?? null) && $item['key'] !== ''
                        && is_string($item['name'] ?? null) && trim($item['name']) !== ''
                        && is_string($item['metadata']['quantity_key'] ?? null)
                        && ((is_string($item['unit'] ?? null) && WorkQuantityInputUnit::parse($item['unit']) !== null)
                            || (($item['unit'] ?? null) === null && in_array('unit_requires_confirmation', $item['validation_flags'] ?? [], true)))) {
                        // Retain a real planned work with unknown volume so a user can supply its basis.
                        // Notes and nested operations remain non-payable rows.
                        $item['item_type'] = 'priced_work';
                        $item['metadata']['display_role'] = 'work';
                        $item['metadata']['requires_quantity_confirmation'] = true;
                    }
                    $quantity = is_array($item['quantity_evidence'] ?? null) ? $item['quantity_evidence'] : null;
                    $amount = null;
                    if ($quantity !== null && $quantityAccepted($item)) {
                        $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal((string) ($quantity['unit'] ?? ''), (string) ($item['unit'] ?? ''));
                        if ($factor !== null && is_string($quantity['amount'] ?? null)) {
                            $amount = (string) BigDecimal::of($quantity['amount'])->multipliedBy($factor);
                        }
                    }
                    $item['quantity'] = $amount;
                    $item['total_cost'] = null;
                    $item['unit_price'] = null;
                    $item['evaluation_section'] = EvaluationSectionMap::forPackage((string) $estimate['key']);
                    $item['quantity_status'] = $amount === null ? 'unknown' : (($quantity['assumptions'] ?? []) !== [] ? 'assumed' : 'supported');
                    $item['quantity_basis_details'] = $quantity;
                    $item['pricing_status'] = 'not_calculated';
                    // A legacy zero total is never evidence of a free work item.
                    $item['pricing_blocker'] = $amount === null ? 'quantity_unknown' : 'price_unknown';
                    unset($item['prompt'], $item['raw_document'], $item['document_content']);
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $item;
                }
            }
        }
        $draft['generation_contract'] = self::CONTRACT;
        $draft['stage6_candidate_rows'] = [];
        $draft['evaluation_rows_count'] = $count;

        return $draft;
    }
}
