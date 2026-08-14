<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

final readonly class ResolveCurrentEstimateClarification
{
    public function __construct(private ClarificationQuestionProjector $projector) {}

    /** @param list<array<string,mixed>> $pages */
    public function resolve(
        array $pages,
        ProjectModelSnapshot $snapshot,
        string $snapshotToken,
        string $questionKey,
    ): ?CurrentEstimateClarification {
        if (preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1) {
            throw new InvalidArgumentException('estimate_clarification_snapshot_token_invalid');
        }
        foreach ($this->resolveAll($pages, $snapshot, $snapshotToken) as $candidate) {
            if ($candidate->question->code === $questionKey) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $pages @return list<CurrentEstimateClarification> */
    public function resolveAll(array $pages, ProjectModelSnapshot $snapshot, string $snapshotToken): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1) {
            throw new InvalidArgumentException('estimate_clarification_snapshot_token_invalid');
        }
        $result = [];
        foreach ($this->projector->projectPages($pages) as $question) {
            $result[] = $this->resolveQuestion($question, $snapshot, $snapshotToken);
        }

        return $result;
    }

    private function resolveQuestion(
        EstimateClarificationQuestion $question,
        ProjectModelSnapshot $snapshot,
        string $snapshotToken,
    ): CurrentEstimateClarification {
        $evidenceIds = $this->matchingEvidenceIds($question, $snapshot->evidence);
        if ($evidenceIds === []) {
            throw new InvalidArgumentException('estimate_generation.question_source_fact_missing');
        }
        $facts = array_values(array_filter(
            $snapshot->facts,
            static fn (Fact $fact): bool => array_intersect($fact->evidenceIds, $evidenceIds) !== [],
        ));
        if ($facts === []) {
            throw new InvalidArgumentException('estimate_generation.question_source_fact_missing');
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

    /** @param list<Evidence> $evidence @return list<string> */
    private function matchingEvidenceIds(EstimateClarificationQuestion $question, array $evidence): array
    {
        $sources = is_array($question->sourceLocator['sources'] ?? null)
            ? $question->sourceLocator['sources']
            : [];
        $ids = [];
        foreach ($sources as $source) {
            if (! is_array($source) || array_is_list($source)
                || ! is_int($source['document_id'] ?? null)
                || ! is_int($source['page_number'] ?? null)
                || ! is_string($source['source_version'] ?? null)) {
                continue;
            }
            foreach ($evidence as $item) {
                if ($item instanceof Evidence
                    && $item->sourceArtifactId === 'document:'.$source['document_id']
                    && $item->page === $source['page_number']
                    && hash_equals($item->sourceVersion, $source['source_version'])) {
                    $ids[$item->id] = true;
                }
            }
        }
        $result = array_keys($ids);
        sort($result, SORT_STRING);

        return $result;
    }
}
