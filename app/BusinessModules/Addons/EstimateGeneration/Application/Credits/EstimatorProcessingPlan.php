<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCostCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

/** Finite server-owned ceilings. Unplanned expansion requires a separate approved operation. */
final class EstimatorProcessingPlan
{
    public function build(string $kind, int $rasterUnits, int $workItems, bool $complex, AiPriceSnapshot $tariff, bool $rerank = false, array $knownPartLimits = []): array
    {
        if (! in_array($kind, ['generation', 'analysis', 'dialogue', 'documents', 'rebuild'], true)
            || $rasterUnits < 0 || $rasterUnits > 500 || $workItems < 0 || $workItems > 2000) {
            throw new DomainException('estimator_processing_plan_limit');
        }
        if (! in_array($kind, ['documents', 'dialogue'], true) && $workItems > 512) {
            throw new DomainException('estimator_processing_plan_requires_section_split');
        }
        $roles = [];
        if (array_diff(array_keys($knownPartLimits), ['composer', 'audit', 'synthesis']) !== []) {
            throw new DomainException('estimator_processing_plan_part_limit_invalid');
        }
        foreach ($knownPartLimits as $parts) {
            if (! is_int($parts) || $parts < 0 || $parts > 512) {
                throw new DomainException('estimator_processing_plan_part_limit_invalid');
            }
        }
        $baselineParts = \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditBatchPlanner::partCeiling($workItems);
        $composerParts = max($baselineParts, $knownPartLimits['composer'] ?? 0);
        // Runtime can split rich proofs down to a single work, even before a draft exists.
        $auditParts = max(1, $workItems, $knownPartLimits['audit'] ?? 0);
        $synthesisParts = 0;
        if ($kind === 'dialogue') {
            $roles['estimate_dialogue_interpretation'] = $this->role(3, 80000, 2048, $tariff);
        } else {
            if ($rasterUnits > 0) {
                // Literal runs first; unknown input may legitimately route to observers and arbitration.
                // Reserve the finite escalation ceiling without making those calls mandatory.
                $roles['vision'] = $this->role($rasterUnits * 15,
                    (int) config('estimate-generation.vision.max_input_tokens', 32768), 4096, $tariff, true);
                $roles['ocr'] = $this->role($rasterUnits * 3,
                    (int) config('estimate-generation.vision.max_input_tokens', 32768), 4096, $tariff, true);
            }
            if ($complex && $kind !== 'documents') {
                $synthesisParts = max($baselineParts, $knownPartLimits['synthesis'] ?? 0);
                $roles['project_synthesis'] = $this->role($synthesisParts * 3, (int) config('estimate-generation.project_engineer.max_input_bytes', 524288),
                    (int) config('estimate-generation.project_engineer.max_output_tokens', 4096), $tariff);
            }
            if ($kind !== 'documents') {
                $roles['estimate_composition'] = $this->role($composerParts * 3, (int) config('estimate-generation.estimate_composer.max_input_bytes', 524288),
                    (int) config('estimate-generation.estimate_composer.max_output_tokens', 8192), $tariff);
                $roles['estimate_audit'] = $this->role($auditParts * 9, (int) config('estimate-generation.estimate_auditor.max_input_bytes', 2097152),
                    (int) config('estimate-generation.estimate_auditor.max_output_tokens', 8192), $tariff);
                $roles['estimate_composer_correction'] = $this->role($auditParts * 6, (int) config('estimate-generation.estimate_composer.max_input_bytes', 524288),
                    (int) config('estimate-generation.estimate_composer.max_output_tokens', 8192), $tariff);
                if ($rerank) {
                    $roles['rerank'] = $this->role(max(1, $workItems) * 3, 12000, 800, $tariff);
                }
            }
        }
        $calls = array_sum(array_column($roles, 'max_calls'));
        $maximum = BigDecimal::zero();
        foreach ($roles as $role) {
            $maximum = $maximum->plus(BigDecimal::of((string) $role['maximum_cost_micro_rub'])->multipliedBy($role['max_calls']));
        }
        if ($calls < 1 || $calls > 10000 || ! $maximum->isGreaterThan(0)) {
            throw new DomainException('estimator_processing_plan_empty_or_oversized');
        }

        return ['contract_version' => 'estimator-processing-plan:v2', 'work_ceiling_policy' => 'global_work_items:v1', 'roles' => $roles,
            'text_payload_policy' => \App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy::capture(),
            'maximum_calls' => $calls, 'maximum_provider_cost_micro_rub' => $maximum->toInt(),
            'raster_units' => $rasterUnits, 'work_item_limit' => $workItems, 'complex' => $complex, 'rerank' => $rerank,
            'audit_part_limit' => $kind === 'documents' || $kind === 'dialogue' ? 0
                : $auditParts,
            'composer_part_limit' => $kind === 'documents' || $kind === 'dialogue' ? 0
                : $composerParts,
            'synthesis_part_limit' => isset($roles['project_synthesis']) ? $synthesisParts : 0];
    }

    private function role(int $calls, int $inputCeiling, int $outputCeiling, AiPriceSnapshot $tariff, bool $image = false): array
    {
        if ($inputCeiling < 1 || $outputCeiling < 1 || $calls < 1) {
            throw new DomainException('estimator_processing_plan_configuration_invalid');
        }
        if (! $image) {
            $policy = \App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy::capture();
            $inputCeiling = \App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy::forInputCeiling($policy, $inputCeiling)['maximum_serialized_input_bytes'];
            $outputCeiling = min($outputCeiling, $policy['token_limits']['output_tokens']);
        }
        $cost = (new AiCostCalculator)->calculate($image ? $inputCeiling + 1024 : $inputCeiling, 0, $outputCeiling, 0,
            $image ? 1 : 0, 0, $tariff->toArray());
        if ($cost->pricingStatus !== 'available' || $cost->currency !== 'RUB' || $cost->amount === null) {
            throw new DomainException('estimator_processing_plan_price_unavailable');
        }

        return ['max_calls' => $calls, 'input_ceiling' => $inputCeiling, 'output_ceiling' => $outputCeiling,
            'maximum_cost_micro_rub' => BigDecimal::of($cost->amount)->multipliedBy('1000000')->toScale(0, RoundingMode::Ceiling)->toInt()];
    }
}
