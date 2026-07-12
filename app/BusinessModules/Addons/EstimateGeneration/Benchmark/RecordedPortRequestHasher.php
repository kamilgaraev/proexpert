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

    public static function geometryConfirmation(BenchmarkPredictionCaseData $case, string $geometryPayloadSha256, array $confirmation): string
    {
        return self::hash(['schema_version' => 'recorded-geometry-confirmation-request:v1',
            'input_locator' => $case->inputLocator, 'source_sha256' => $case->inputSha256,
            'geometry_payload_sha256' => $geometryPayloadSha256, 'confirmation' => $confirmation]);
    }

    public static function planner(array $buildingModel, array $quantities, array $evidence): string
    {
        return self::hash(['schema_version' => 'recorded-planner-request:v1', 'building_model' => $buildingModel,
            'quantities' => $quantities, 'evidence' => $evidence]);
    }

    public static function reranker(WorkIntentData $intent, NormativeCandidateDecisionContextData $context, NormativeCandidateSetData $set): string
    {
        return self::hash([
            'schema_version' => 'recorded-reranker-request:v2',
            'work_intent' => self::normalize(get_object_vars($intent)),
            'decision_context' => self::normalize(get_object_vars($context)),
            'candidate_set' => [
                'organization_id' => $set->organizationId, 'project_id' => $set->projectId,
                'session_id' => $set->sessionId, 'work_item_id' => $set->workItemId,
                'dataset_version' => $set->datasetVersion, 'lexical_algorithm_version' => $set->lexicalAlgorithmVersion,
                'semantic_index_version' => $set->semanticIndexVersion, 'status' => $set->status,
                'blocking_issues' => $set->blockingIssues, 'scoring_version' => $set->scoringVersion,
                'candidates' => array_map(static fn ($candidate): array => self::normalize($candidate->toArray()), $set->candidates),
                'rejected' => array_map(static fn ($rejected): array => [
                    'candidate' => self::normalize($rejected->candidate->toArray()),
                    'reason_codes' => $rejected->reasonCodes, 'evidence' => $rejected->evidence,
                ], $set->rejected),
            ],
        ]);
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

    private static function normalize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $payload[$key] = $value->format('Y-m-d\TH:i:s.uP');
            } elseif (is_array($value)) {
                $payload[$key] = self::normalize($value);
            }
        }

        return $payload;
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
