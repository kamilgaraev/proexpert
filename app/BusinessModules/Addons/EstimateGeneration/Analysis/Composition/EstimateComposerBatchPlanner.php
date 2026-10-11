<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** Plans every request before sending; never cuts records to fit a model context. */
final readonly class EstimateComposerBatchPlanner
{
    public function __construct(private int $maxInputBytes = 524288, private int $candidatesPerBatch = 32) {}

    /** @return list<EstimateComposerInput> */
    public function plan(EstimateComposerInput $input): array
    {
        if ($this->maxInputBytes < 1024 || $this->candidatesPerBatch < 1 || $this->candidatesPerBatch > 64) {
            throw new InvalidArgumentException('estimate_composer_batch_configuration_invalid');
        }
        if (count($input->candidates) <= $this->candidatesPerBatch && $this->fits($input)) {
            return [$input];
        }
        $groups = array_chunk($input->candidates, $this->candidatesPerBatch);
        if ($groups === []) {
            $groups = [[]];
        }
        $batches = [];
        $coveredFacts = [];
        foreach ($groups as $candidates) {
            $whole = $this->batch($input, $candidates, $input->facts, $input->decisions);
            if ($this->fits($whole)) {
                $batches[] = $whole;
                foreach ($input->facts as $fact) {
                    $coveredFacts[$fact['id']] = true;
                }

                continue;
            }
            // Keep the complete entity context and the decisions that establish its facts.
            $ids = array_fill_keys(array_merge(...array_map(static fn (array $candidate): array => $candidate['source_fact_ids'], $candidates)), true);
            $entities = [];
            foreach ($input->facts as $fact) {
                if (isset($ids[$fact['id']])) {
                    $entities[(string) ($fact['entity_id'] ?? '')] = true;
                }
            }
            $facts = array_values(array_filter($input->facts, static fn (array $fact): bool => isset($ids[$fact['id']])
                || isset($entities[(string) ($fact['entity_id'] ?? '')])));
            $batch = $this->withFacts($input, $candidates, $facts);
            $this->assertFits($batch);
            $batches[] = $batch;
            foreach ($facts as $fact) {
                $coveredFacts[$fact['id']] = true;
            }
        }
        // Facts without deterministic candidates still need a composition pass.
        $uncovered = array_values(array_filter($input->facts, static fn (array $fact): bool => ! isset($coveredFacts[$fact['id']])));
        foreach (array_chunk($uncovered, 64) as $facts) {
            $batch = $this->withFacts($input, [], $facts);
            $this->assertFits($batch);
            $batches[] = $batch;
        }
        if (count($batches) > 512) {
            throw new InvalidArgumentException('estimate_composer_operation_plan_limit_exceeded');
        }

        return $batches;
    }

    private function withFacts(EstimateComposerInput $input, array $candidates, array $facts): EstimateComposerInput
    {
        $ids = array_fill_keys(array_column($facts, 'id'), true);
        $decisions = array_values(array_filter($input->decisions, static fn (array $decision): bool => isset($ids[$decision['selected_fact_id'] ?? ''])
            || isset($ids[$decision['target_id'] ?? ''])));

        return $this->batch($input, $candidates, $facts, $decisions);
    }

    private function batch(EstimateComposerInput $input, array $candidates, array $facts, array $decisions): EstimateComposerInput
    {
        $key = hash('sha256', CanonicalPipelineJson::encode([
            'snapshot' => $input->snapshotToken, 'candidates' => array_column($candidates, 'candidate_id'), 'facts' => array_column($facts, 'id'),
        ]));

        return new EstimateComposerInput($input->organizationId, $input->projectId, $input->sessionId, $input->snapshotToken,
            $facts, $input->derivedQuantities, $decisions, $candidates, $input->missingDocuments, $input->contractVersion, $input->wireScope, $key);
    }

    private function fits(EstimateComposerInput $input): bool
    {
        return strlen(CanonicalPipelineJson::encode(['project' => $input->canonicalPayload()])) <= $this->maxInputBytes;
    }

    private function assertFits(EstimateComposerInput $input): void
    {
        if (! $this->fits($input)) {
            throw new InvalidArgumentException('estimate_composer_entity_context_too_large');
        }
    }
}
