<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope;

final class OpeningSystemQuestions
{
    public function append(array $backlog, ProjectModelSnapshot $snapshot, array $decisions, array $documentVersions,
        string $token, array $sections, int $stateVersion, bool $editable): array
    {
        if (! in_array('structures', $sections, true)) {
            return $backlog;
        }
        if (GeometryRelationOverlay::factIds($snapshot) !== []) {
            $snapshot = GeometryRelationOverlay::apply($snapshot, $decisions, $documentVersions);
        }
        $scopes = OpeningEstimateScope::select($snapshot, $decisions, $documentVersions);
        $systems = OpeningSystemScope::select($snapshot, $decisions, $documentVersions);
        $names = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'entity_identity' && $fact->status === 'confirmed' && is_array($fact->value)
                && is_string($fact->value['name'] ?? null)) {
                $names[$fact->entityId] = mb_substr($fact->value['name'], 0, 160);
            }
        }
        $questions = [];
        foreach (array_diff_key($scopes, $systems) as $id => $scope) {
            $previous = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->entityId === $id
                && $fact->type === 'opening_system' && $fact->sourceVersion === $scope['target_source_version']));
            $writable = $editable && count($previous) <= 1;
            foreach ($snapshot->conflicts as $conflict) {
                foreach ($conflict->status === 'unresolved' ? $conflict->facts : [] as $fact) {
                    if (in_array($fact->id, array_column($previous, 'id'), true)) {
                        $writable = false;
                    }
                }
            }
            if (! $writable && $editable) {
                $backlog['limitations'] = array_values(array_unique([...$backlog['limitations'], 'source_review_required']));
            }
            $key = 'native-parameter:'.hash('sha256', $id.'|opening_system');
            $name = $names[$id] ?? trans_message('estimate_opening.object');
            $question = ['key' => $key, 'question' => trans_message('estimate_opening.system_question', ['object' => $name]),
                'can_answer' => $writable, 'formula_ids' => [],
                'answer' => ['kind' => 'opening_system', 'entity_id' => $id, 'entity_type' => 'opening', 'entity_name' => $name,
                    'opening_kind' => $scope['opening_kind'],
                    'entity_source_version' => $scope['target_source_version'], 'entity_fingerprint' => $scope['entity_fingerprint'],
                    'scope_fact_id' => $scope['fact_id'], 'scope_fact_version' => $scope['fact_version']],
                'input_contract' => ['kind' => 'opening_system', 'basis' => 'input',
                    'choices' => array_map(static fn (string $system): array => ['value' => $system,
                        'label' => trans_message('estimate_opening.systems.'.$system)], OpeningSystemScope::choices($scope['opening_kind']))]];
            $version = hash('sha256', CanonicalPipelineJson::encode([OpeningSystemScope::CONTRACT, $token, $stateVersion, $sections, $question]));
            $question['answer']['version'] = $version;
            $question['answer']['native_question'] = ['key' => $key, 'version' => $version, 'input_fingerprint' => $token,
                'entity_source_version' => $scope['target_source_version']];
            $questions[] = $question;
        }
        $backlog['total_questions'] += count($questions);
        $backlog['questions'] = array_slice([...$backlog['questions'], ...$questions], 0, 50);
        $backlog['truncated'] = $backlog['total_questions'] > 50;
        if ($backlog['truncated']) {
            $backlog['limitations'] = array_values(array_unique([...$backlog['limitations'], 'questions_truncated']));
        }

        return $backlog;
    }
}
