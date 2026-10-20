<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomCeilingReplacement;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomCeilingScope;
use Illuminate\Validation\ValidationException;

final readonly class RoomCeilingReplacementProof
{
    public function __construct(private ProjectModelRepository $models) {}

    public function evidenceIds(EstimateGenerationSession $session, array $items, ProjectModelSnapshot $snapshot): array
    {
        $records = $session->input_payload['room_ceiling_replacements'] ?? [];
        $facts = array_column($snapshot->facts, null, 'id');
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($records, 'fact_id')]);
        $decisions = array_column($decisions, null, 'id');
        $proofs = new EvaluationChoiceProof;
        $ids = $seen = [];
        $activeWorks = $childKeys = [];
        foreach ($records as $record) {
            if (($record['generation_id'] ?? null) === ($session->input_payload['composition_generation_id'] ?? null)) {
                $activeWorks[$record['work_key']] = true;
                foreach ($record['rooms'] as $key) {
                    $childKeys[$key] = true;
                }
            }
        }
        foreach ($items as $item) {
            $metadata = $item['metadata']['room_ceiling_replacement'] ?? null;
            $record = $records[$item['key'] ?? ''] ?? null;
            if ($record !== null && ($record['generation_id'] ?? null) === ($session->input_payload['composition_generation_id'] ?? null)) {
                $this->reject();
            }
            if ($metadata === null) {
                if (isset($activeWorks[RoomCeilingReplacement::work($item) ?? '']) || isset($childKeys[$item['key'] ?? ''])
                    || str_starts_with($item['key'] ?? '', 'room-ceiling:')) {
                    $this->reject();
                }

                continue;
            }
            if (! is_array($metadata)) {
                $this->reject();
            }
            $parent = $metadata['work_item_key'] ?? '';
            $record = $records[$parent] ?? null;
            $entity = $item['metadata']['scope_entity_id'] ?? null;
            $work = RoomCeilingReplacement::work($item);
            if (! is_array($metadata) || ! is_array($record) || ! is_string($entity) || $work === null
                || ($record['contract'] ?? null) !== RoomCeilingReplacement::CONTRACT
                || ($record['answer_kind'] ?? null) !== 'room_ceiling_replacement'
                || ($record['generation_id'] ?? null) !== ($session->input_payload['composition_generation_id'] ?? null)
                || CanonicalPipelineJson::encode($metadata) !== CanonicalPipelineJson::encode($record)
                || ($record['rooms'][$entity] ?? null) !== ($item['key'] ?? null)
                || ($item['key'] ?? null) !== RoomCeilingReplacement::childKey($parent, $entity)
                || ($record['work_key'] ?? null) !== $work || ($item['entity_id'] ?? null) !== $entity
                || ($item['metadata']['generation_source'] ?? null) !== 'accepted_room_ceiling_replacement'
                || isset($seen[$work][$entity])) {
                $this->reject();
            }
            $seen[$work][$entity] = true;
            $fact = $facts[$record['fact_id'] ?? ''] ?? null;
            $decision = $decisions[$record['decision_id'] ?? ''] ?? null;
            $version = 'sha256:'.hash('sha256', 'evaluation-choices:v1|'.implode('|', $scope));
            $anchor = 'entity:'.hash('sha256', $version.'|room-ceiling-replacement:'.hash('sha256', $parent));
            if ($fact === null || $decision === null || $fact->type !== 'evaluation_answer' || $fact->unit !== null
                || $fact->entityId !== $anchor || ($record['ledger_entity_id'] ?? null) !== $anchor || $fact->sourceVersion !== $version
                || (int) $decision->actorId < 1 || $decision->version !== $fact->version || ! $proofs->accepted($snapshot, $fact, $decision)) {
                $this->reject();
            }
            $value = $fact->value;
            unset($value['decision_id'], $value['reason']);
            $stored = $record;
            unset($stored['fact_id'], $stored['decision_id'], $stored['ledger_entity_id']);
            if (CanonicalPipelineJson::encode($value) !== CanonicalPipelineJson::encode($stored)) {
                $this->reject();
            }
            $rooms = array_keys($record['rooms']);
            sort($rooms, SORT_STRING);
            if ($rooms !== RoomCeilingScope::roomIds($snapshot)) {
                $this->reject();
            }
            foreach ($snapshot->conflicts as $conflict) {
                foreach ($conflict->status === 'unresolved' ? $conflict->facts : [] as $other) {
                    if ([$fact->id, $fact->version, $fact->sourceVersion] === [$other->id, $other->version, $other->sourceVersion]) {
                        $this->reject();
                    }
                }
            }
            foreach ($fact->evidenceIds as $id) {
                $ids[$id] = $id;
            }
        }
        $scoped = [];
        foreach ($items as $item) {
            $work = RoomCeilingReplacement::work($item);
            if ($work !== null && isset($seen[$work]) && ! isset($item['metadata']['room_ceiling_replacement'])) {
                $this->reject();
            }
            if ($work !== null && isset($item['metadata']['room_ceiling_replacement'])) {
                $scoped[] = $item;
            }
        }
        if ($scoped !== []) {
            $scopeFacts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'evaluation_answer'
                && is_array($fact->value) && ($fact->value['answer_kind'] ?? null) === 'room_ceiling_scope'));
            $choices = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($scopeFacts, 'id')]);
            $current = RoomCeilingScope::select($snapshot, $choices);
            foreach ($scoped as $item) {
                $room = $item['metadata']['scope_entity_id'];
                if (! isset($current[$room]) || ! in_array($current[$room]['fact_id'], $item['metadata']['room_ceiling_replacement']['scope_fact_ids'] ?? [], true)) {
                    $this->reject();
                }
            }
        }

        return array_values($ids);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['ceiling' => trans_message('estimate_ceiling.changed')]);
    }
}
