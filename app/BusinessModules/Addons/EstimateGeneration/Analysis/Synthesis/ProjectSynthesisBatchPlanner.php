<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** All deterministic links/questions are covered once; the shared source context remains intact. */
final class ProjectSynthesisBatchPlanner
{
    public const CANDIDATES_PER_PART = 64;

    public const MAX_PARTS = 64;

    public function plan(array $links, array $questions): array
    {
        $records = [];
        $seen = [];
        foreach (['links' => $links, 'questions' => $questions] as $kind => $items) {
            foreach ($items as $item) {
                $id = $item[$kind === 'links' ? 'id' : 'conflict_id'] ?? null;
                $key = $kind.':'.$id;
                if (! is_string($id) || trim($id) === '' || isset($seen[$key])) {
                    throw new InvalidArgumentException('project_synthesis_candidate_identity_ambiguous');
                }
                $seen[$key] = true;
                $records[] = ['key' => $key, 'kind' => $kind, 'value' => $item];
            }
        }
        if (count($records) > self::CANDIDATES_PER_PART * self::MAX_PARTS) {
            throw new InvalidArgumentException('project_synthesis_parts_exceed_limit');
        }
        if (count($records) <= self::CANDIDATES_PER_PART) {
            return [['id' => 'whole', 'links' => $links, 'questions' => $questions]];
        }
        usort($records, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);
        $parts = [];
        foreach (array_chunk($records, self::CANDIDATES_PER_PART) as $chunk) {
            $part = ['id' => 'part:'.substr(hash('sha256', CanonicalPipelineJson::encode(array_column($chunk, 'key'))), 0, 40),
                'links' => [], 'questions' => []];
            foreach ($chunk as $record) {
                $part[$record['kind']][] = $record['value'];
            }
            $parts[] = $part;
        }

        return $parts;
    }
}
