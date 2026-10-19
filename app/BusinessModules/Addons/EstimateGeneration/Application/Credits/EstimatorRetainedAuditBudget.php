<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditDraftPreview;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Brick\Math\BigInteger;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

final readonly class EstimatorRetainedAuditBudget
{
    public function __construct(private Connection $database, private EstimateAuditDraftPreview $preview) {}

    public function assertFits(User $actor, EstimateGenerationSession $session, array $plan, string $errorKey = 'quote'): ?array
    {
        $prefix = $plan['retained_pipeline_prefix'] ?? null;
        if ($prefix === null) {
            return null;
        }
        try {
            $source = $this->database->table('estimate_generation_credit_operations')
                ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
                ->where('session_id', $session->id)->where('operation_id', $prefix['source_operation_id'] ?? null)
                ->where('status', 'failed')->first(['processing_plan', 'plan_hash']);
            if ($source === null || ! hash_equals((string) ($prefix['source_plan_hash'] ?? ''), $source->plan_hash)) {
                throw new DomainException('estimator_retained_audit_preflight_unavailable');
            }
            $sourcePlan = json_decode($source->processing_plan, true, 64, JSON_THROW_ON_ERROR);
            if (! hash_equals($source->plan_hash, hash('sha256', CanonicalPipelineJson::encode($sourcePlan)))) {
                throw new DomainException('estimator_retained_audit_preflight_unavailable');
            }
            $forecast = self::forecast($plan, $sourcePlan, $this->preview->read($actor, (int) $session->project_id, (int) $session->id));
            if (isset($plan['retained_audit_preflight'])
                && CanonicalPipelineJson::encode($plan['retained_audit_preflight']) !== CanonicalPipelineJson::encode($forecast)) {
                throw new DomainException('estimator_retained_audit_preflight_unavailable');
            }

            return $forecast;
        } catch (DomainException $error) {
            $key = match ($error->getMessage()) {
                'estimator_retained_audit_budget_insufficient' => 'retained_audit_budget_insufficient',
                'estimator_retained_audit_preflight_unavailable' => 'retained_audit_preflight_unavailable',
                default => throw $error,
            };
            throw ValidationException::withMessages([$errorKey => trans_message('estimate_generation.'.$key)]);
        }
    }

    public static function forecast(array $plan, array $sourcePlan, array $preview): array
    {
        $prefix = $plan['retained_pipeline_prefix'] ?? [];
        foreach (['text_payload_policy', 'work_item_limit', 'audit_part_limit'] as $key) {
            if (! isset($plan[$key], $sourcePlan[$key])
                || CanonicalPipelineJson::encode([$plan[$key]]) !== CanonicalPipelineJson::encode([$sourcePlan[$key]])) {
                throw new DomainException('estimator_retained_audit_preflight_unavailable');
            }
        }
        foreach (['estimate_audit', 'estimate_composer_correction'] as $role) {
            foreach (['input_ceiling', 'output_ceiling'] as $key) {
                if (! isset($plan['roles'][$role][$key], $sourcePlan['roles'][$role][$key])
                    || $plan['roles'][$role][$key] !== $sourcePlan['roles'][$role][$key]) {
                    throw new DomainException('estimator_retained_audit_preflight_unavailable');
                }
            }
        }
        $parts = $preview['audit_preview']['parts'] ?? null;
        $perCall = $plan['roles']['estimate_audit']['maximum_cost_micro_rub'] ?? null;
        $maximum = $plan['maximum_provider_cost_micro_rub'] ?? null;
        $documents = $plan['document_costs']['cost_micro_rub'] ?? 0;
        if (($preview['available'] ?? null) !== true || ($preview['audit_preview']['fits'] ?? null) !== true
            || ! is_string($preview['artifact_version'] ?? null)
            || $preview['artifact_version'] !== ($prefix['stages']['build_draft']['output_version'] ?? null)
            || ! is_array($parts) || $parts === [] || count($parts) > $plan['audit_part_limit']
            || ! is_int($perCall) || $perCall < 1 || ! is_int($maximum) || $maximum < 1
            || ! is_int($documents) || $documents < 0 || $documents >= $maximum) {
            throw new DomainException('estimator_retained_audit_preflight_unavailable');
        }
        $required = BigInteger::of($perCall)->multipliedBy(count($parts));
        $available = BigInteger::of($maximum)->minus($documents);
        if ($required->isGreaterThan($available)) {
            throw new DomainException('estimator_retained_audit_budget_insufficient');
        }

        return ['contract_version' => 'estimator-retained-audit-preflight:v1',
            'source_operation_id' => $prefix['source_operation_id'], 'source_artifact_version' => $preview['artifact_version'],
            'initial_audit_parts' => count($parts), 'per_call_maximum_cost_micro_rub' => $perCall,
            'maximum_initial_audit_cost_micro_rub' => $required->toInt(),
            'available_provider_cost_micro_rub' => $available->toInt(),
            'corrections_included' => false, 'completion_guaranteed' => false];
    }
}
