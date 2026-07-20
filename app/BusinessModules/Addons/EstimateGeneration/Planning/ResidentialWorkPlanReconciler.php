<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class ResidentialWorkPlanReconciler
{
    public function __construct(
        private ResidentialWorkCompositionCatalog $catalog = new ResidentialWorkCompositionCatalog,
    ) {}

    public function reconcile(array $plan, AiWorkCompositionAdviceData $advice): array
    {
        $requirements = $this->catalog->requirements($plan);
        if ($requirements === []) {
            return $plan;
        }

        foreach ($plan['local_estimates'] as $localIndex => $localEstimate) {
            $packageKey = (string) ($localEstimate['key'] ?? '');
            foreach (($localEstimate['sections'] ?? []) as $sectionIndex => $section) {
                foreach (($section['work_items'] ?? []) as $itemIndex => $workItem) {
                    if (! is_array($workItem)) {
                        continue;
                    }
                    $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
                    $workKey = trim((string) (
                        $metadata['composition_work_key']
                        ?? $metadata['material_scenario_work_key']
                        ?? $metadata['quantity_key']
                        ?? $workItem['quantity_formula']
                        ?? ''
                    ));
                    if ($workKey === '' || ! in_array($workKey, $requirements[$packageKey] ?? [], true)) {
                        continue;
                    }
                    $decision = $advice->decisions[$workKey] ?? null;
                    $metadata['composition_coverage'] = [
                        'catalog_version' => ResidentialWorkCompositionCatalog::VERSION,
                        'required' => true,
                        'source' => is_array($decision) ? 'ai_bounded_catalog' : 'deterministic_catalog',
                        'ai_status' => is_array($decision) ? $decision['status'] : $advice->status,
                        'reason_codes' => is_array($decision) ? $decision['reason_codes'] : [],
                        'confidence' => is_array($decision) ? $decision['confidence'] : null,
                    ];
                    $workItem['metadata'] = $metadata;
                    $plan['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $workItem;
                }
            }
        }

        $plan['package_plan']['work_composition_advice'] = [
            'status' => $advice->status,
            'catalog_version' => ResidentialWorkCompositionCatalog::VERSION,
            'decision_count' => count($advice->decisions),
            'model' => $advice->model,
            'scope_decision_catalog_version' => ResidentialScopeDecisionCatalog::VERSION,
            'scope_decisions' => $advice->scopeDecisions,
        ];

        return $plan;
    }
}
