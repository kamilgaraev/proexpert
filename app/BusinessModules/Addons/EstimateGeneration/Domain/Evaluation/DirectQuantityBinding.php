<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

/** A user assigns a current numerical source to one work; a locator alone never supplies a quantity. */
final class DirectQuantityBinding
{
    public static function key(string $workKey): string
    {
        return 'quantity:direct:'.hash('sha256', $workKey);
    }

    public static function valueHash(mixed $value): string
    {
        return hash('sha256', CanonicalPipelineJson::encode(['value' => $value]));
    }

    public static function canonicalUnit(?string $source): ?string
    {
        foreach (['length' => 'm', 'area' => 'm2', 'volume' => 'm3', 'count' => 'count', 'mass' => 'kg', 'time' => 'h'] as $group => $unit) {
            if (FactVocabulary::factor($group, $source) !== null) {
                return $unit;
            }
        }

        return null;
    }

    public function source(ProjectModelSnapshot $snapshot, Fact $binding, Decision $decision): ?Fact
    {
        $value = $binding->value;
        if ($binding->type !== 'evaluation_answer' || ! is_array($value) || ($value['answer_kind'] ?? null) !== 'direct_quantity'
            || ! is_string($value['work_item_key'] ?? null) || $value['work_item_key'] === ''
            || ($value['quantity_key'] ?? null) !== self::key($value['work_item_key'])
            || ! (new EvaluationChoiceProof)->accepted($snapshot, $binding, $decision)) {
            return null;
        }
        foreach ($snapshot->facts as $fact) {
            if ($fact->id !== ($value['source_fact_id'] ?? null)) {
                continue;
            }
            if ([$fact->organizationId, $fact->projectId, $fact->sessionId] !== [$binding->organizationId, $binding->projectId, $binding->sessionId]
                || $fact->version !== ($value['source_fact_version'] ?? null) || $fact->sourceVersion !== ($value['source_version'] ?? null)
                || $fact->entityId !== ($value['source_entity_id'] ?? null) || $fact->unit !== ($value['source_unit'] ?? null)
                || ! is_string($value['source_value_hash'] ?? null)
                || ! hash_equals($value['source_value_hash'], self::valueHash($fact->value))
                || $fact->status !== 'confirmed' || ! in_array($fact->origin, ['document', 'user_input', 'user_assumption'], true)
                || (! is_string($fact->value) && ! is_int($fact->value))) {
                return null;
            }

            return $fact;
        }

        return null;
    }
}
