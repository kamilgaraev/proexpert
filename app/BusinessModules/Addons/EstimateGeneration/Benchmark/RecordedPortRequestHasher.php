<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;

final class RecordedPortRequestHasher
{
    public static function verify(string $recorded, string $actual, string $reason): void
    {
        if (! hash_equals($recorded, $actual)) {
            throw new RecordedPortEnvelopeException($reason);
        }
    }

    public static function geometry(BenchmarkPredictionCaseData $case, RecordedPort $port): string
    {
        return self::hash(['schema_version' => 'recorded-port-request:v1', 'port' => $port->value,
            'input_locator' => $case->inputLocator, 'source_sha256' => $case->inputSha256]);
    }

    public static function planner(array $buildingModel, array $quantities, array $evidence): string
    {
        return self::hash(['schema_version' => 'recorded-planner-request:v1', 'building_model' => $buildingModel,
            'quantities' => $quantities, 'evidence' => $evidence]);
    }

    public static function reranker(WorkIntentData $intent, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $set): string
    {
        return self::rerankerFixture(
            array_map(static fn ($candidate): string => $candidate->id, $set->candidates),
            ['work_item_id' => $intent->workItemId, 'intent' => $intent->intent, 'unit' => $intent->canonicalUnit,
                'quantity_evidence' => $intent->sourceEvidence, 'dataset_version' => $intent->datasetVersion,
                'checkpoint_claim_token' => $context->checkpointClaimToken, 'input_version' => $context->inputVersion,
                'logical_attempt' => $context->logicalAttempt],
        );
    }

    public static function rerankerFixture(array $orderedCandidateIds, array $context): string
    {
        return self::hash(['schema_version' => 'recorded-reranker-request:v1',
            'ordered_candidate_ids' => array_values($orderedCandidateIds), 'context' => $context]);
    }

    private static function hash(array $payload): string
    {
        self::sort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private static function sort(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sort($item);
            }
        }
    }
}
