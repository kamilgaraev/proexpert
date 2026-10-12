<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerBatchPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

/** Runs the execution planners before a quote; inputs are never sent to a model. */
final class EstimatorKnownContextParts
{
    public function measure(?EstimateComposerInput $composer, ?EstimateAuditInput $audit, ?ProjectSynthesisInput $synthesis,
        array $links, array $questions, array $wirePolicy, ?array $roleLimits = null): array
    {
        $wirePolicy = EstimatorTextWirePolicy::validate($wirePolicy);
        $roleLimits = $this->validateLimits($roleLimits ?? $this->captureLimits($wirePolicy), $wirePolicy);
        $composerParts = $composer === null ? [] : (new EstimateComposerBatchPlanner(
            $roleLimits['maximum_part_bytes']['composer'], 32, $roleLimits['policies']['composer']))->plan($composer);
        $auditParts = $audit === null ? [] : (new EstimateAuditContextPlanner($roleLimits['policies']['audit']))->prepare($audit,
            $roleLimits['maximum_part_bytes']['audit']);
        $synthesisParts = $synthesis === null || ($links === [] && $questions === []) ? []
            : (new ProjectSynthesisContextPlanner($roleLimits['policies']['synthesis']))->prepare($synthesis, $links, $questions,
                $roleLimits['maximum_part_bytes']['synthesis']);
        $identity = ['contract' => 'estimator-known-context:v1', 'composer' => $composer?->fingerprint(),
            'audit' => $audit?->fingerprint(), 'synthesis' => $synthesis?->fingerprint(),
            'links' => $links, 'questions' => $questions, 'wire_policy' => $wirePolicy, 'role_limits' => $roleLimits,
            'composer_parts' => array_map(static fn (EstimateComposerInput $part): string => $part->fingerprint(), $composerParts),
            'audit_parts' => array_map(static fn (array $part): string => $part['input']->fingerprint(), $auditParts),
            'synthesis_parts' => array_map(static fn (array $part): string => $part['input']->fingerprint(), $synthesisParts)];

        return ['contract' => $identity['contract'], 'fingerprint' => hash('sha256', CanonicalPipelineJson::encode($identity)),
            'part_limits' => ['composer' => count($composerParts), 'audit' => count($auditParts), 'synthesis' => count($synthesisParts)],
            'role_limits' => $roleLimits];
    }

    public function captureLimits(array $policy): array
    {
        return $this->limits($policy, ['composer' => (int) config('estimate-generation.estimate_composer.max_input_bytes', 524288),
            'audit' => (int) config('estimate-generation.estimate_auditor.max_input_bytes', 2097152),
            'synthesis' => (int) config('estimate-generation.project_engineer.max_input_bytes', 524288)]);
    }

    private function limits(array $policy, array $bytes): array
    {
        if (count($bytes) !== 3 || array_diff(['composer', 'audit', 'synthesis'], array_keys($bytes)) !== []) {
            throw new \DomainException('estimator_context_limits_invalid');
        }
        $bytes = ['composer' => $bytes['composer'], 'audit' => $bytes['audit'], 'synthesis' => $bytes['synthesis']];
        $policies = [];
        foreach ($bytes as $role => $maximum) {
            if (! is_int($maximum) || $maximum < 1024 || $maximum > 33554432) {
                throw new \DomainException('estimator_context_limits_invalid');
            }
            $policies[$role] = EstimatorTextWirePolicy::forInputCeiling($policy, $maximum);
        }
        $policies['audit']['maximum_serialized_input_bytes'] = min($policies['audit']['maximum_serialized_input_bytes'],
            $policies['composer']['maximum_serialized_input_bytes']);

        return ['contract' => 'estimator-context-limits:v1', 'maximum_part_bytes' => $bytes, 'policies' => $policies];
    }

    private function validateLimits(array $limits, array $policy): array
    {
        if (! is_array($limits['maximum_part_bytes'] ?? null)) {
            throw new \DomainException('estimator_context_limits_invalid');
        }
        $expected = $this->limits($policy, $limits['maximum_part_bytes']);
        if (CanonicalPipelineJson::encode($limits) !== CanonicalPipelineJson::encode($expected)) {
            throw new \DomainException('estimator_context_limits_invalid');
        }

        return $expected;
    }
}
