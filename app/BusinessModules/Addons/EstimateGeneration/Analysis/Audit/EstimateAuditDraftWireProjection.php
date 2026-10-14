<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

/** Remove only identical copies of quantity proofs; keep the business artifact untouched. */
final class EstimateAuditDraftWireProjection
{
    public static function project(array $draft, array $quantities): array
    {
        if (isset($draft['estimate_composition']['derived_quantities'])
            && $draft['estimate_composition']['derived_quantities'] === $quantities) {
            unset($draft['estimate_composition']['derived_quantities']);
            $draft['estimate_composition']['derived_quantities_ref'] = 'audit_input.derived_quantities';
        }
        if (isset($draft['building_quantities']['quantities']) && $quantities !== []
            && array_column($quantities, 'quantity_basis') === $draft['building_quantities']['quantities']
            && count(array_column($quantities, 'quantity_basis')) === count($quantities)) {
            unset($draft['building_quantities']['quantities']);
            $draft['building_quantities']['quantities_ref'] = 'audit_input.derived_quantities[*].quantity_basis';
        }
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                    if (is_array($item['quantity_evidence'] ?? null)
                        && $item['quantity_evidence'] === ($item['quantity_basis_details'] ?? null)) {
                        $item['item_fingerprint'] = ApplyComposerCorrectionCycle::itemFingerprint($item);
                        unset($item['quantity_evidence']);
                        $item['quantity_evidence_ref'] = 'quantity_basis_details';
                        $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $item;
                    }
                }
            }
        }

        return $draft;
    }
}
