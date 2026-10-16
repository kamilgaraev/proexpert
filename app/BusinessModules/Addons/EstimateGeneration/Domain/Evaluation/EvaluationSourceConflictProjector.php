<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use Closure;

final readonly class EvaluationSourceConflictProjector
{
    private Closure $translator;

    public function __construct(?Closure $translator = null)
    {
        $this->translator = $translator ?? static fn (string $key, array $replace): string => trans_message($key, $replace);
    }

    public function project(array $positions, ProjectModelSnapshot $snapshot): array
    {
        $requirements = $questions = [];
        $currentFacts = array_column($snapshot->facts, null, 'id');
        $entities = array_column($snapshot->entities, null, 'id');
        foreach ($snapshot->conflicts as $conflict) {
            if ($conflict->status !== 'unresolved') {
                continue;
            }
            $factIds = $entityIds = [];
            foreach ($conflict->facts as $fact) {
                if (isset($currentFacts[$fact->id]) && $currentFacts[$fact->id]->version === $fact->version
                    && $currentFacts[$fact->id]->sourceVersion === $fact->sourceVersion) {
                    $factIds[] = $fact->id;
                    $entityIds[] = $fact->entityId;
                }
            }
            if (count($factIds) !== count($conflict->facts)) {
                continue;
            }
            $roomConflict = array_filter($entityIds, static fn (string $id): bool => ($entities[$id]->type ?? null) === 'room') !== [];
            $affected = [];
            $unboundScope = false;
            foreach ($positions as $index => $position) {
                $operands = array_column($position['quantity_basis']['formula_inputs']['operands'] ?? [], 'fact_id');
                $sources = $quantitySources = [];
                foreach ($position['source_refs'] ?? [] as $reference) {
                    if (is_string($reference)) {
                        $sources[] = $reference;
                        $quantitySources[] = $reference;
                    } elseif (is_array($reference) && is_string($reference['fact_id'] ?? null)) {
                        $sources[] = $reference['fact_id'];
                        if (($reference['role'] ?? 'quantity_operand') === 'quantity_operand') {
                            $quantitySources[] = $reference['fact_id'];
                        }
                    }
                }
                $unboundRoomWork = ($position['entity_id'] ?? null) === null && ($position['section'] ?? null) === 'finishing' && $roomConflict;
                if (! in_array($position['entity_id'] ?? null, $entityIds, true)
                    && array_intersect([...$operands, ...$sources], $factIds) === []) {
                    $unboundScope = $unboundScope || $unboundRoomWork;

                    continue;
                }
                $affected[] = $position['key'];
                if (array_intersect([...$operands, ...$quantitySources], $factIds) !== []) {
                    $positions[$index]['quantity'] = null;
                    $positions[$index]['quantity_status'] = 'unknown';
                    $positions[$index]['price_snapshot'] = null;
                }
            }
            if ($affected === [] && ! $unboundScope) {
                continue;
            }
            $requirements[] = ['requirement' => 'source_conflict', 'reason' => 'unresolved_source_conflict',
                'conflict_id' => $conflict->id, 'fact_ids' => $factIds, 'position_keys' => $affected];
            $questions[] = ['key' => 'conflict:'.$conflict->id, 'conflict_id' => $conflict->id,
                'question' => ($this->translator)('estimate_generation.evaluation_source_conflict_question', []),
                'affected_position_keys' => $affected, 'correctness_blocker' => true, 'cost_blocker' => true,
                'source_conflict_blocker' => true,
                'easy_to_answer' => true, 'next_action' => 'review_source_questions',
                'effect' => ($this->translator)('estimate_generation.evaluation_source_conflict_effect', [])];
        }

        return ['positions' => $positions, 'requirements' => $requirements, 'questions' => $questions];
    }
}
