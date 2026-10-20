<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class RoomCeilingScope
{
    public const CONTRACT = 'room-ceiling-scope:v1';

    public const METHOD = 'horizontal_rectangle';

    public static function anchor(string $roomId): string
    {
        return 'room-ceiling:'.hash('sha256', $roomId);
    }

    public static function sources(ProjectModelSnapshot $snapshot): array
    {
        $result = [];
        foreach ($snapshot->entities as $room) {
            if (GeometryInventory::type($room) !== 'room'
                || ($room->attributes['contextual_only'] ?? false) || ($room->attributes['excluded_by_document_note'] ?? false)
                || ($room->attributes['properties']['contextual_only'] ?? false) || ($room->attributes['properties']['excluded_by_document_note'] ?? false)) {
                continue;
            }
            $dimensions = [];
            foreach (['length', 'width'] as $role) {
                $matches = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->entityId === $room->id
                    && in_array($fact->type, FactVocabulary::aliases($role), true)));
                if (count($matches) !== 1) {
                    continue 2;
                }
                $fact = $matches[0];
                if ($fact->status !== 'confirmed' || ! in_array($fact->origin, ['document', 'user_input', 'user_assumption'], true)
                    || (! is_string($fact->value) && ! is_int($fact->value)) || FactVocabulary::factor('length', $fact->unit) === null
                    || self::conflicted($snapshot, $fact)) {
                    continue 2;
                }
                $dimensions[$role] = self::signature($fact);
            }
            $result[$room->id] = ['contract' => self::CONTRACT, 'answer_kind' => 'room_ceiling_scope',
                'room_entity_id' => $room->id, 'room_source_version' => $room->sourceVersion,
                'room_fingerprint' => self::fingerprint($room), 'method' => self::METHOD, 'dimensions' => $dimensions];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    public static function roomIds(ProjectModelSnapshot $snapshot): array
    {
        $ids = [];
        foreach ($snapshot->entities as $room) {
            if (GeometryInventory::type($room) === 'room'
                && ! ($room->attributes['contextual_only'] ?? false) && ! ($room->attributes['excluded_by_document_note'] ?? false)
                && ! ($room->attributes['properties']['contextual_only'] ?? false) && ! ($room->attributes['properties']['excluded_by_document_note'] ?? false)) {
                $ids[] = $room->id;
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    public static function select(ProjectModelSnapshot $snapshot, array $decisions): array
    {
        $sources = self::sources($snapshot);
        $accepted = array_column(array_filter($decisions, static fn ($decision): bool => $decision instanceof Decision), null, 'id');
        $choices = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'evaluation_answer' && is_array($fact->value) && ($fact->value['answer_kind'] ?? null) === 'room_ceiling_scope') {
                $choices[$fact->value['room_entity_id'] ?? ''][] = $fact;
            }
        }
        $result = [];
        foreach ($choices as $id => $facts) {
            if (count($facts) !== 1 || ! isset($sources[$id])) {
                continue;
            }
            $fact = $facts[0];
            $decision = $accepted[$fact->value['decision_id'] ?? ''] ?? null;
            $version = 'sha256:'.hash('sha256', 'evaluation-choices:v1|'.implode('|', [$fact->organizationId, $fact->projectId, $fact->sessionId]));
            $anchor = 'entity:'.hash('sha256', $version.'|'.self::anchor($id));
            $source = $sources[$id];
            if ($fact->unit !== null || $fact->sourceVersion !== $version || $fact->entityId !== $anchor
                || ! $decision instanceof Decision || (int) $decision->actorId < 1 || $decision->version !== $fact->version
                || self::conflicted($snapshot, $fact) || ! (new EvaluationChoiceProof)->accepted($snapshot, $fact, $decision)
                || array_diff(array_keys($fact->value), [...array_keys($source), 'decision_id', 'reason']) !== []) {
                continue;
            }
            foreach ($source as $key => $value) {
                if (! array_key_exists($key, $fact->value) || CanonicalPipelineJson::encode([$value]) !== CanonicalPipelineJson::encode([$fact->value[$key]])) {
                    continue 2;
                }
            }
            $result[$id] = [...$source, 'fact_id' => $fact->id, 'fact_version' => $fact->version,
                'decision_id' => $decision->id, 'evidence_ids' => $fact->evidenceIds];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private static function signature(Fact $fact): array
    {
        return ['fact_id' => $fact->id, 'fact_version' => $fact->version, 'source_version' => $fact->sourceVersion,
            'value_hash' => DirectQuantityBinding::valueHash($fact->value), 'unit' => $fact->unit, 'type' => $fact->type];
    }

    private static function fingerprint(Entity $room): string
    {
        return hash('sha256', CanonicalPipelineJson::encode(['id' => $room->id, 'source_version' => $room->sourceVersion,
            'stable_key' => $room->stableKey, 'attributes' => $room->attributes]));
    }

    private static function conflicted(ProjectModelSnapshot $snapshot, Fact $fact): bool
    {
        foreach ($snapshot->conflicts as $conflict) {
            foreach ($conflict->status === 'unresolved' ? $conflict->facts : [] as $other) {
                if ([$fact->id, $fact->version, $fact->sourceVersion] === [$other->id, $other->version, $other->sourceVersion]) {
                    return true;
                }
            }
        }

        return false;
    }
}
