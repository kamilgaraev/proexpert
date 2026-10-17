<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextPayloadBudget;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** Plans every request before sending; never cuts records to fit a model context. */
final readonly class EstimateComposerBatchPlanner
{
    public const MAX_PARTS = 512;

    public function __construct(private int $maxInputBytes = 524288, private int $candidatesPerBatch = 32, private ?array $wirePolicy = null) {}

    public function withWirePolicy(?array $policy): self
    {
        return new self($this->maxInputBytes, $this->candidatesPerBatch, $policy);
    }

    /** @return list<EstimateComposerInput> */
    public function plan(EstimateComposerInput $input): array
    {
        if ($this->maxInputBytes < 1024 || $this->candidatesPerBatch < 1 || $this->candidatesPerBatch > 64) {
            throw new InvalidArgumentException('estimate_composer_batch_configuration_invalid');
        }
        $factSet = array_fill_keys(array_column($input->facts, 'id'), true);
        foreach ($input->derivedQuantities as $quantity) {
            foreach ($quantity['operand_fact_ids'] ?? [] as $id) {
                if (! is_string($id) || ! isset($factSet[$id])) {
                    throw new InvalidArgumentException('estimate_composer_quantity_operand_missing');
                }
            }
        }
        if (count($input->candidates) <= $this->candidatesPerBatch && $this->fits($input)) {
            return [$input];
        }
        $batches = [];
        $coveredFacts = [];
        $coveredQuantities = [];
        $queue = array_chunk($input->candidates, $this->candidatesPerBatch);
        while ($queue !== []) {
            $candidates = array_shift($queue);
            $batch = $this->batch($input, $candidates, $input->facts, $input->decisions, $input->derivedQuantities);
            if (! $this->fits($batch)) {
                $batch = $this->context($input, $candidates, [], []);
            }
            if (! $this->fits($batch) && count($candidates) > 1) {
                array_unshift($queue, ...array_chunk($candidates, (int) ceil(count($candidates) / 2)));

                continue;
            }
            $this->assertFits($batch);
            $this->append($batches, $coveredFacts, $coveredQuantities, $batch);
        }
        // Facts without deterministic candidates still need a composition pass.
        $uncovered = array_values(array_filter($input->facts, static fn (array $fact): bool => ! isset($coveredFacts[$fact['id']])));
        $queue = array_chunk($uncovered, 64);
        while ($queue !== []) {
            $facts = array_shift($queue);
            $batch = $this->context($input, [], $facts, []);
            if (! $this->fits($batch) && count($facts) > 1) {
                array_unshift($queue, ...array_chunk($facts, (int) ceil(count($facts) / 2)));

                continue;
            }
            $this->assertFits($batch);
            $this->append($batches, $coveredFacts, $coveredQuantities, $batch);
        }
        $uncovered = array_values(array_filter($input->derivedQuantities, static fn (array $quantity): bool => ! isset($coveredQuantities[$quantity['id']])));
        $queue = array_chunk($uncovered, 64);
        while ($queue !== []) {
            $quantities = array_shift($queue);
            $batch = $this->context($input, [], [], $quantities);
            if (! $this->fits($batch) && count($quantities) > 1) {
                array_unshift($queue, ...array_chunk($quantities, (int) ceil(count($quantities) / 2)));

                continue;
            }
            $this->assertFits($batch);
            $this->append($batches, $coveredFacts, $coveredQuantities, $batch);
        }

        return $batches;
    }

    private function context(EstimateComposerInput $input, array $candidates, array $facts, array $quantities): EstimateComposerInput
    {
        $ids = array_fill_keys(array_column($facts, 'id'), true);
        $quantityIds = array_fill_keys(array_column($quantities, 'id'), true);
        foreach ($candidates as $candidate) {
            foreach ($candidate['source_fact_ids'] as $id) {
                $ids[$id] = true;
            }
            foreach ($input->derivedQuantities as $quantity) {
                if ($candidate['quantity_formula'] === $quantity['id'] && $candidate['unit'] === $quantity['unit']) {
                    $quantityIds[$quantity['id']] = true;
                }
            }
        }
        $entities = [];
        do {
            $previous = count($ids) + count($quantityIds) + count($entities);
            foreach ($input->derivedQuantities as $quantity) {
                $entity = $quantity['entity_id'] ?? null;
                $operands = $quantity['operand_fact_ids'] ?? [];
                if (isset($quantityIds[$quantity['id']]) || (is_string($entity) && isset($entities[$entity]))
                    || array_intersect($operands, array_keys($ids)) !== []) {
                    $quantityIds[$quantity['id']] = true;
                    foreach ($operands as $id) {
                        $ids[$id] = true;
                    }
                    if (is_string($entity)) {
                        $entities[$entity] = true;
                    }
                }
            }
            foreach ($input->facts as $fact) {
                $entity = $fact['entity_id'] ?? null;
                if (isset($ids[$fact['id']]) && is_string($entity)) {
                    $entities[$entity] = true;
                }
                if (is_string($entity) && isset($entities[$entity])) {
                    $ids[$fact['id']] = true;
                }
            }
        } while ($previous !== count($ids) + count($quantityIds) + count($entities));
        $facts = array_values(array_filter($input->facts, static fn (array $fact): bool => isset($ids[$fact['id']])));
        $quantities = array_values(array_filter($input->derivedQuantities, static fn (array $quantity): bool => isset($quantityIds[$quantity['id']])));
        $decisions = array_values(array_filter($input->decisions, static fn (array $decision): bool => isset($ids[$decision['selected_fact_id'] ?? ''])
            || isset($ids[$decision['target_id'] ?? ''])));

        return $this->batch($input, $candidates, $facts, $decisions, $quantities);
    }

    private function append(array &$batches, array &$coveredFacts, array &$coveredQuantities, EstimateComposerInput $batch): void
    {
        $batches[] = $batch;
        if (count($batches) > self::MAX_PARTS) {
            throw new InvalidArgumentException('estimate_composer_operation_plan_limit_exceeded');
        }
        foreach ($batch->facts as $fact) {
            $coveredFacts[$fact['id']] = true;
        }
        foreach ($batch->derivedQuantities as $quantity) {
            $coveredQuantities[$quantity['id']] = true;
        }
    }

    private function batch(EstimateComposerInput $input, array $candidates, array $facts, array $decisions, array $quantities): EstimateComposerInput
    {
        $parent = $input->fingerprint();
        $key = hash('sha256', CanonicalPipelineJson::encode([
            'parent' => $parent, 'snapshot' => $input->snapshotToken, 'candidates' => array_column($candidates, 'candidate_id'),
            'facts' => array_column($facts, 'id'), 'quantities' => array_column($quantities, 'id'),
        ]));

        return new EstimateComposerInput($input->organizationId, $input->projectId, $input->sessionId, $input->snapshotToken,
            $facts, $quantities, $decisions, $candidates, $input->missingDocuments, $input->contractVersion, $input->wireScope, $key,
            ['contract' => 'composer-context:v1', 'parent_input_fingerprint' => $parent,
                'parent_counts' => ['facts' => count($input->facts), 'quantities' => count($input->derivedQuantities),
                    'decisions' => count($input->decisions), 'candidates' => count($input->candidates), 'missing_documents' => count($input->missingDocuments)]],
            $input->allowedWorkSections);
    }

    private function fits(EstimateComposerInput $input): bool
    {
        $content = CanonicalPipelineJson::encode(['project' => $input->canonicalPayload()]);

        return strlen($content) <= $this->maxInputBytes && (new EstimatorTextPayloadBudget)->fits($content, $this->wirePolicy);
    }

    private function assertFits(EstimateComposerInput $input): void
    {
        if (! $this->fits($input)) {
            throw new InvalidArgumentException('estimate_composer_entity_context_too_large');
        }
    }
}
