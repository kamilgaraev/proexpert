<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WallEstimateScope;

final class WallAssemblyQuestions
{
    public function append(array $backlog, ProjectModelSnapshot $snapshot, array $decisions, array $documentVersions,
        string $token, array $sections, int $stateVersion, bool $editable): array
    {
        if (array_intersect($sections, ['structures', 'finishing']) === []) {
            return $backlog;
        }
        if (GeometryRelationOverlay::factIds($snapshot) !== []) {
            $snapshot = GeometryRelationOverlay::apply($snapshot, $decisions, $documentVersions);
        }
        $selected = WallEstimateScope::select($snapshot, $decisions, $documentVersions);
        $walls = WallEstimateScope::walls($snapshot, $documentVersions);
        ksort($walls, SORT_STRING);
        $names = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'entity_identity' && $fact->status === 'confirmed' && is_array($fact->value)
                && is_string($fact->value['name'] ?? null)) {
                $names[$fact->entityId] = mb_substr($fact->value['name'], 0, 160);
            }
        }
        $questions = [];
        foreach (array_diff_key($walls, $selected) as $id => $wall) {
            $previous = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->entityId === $id
                && $fact->sourceVersion === $wall->sourceVersion && $fact->type === 'wall_assembly'));
            $writable = $editable && count($previous) <= 1;
            foreach ($snapshot->conflicts as $conflict) {
                if ($conflict->status === 'unresolved') {
                    foreach ($conflict->facts as $fact) {
                        if (in_array($fact->id, array_column($previous, 'id'), true)) {
                            $writable = false;
                        }
                    }
                }
            }
            if (! $writable && $editable) {
                $backlog['limitations'] = array_values(array_unique([...$backlog['limitations'], 'source_review_required']));
            }
            $key = 'native-parameter:'.hash('sha256', $id.'|wall_assembly');
            $name = $names[$id] ?? $wall->attributes['name'] ?? trans_message('estimate_generation.native_questions.entities.wall');
            $fingerprint = GeometryInventory::entityFingerprint($wall, $documentVersions);
            $question = ['key' => $key, 'question' => trans_message('estimate_generation.wall_assembly.question', ['object' => $name]),
                'can_answer' => $writable, 'formula_ids' => ['wall_assembly'],
                'answer' => ['kind' => 'wall_assembly', 'entity_id' => $id, 'entity_type' => 'wall', 'entity_name' => $name,
                    'entity_source_version' => $wall->sourceVersion, 'entity_fingerprint' => $fingerprint],
                'input_contract' => ['kind' => 'wall_assembly', 'basis' => 'assumption',
                    'roles' => array_map(static fn ($role): array => ['value' => $role, 'label' => trans_message('estimate_generation.wall_assembly.roles.'.$role),
                        'surfaces' => array_map(static fn ($surface): array => ['value' => $surface, 'label' => trans_message('estimate_generation.wall_assembly.surfaces.'.$surface)], WallEstimateScope::SURFACES[$role])], array_keys(WallEstimateScope::SURFACES)),
                    'description' => trans_message('estimate_generation.wall_assembly.assumption')]];
            $version = hash('sha256', CanonicalPipelineJson::encode([WallEstimateScope::CONTRACT, $token, $stateVersion, $sections, $question]));
            $question['answer']['version'] = $version;
            $question['answer']['native_question'] = ['key' => $key, 'version' => $version, 'input_fingerprint' => $token,
                'entity_source_version' => $wall->sourceVersion];
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
