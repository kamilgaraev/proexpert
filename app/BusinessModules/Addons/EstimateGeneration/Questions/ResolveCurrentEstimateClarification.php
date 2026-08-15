<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

final readonly class ResolveCurrentEstimateClarification
{
    public function __construct(private ProjectUnderstandingQuestionProjector $projector) {}

    /** @param list<mixed> $questions */
    public function resolve(
        array $questions,
        string $understandingSourceVersion,
        ProjectModelSnapshot $snapshot,
        string $snapshotToken,
        string $questionKey,
    ): ?CurrentEstimateClarification {
        foreach ($this->resolveAll($questions, $understandingSourceVersion, $snapshot, $snapshotToken) as $candidate) {
            if ($candidate->question->code === $questionKey) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param list<mixed> $questions @return list<CurrentEstimateClarification> */
    public function resolveAll(
        array $questions,
        string $understandingSourceVersion,
        ProjectModelSnapshot $snapshot,
        string $snapshotToken,
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1) {
            throw new InvalidArgumentException('estimate_clarification_snapshot_token_invalid');
        }
        $result = [];
        foreach ($this->projector->project($questions, $understandingSourceVersion) as $question) {
            $resolved = $this->resolveQuestion($question, $snapshot, $snapshotToken);
            if ($resolved !== null) {
                $result[] = $resolved;
            }
        }

        return $result;
    }

    private function resolveQuestion(
        EstimateClarificationQuestion $question,
        ProjectModelSnapshot $snapshot,
        string $snapshotToken,
    ): ?CurrentEstimateClarification {
        $factIds = is_array($question->sourceLocator['fact_ids'] ?? null)
            ? $question->sourceLocator['fact_ids']
            : [];
        $evidenceIds = is_array($question->sourceLocator['evidence_ids'] ?? null)
            ? $question->sourceLocator['evidence_ids']
            : [];
        $facts = array_values(array_filter(
            $snapshot->facts,
            static fn (Fact $fact): bool => in_array($fact->id, $factIds, true)
                || array_intersect($fact->evidenceIds, $evidenceIds) !== [],
        ));
        if ($facts === []) {
            return null;
        }
        usort($facts, static function (Fact $left, Fact $right): int {
            $rank = static fn (Fact $fact): int => match ($fact->status) {
                'unresolved' => 0,
                'candidate' => 1,
                default => 2,
            };

            return [$rank($left), $left->id] <=> [$rank($right), $right->id];
        });
        $target = $facts[0];
        $fingerprint = hash('sha256', CanonicalPipelineJson::encode([
            'question' => $question->toArray(),
            'snapshot_token' => $snapshotToken,
            'target' => [
                'id' => $target->id,
                'source_version' => $target->sourceVersion,
                'value' => $target->value,
                'unit' => $target->unit,
                'status' => $target->status,
                'version' => $target->version,
                'evidence_ids' => $target->evidenceIds,
            ],
        ]));

        return new CurrentEstimateClarification(
            $question,
            $target->sourceVersion,
            $snapshotToken,
            $fingerprint,
            $target->id,
        );
    }
}
