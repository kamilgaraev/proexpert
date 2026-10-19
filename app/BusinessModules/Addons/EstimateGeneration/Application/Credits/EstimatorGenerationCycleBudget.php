<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigInteger;
use DomainException;
use Illuminate\Validation\ValidationException;

final class EstimatorGenerationCycleBudget
{
    public function assertFits(string $kind, array $plan, string $errorKey = 'quote'): ?array
    {
        try {
            $forecast = self::forecast($kind, $plan);
            if (isset($plan['generation_cycle_preflight'])
                && CanonicalPipelineJson::encode($plan['generation_cycle_preflight']) !== CanonicalPipelineJson::encode($forecast)) {
                throw new DomainException('estimator_generation_cycle_preflight_unavailable');
            }

            return $forecast;
        } catch (DomainException $error) {
            $key = match ($error->getMessage()) {
                'estimator_generation_cycle_budget_insufficient' => 'generation_cycle_budget_insufficient',
                'estimator_generation_cycle_preflight_unavailable' => 'generation_cycle_preflight_unavailable',
                default => throw $error,
            };
            throw ValidationException::withMessages([$errorKey => trans_message('estimate_generation.'.$key)]);
        }
    }

    public static function forecast(string $kind, array $plan): ?array
    {
        if ($kind !== 'generation' || isset($plan['retained_pipeline_prefix'])) {
            return null;
        }
        $roles = $plan['roles'] ?? null;
        $maximum = $plan['maximum_provider_cost_micro_rub'] ?? null;
        $documents = $plan['document_costs']['cost_micro_rub'] ?? 0;
        $calls = $plan['maximum_calls'] ?? null;
        if (! in_array($plan['contract_version'] ?? null, ['estimator-processing-plan:v1', 'estimator-processing-plan:v2'], true)
            || (isset($plan['document_costs']) && ! is_array($plan['document_costs']))
            || ! is_array($roles) || $roles === []
            || array_diff(['estimate_composition', 'estimate_audit', 'estimate_composer_correction'], array_keys($roles)) !== []
            || array_diff(array_keys($roles), ['vision', 'ocr', 'project_synthesis', 'estimate_composition',
                'estimate_audit', 'estimate_composer_correction', 'rerank']) !== []
            || ! is_int($maximum) || $maximum < 1 || ! is_int($documents) || $documents < 0 || $documents >= $maximum
            || ! is_int($calls) || $calls < 1 || $calls > 10000) {
            throw new DomainException('estimator_generation_cycle_preflight_unavailable');
        }
        $required = BigInteger::zero();
        $roleCosts = [];
        $totalCalls = 0;
        foreach ($roles as $role => $limits) {
            if (! is_array($limits)) {
                throw new DomainException('estimator_generation_cycle_preflight_unavailable');
            }
            $roleCalls = $limits['max_calls'] ?? null;
            $cost = $limits['maximum_cost_micro_rub'] ?? null;
            if (! is_int($roleCalls) || $roleCalls < 1 || $roleCalls > 10000
                || ! is_int($cost) || $cost < 1) {
                throw new DomainException('estimator_generation_cycle_preflight_unavailable');
            }
            $totalCalls += $roleCalls;
            $roleCosts[$role] = BigInteger::of($cost)->multipliedBy($roleCalls);
            $required = $required->plus($roleCosts[$role]);
        }
        if ($totalCalls !== $calls) {
            throw new DomainException('estimator_generation_cycle_preflight_unavailable');
        }
        $available = BigInteger::of($maximum)->minus($documents);
        if ($required->isGreaterThan($available)) {
            throw new DomainException('estimator_generation_cycle_budget_insufficient');
        }
        $roleBudgets = [];
        foreach ($roleCosts as $role => $cost) {
            $roleBudgets[$role] = ['maximum_calls' => $roles[$role]['max_calls'],
                'per_call_maximum_cost_micro_rub' => $roles[$role]['maximum_cost_micro_rub'],
                'maximum_cost_micro_rub' => $cost->toInt()];
        }

        return ['contract_version' => 'estimator-generation-cycle-preflight:v1', 'full_cycle_fits' => true,
            'maximum_remaining_cycle_cost_micro_rub' => $required->toInt(),
            'maximum_total_provider_cost_micro_rub' => $required->plus($documents)->toInt(),
            'included_document_cost_micro_rub' => $documents, 'available_provider_cost_micro_rub' => $available->toInt(),
            'maximum_calls' => $calls, 'role_budgets' => $roleBudgets,
            'corrections_included' => true, 'retries_included' => true,
            'replay_discount_micro_rub' => 0, 'completion_guaranteed' => false];
    }
}
