<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\Support\AI\TokenBudgetService;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Estimator-only frozen limits. Byte bounds remain distinct from approximate token counts. */
final class EstimatorTextWirePolicy
{
    public const CONTRACT = 'estimator-text-wire-policy:v1';

    public const MAX_SERIALIZED_INPUT_BYTES = 65536;

    public const SYSTEM_HEADROOM_BYTES = 8192;

    public static function capture(): array
    {
        $prepared = (new TokenBudgetService)->prepare([], [], 'normal');

        return ['contract' => self::CONTRACT, 'profile' => 'normal',
            'token_limits' => $prepared['budget_limits'], 'maximum_serialized_input_bytes' => self::MAX_SERIALIZED_INPUT_BYTES];
    }

    public static function validate(array $policy): array
    {
        $limits = $policy['token_limits'] ?? null;
        if (($policy['contract'] ?? null) !== self::CONTRACT || ($policy['profile'] ?? null) !== 'normal'
            || ! is_array($limits) || ! is_int($policy['maximum_serialized_input_bytes'] ?? null)
            || $policy['maximum_serialized_input_bytes'] < 1 || $policy['maximum_serialized_input_bytes'] > self::MAX_SERIALIZED_INPUT_BYTES) {
            throw new DomainException('estimator_text_wire_policy_invalid');
        }
        foreach (['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6] as $field => $maximum) {
            if (! is_int($limits[$field] ?? null) || $limits[$field] < 1 || $limits[$field] > $maximum) {
                throw new DomainException('estimator_text_wire_policy_invalid');
            }
        }

        return $policy;
    }

    public static function forOperation(int $organizationId, int $projectId, int $sessionId, ?string $operationId, string $role): ?array
    {
        if ($operationId === null || in_array($role, ['vision', 'ocr'], true)) {
            return null;
        }
        $operation = DB::table('estimate_generation_credit_operations')->where('organization_id', $organizationId)
            ->where('project_id', $projectId)->where('session_id', $sessionId)->where('operation_id', $operationId)->first(['processing_plan']);
        if ($operation === null) {
            throw new DomainException('estimator_text_wire_policy_operation_missing');
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        if (! isset($plan['text_payload_policy'])) {
            return null;
        }
        $policy = self::validate($plan['text_payload_policy']);
        $bound = $plan['roles'][$role]['input_ceiling'] ?? null;
        if (! is_int($bound) || $bound < 1 || $bound > self::MAX_SERIALIZED_INPUT_BYTES) {
            throw new DomainException('estimator_text_wire_policy_role_missing');
        }

        return [...$policy, 'maximum_serialized_input_bytes' => $bound];
    }

    public static function serializedInputUpper(array $messages): int
    {
        return strlen(json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) + count($messages) * 64;
    }
}
