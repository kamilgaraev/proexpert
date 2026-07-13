<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;

final readonly class WorkPlanCompiler
{
    public function __construct(
        private PackagePlannerService $packagePlanner,
        private EstimateDecompositionService $decomposition,
        private NormativeWorkItemPlannerService $workItemPlanner,
        private NormativeContextPinResolver $normativePins,
    ) {}

    /** @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    public function compile(array $analysis, ?WorkPlannerResponseData $source = null): array
    {
        $profile = $this->packagePlanner->profileFromAnalysis($analysis);
        $plan = $this->packagePlanner->plan($profile);
        $localEstimates = $this->decomposition->decomposePackagePlan($analysis, $plan);
        foreach ($localEstimates as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $intents = $source?->intentsFor((string) $section['key'], (string) $localEstimate['scope_type']);
                $localEstimates[$localIndex]['sections'][$sectionIndex]['work_items'] = $intents === null
                    ? $this->workItemPlanner->build($localEstimate, $section, $analysis)
                    : array_map(fn (array $intent): array => $this->intentToWorkItem($intent, $localEstimate, $section), $intents);
            }
        }

        $regionalContext = is_array($analysis['regional_context'] ?? null) ? $analysis['regional_context'] : [];

        return [
            'object_profile' => $profile->toArray(),
            'package_plan' => $plan->toArray(),
            'document_requirements' => $this->packagePlanner->documentRequirements($profile),
            'generation_mode' => EstimateGenerationMode::fromInput($profile->planningSignals['generation_mode'] ?? null)->value,
            'regional_context' => $analysis['regional_context'] ?? [],
            'normative_context_pin' => $this->normativePins->resolve($regionalContext, $this->normativeIntents($localEstimates)),
            'local_estimates' => $localEstimates,
        ];
    }

    /** @param array<string, mixed> $intent
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function intentToWorkItem(array $intent, array $localEstimate, array $section): array
    {
        $name = (string) $intent['name'];
        $category = (string) $intent['category'];

        return [
            'key' => (string) $intent['intent_key'], 'parent_key' => null, 'level' => 0, 'item_type' => 'priced_work',
            'name' => $name, 'description' => $name, 'normative_search_text' => $name,
            'work_intent' => isset($intent['work_intent']) ? [
                ...$intent['work_intent'],
                'expected_dimensions' => $intent['work_intent']['dimensions'],
            ] : null,
            'normative_search_key' => implode('|', [(string) $localEstimate['key'], (string) $localEstimate['scope_type'], $category, mb_strtolower($name), (string) $intent['unit'], (string) $intent['intent_key']]),
            'normative_rate_code' => null, 'work_category' => $category, 'unit' => (string) $intent['unit'],
            'quantity' => (string) $intent['quantity'], 'quantity_formula' => (string) $intent['intent_key'],
            'quantity_basis' => 'recorded_work_planner', 'work_cost' => 0, 'materials_cost' => 0, 'machinery_cost' => 0,
            'labor_cost' => 0, 'total_cost' => 0, 'materials' => [], 'labor' => [], 'machinery' => [], 'other_resources' => [],
            'work_composition' => [], 'source_refs' => array_map(static fn (string $ref): array => ['type' => 'evidence', 'value' => $ref], $intent['quantity_source_refs']),
            'confidence' => round(max(min((float) $intent['confidence'], 0.98), 0.35), 4),
            'validation_flags' => ['normative_required'], 'price_source' => null, 'pricing_status' => 'not_calculated',
            'pricing_blocker' => 'normative_required', 'pricing_blocker_message' => null,
            'metadata' => ['generation_source' => 'work_planner_provider', 'quantity_source' => 'recorded_provider_evidence',
                'quantity_key' => (string) $intent['quantity_key'],
                'package_key' => (string) $localEstimate['key'], 'section_key' => (string) $section['key'],
                'quantity_source_refs' => $intent['quantity_source_refs'], 'normative_grounding_policy' => 'fsnb_required',
                'display_role' => 'priced_work', 'work_composition' => [], 'composition_source' => 'planner_intent'],
        ];
    }

    /** @return list<array{search_text: string, unit: string, code: string|null}> */
    private function normativeIntents(array $localEstimates): array
    {
        $intents = [];
        foreach ($localEstimates as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (! is_array($item) || in_array((string) ($item['item_type'] ?? 'priced_work'), ['operation', 'resource_note', 'review_note', 'quantity_review'], true)) {
                        continue;
                    }
                    $intents[] = [
                        'search_text' => (string) ($item['normative_search_text'] ?? $item['name'] ?? ''),
                        'unit' => (string) ($item['unit'] ?? ''),
                        'code' => is_string($item['normative_rate_code'] ?? null) ? $item['normative_rate_code'] : null,
                    ];
                }
            }
        }

        return $intents;
    }
}
