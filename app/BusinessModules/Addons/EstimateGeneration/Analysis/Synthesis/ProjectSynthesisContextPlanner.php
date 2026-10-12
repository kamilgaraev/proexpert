<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextPayloadBudget;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** Candidate coverage is complete; slices retain their full parent identity. */
final class ProjectSynthesisContextPlanner
{
    public function prepare(ProjectSynthesisInput $root, array $links, array $questions, int $maximumBytes): array
    {
        for ($size = ProjectSynthesisBatchPlanner::CANDIDATES_PER_PART; $size >= 1; $size = intdiv($size, 2)) {
            $prepared = [];
            $fits = true;
            foreach ((new ProjectSynthesisBatchPlanner)->plan($links, $questions, $size) as $part) {
                $input = $root->withPart($part['id']);
                if (! $this->fits($input, $part, $maximumBytes)) {
                    $input = $this->slice($root, $part);
                }
                if (! $this->fits($input, $part, $maximumBytes)) {
                    $fits = false;
                    break;
                }
                $prepared[] = [...$part, 'input' => $input];
            }
            if ($fits) {
                return $prepared;
            }
        }

        throw new InvalidArgumentException('project_synthesis_input_requires_source_split');
    }

    private function fits(ProjectSynthesisInput $input, array $part, int $maximumBytes): bool
    {
        $payload = json_encode(['project' => $input->canonicalPayload(), 'candidate_links' => $part['links'],
            'candidate_questions' => $part['questions']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strlen($payload) <= $maximumBytes && (new EstimatorTextPayloadBudget)->fits($payload);
    }

    private function slice(ProjectSynthesisInput $root, array $part): ProjectSynthesisInput
    {
        $factIds = [];
        foreach ([...$part['links'], ...$part['questions']] as $candidate) {
            foreach ($candidate['fact_ids'] as $id) {
                $factIds[$id] = true;
            }
        }
        $entities = $quantityIds = [];
        do {
            $previous = count($factIds) + count($entities) + count($quantityIds);
            foreach ($root->facts as $fact) {
                $entity = $fact['entity_id'] ?? null;
                if (isset($factIds[$fact['id']]) && is_string($entity)) {
                    $entities[$entity] = true;
                }
                if (is_string($entity) && isset($entities[$entity])) {
                    $factIds[$fact['id']] = true;
                }
            }
            foreach ($root->derivedQuantities as $quantity) {
                $entity = $quantity['entity_id'] ?? null;
                $operands = $quantity['operand_fact_ids'] ?? [];
                if ((is_string($entity) && isset($entities[$entity])) || array_intersect($operands, array_keys($factIds)) !== []) {
                    $quantityIds[$quantity['id']] = true;
                    foreach ($operands as $id) {
                        $factIds[$id] = true;
                    }
                }
            }
        } while ($previous !== count($factIds) + count($entities) + count($quantityIds));
        $id = $part['id'] === 'whole' ? 'part:'.substr(hash('sha256', CanonicalPipelineJson::encode([
            'links' => array_column($part['links'], 'id'), 'questions' => array_column($part['questions'], 'conflict_id'),
        ])), 0, 40) : $part['id'];

        return $root->withContextPart($id,
            array_values(array_filter($root->facts, static fn (array $fact): bool => isset($factIds[$fact['id']]))),
            array_values(array_filter($root->derivedQuantities, static fn (array $quantity): bool => isset($quantityIds[$quantity['id']]))),
            array_values(array_filter($root->decisions, static fn (array $decision): bool => ! isset($decision['selected_fact_id'])
                || isset($factIds[$decision['selected_fact_id']]))));
    }
}
