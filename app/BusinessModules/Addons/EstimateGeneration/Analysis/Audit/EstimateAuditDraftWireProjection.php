<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

/** Remove only identical proof copies; keep the business artifact untouched. */
final class EstimateAuditDraftWireProjection
{
    public static function project(array $draft, array $quantities): array
    {
        $basisReferences = [];
        foreach (array_values($quantities) as $index => $quantity) {
            if (is_array($quantity['quantity_basis'] ?? null)) {
                $encoded = CanonicalPipelineJson::encode($quantity['quantity_basis']);
                $basisReferences[hash('sha256', $encoded)] = [
                    'encoded' => $encoded,
                    'reference' => 'audit_input.derived_quantities['.$index.'].quantity_basis',
                ];
            }
        }
        if (is_array($draft['estimate_composition']['derived_quantities'] ?? null)
            && self::same($draft['estimate_composition']['derived_quantities'], $quantities)) {
            unset($draft['estimate_composition']['derived_quantities']);
            $draft['estimate_composition']['derived_quantities_ref'] = 'audit_input.derived_quantities';
        }
        if (is_array($draft['building_quantities']['quantities'] ?? null) && $quantities !== []
            && self::same(array_column($quantities, 'quantity_basis'), $draft['building_quantities']['quantities'])
            && count(array_column($quantities, 'quantity_basis')) === count($quantities)) {
            unset($draft['building_quantities']['quantities']);
            $draft['building_quantities']['quantities_ref'] = 'audit_input.derived_quantities[*].quantity_basis';
        }
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                    $originalItem = $item;
                    $quantityProjected = false;
                    if (is_array($item['quantity_evidence'] ?? null)
                        && is_array($item['quantity_basis_details'] ?? null)
                        && self::same($item['quantity_evidence'], $item['quantity_basis_details'])) {
                        $quantityProjected = true;
                        unset($item['quantity_evidence']);
                        $encoded = CanonicalPipelineJson::encode($item['quantity_basis_details']);
                        $basisReference = $basisReferences[hash('sha256', $encoded)] ?? null;
                        if ($basisReference !== null && $basisReference['encoded'] === $encoded) {
                            unset($item['quantity_basis_details']);
                            $item['quantity_evidence_ref'] = $basisReference['reference'];
                            $item['quantity_basis_details_ref'] = $basisReference['reference'];
                        } else {
                            $item['quantity_evidence_ref'] = 'quantity_basis_details';
                        }
                    }
                    if (is_array($item['work_composition'] ?? null)) {
                        $reference = 'audit_input.draft.local_estimates['.$localIndex.'].sections['.$sectionIndex.'].work_items['.$itemIndex.'].work_composition';
                        foreach (['metadata', 'normative_match'] as $field) {
                            if (is_array($item[$field] ?? null)) {
                                $item[$field] = self::reuseComposition($item[$field], $item['work_composition'], $reference);
                            }
                        }
                        foreach ($item['normative_candidates'] ?? [] as $candidateIndex => $candidate) {
                            if (is_array($candidate)) {
                                $item['normative_candidates'][$candidateIndex] = self::reuseComposition($candidate, $item['work_composition'], $reference);
                            }
                        }
                    }
                    if ($item !== $originalItem) {
                        $item['item_fingerprint'] = ApplyComposerCorrectionCycle::itemFingerprint($originalItem);
                        if ($quantityProjected || strlen(CanonicalPipelineJson::encode($item)) < strlen(CanonicalPipelineJson::encode($originalItem))) {
                            $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $item;
                        }
                    }
                }
            }
        }

        return $draft;
    }

    public static function forContextPart(array $draft, array $allQuantities, array $quantities): array
    {
        if (is_array($draft['estimate_composition']['derived_quantities'] ?? null)
            && self::same($draft['estimate_composition']['derived_quantities'], $allQuantities)) {
            $draft['estimate_composition']['derived_quantities'] = $quantities;
        }
        $bases = array_column($allQuantities, 'quantity_basis');
        if ($bases !== [] && count($bases) === count($allQuantities)
            && is_array($draft['building_quantities']['quantities'] ?? null)
            && self::same($draft['building_quantities']['quantities'], $bases)) {
            $draft['building_quantities']['quantities'] = array_column($quantities, 'quantity_basis');
        }

        return $draft;
    }

    private static function same(array $left, array $right): bool
    {
        return CanonicalPipelineJson::encode($left) === CanonicalPipelineJson::encode($right);
    }

    private static function reuseComposition(array $record, array $composition, string $reference): array
    {
        if (is_array($record['work_composition'] ?? null)
            && ! array_key_exists('work_composition_ref', $record)
            && self::same($record['work_composition'], $composition)
            && strlen(CanonicalPipelineJson::encode($composition)) > strlen(CanonicalPipelineJson::encode([$reference])) - 2 + strlen('_ref')) {
            unset($record['work_composition']);
            $record['work_composition_ref'] = $reference;
        }

        return $record;
    }
}
