<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\NativeParameterBacklog;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class FactoryInputInspector
{
    public function __construct(private NativeParameterBacklog $native) {}

    public function inspect(EstimateGenerationSession $session, ProjectModelSnapshot $snapshot, string $token): array
    {
        return self::summarize($this->native->current($session, $snapshot, $token));
    }

    public static function summarize(array $native): array
    {
        $limitations = $native['limitations'];
        $status = ! $native['available'] ? 'unavailable'
            : (array_diff($limitations, ['questions_truncated']) !== [] ? 'source_review_required'
                : ($native['total_questions'] > 0 ? 'awaiting_input' : 'planning_required'));

        return ['step' => 'inspect_input', 'outcome' => $status,
            'question_refs' => array_map(static fn (array $question): array => [
                'key' => $question['key'], 'version' => $question['answer']['version'],
                'entity_id' => $question['answer']['entity_id'], 'parameter' => $question['answer']['parameter'],
                'can_answer' => $question['can_answer'],
            ], $native['questions']),
            'total_questions' => $native['total_questions'], 'truncated' => $native['truncated'],
            'limitations' => $limitations, 'estimate_complete' => false, 'paid_actions_enabled' => false, 'cost_units' => '0'];
    }
}
