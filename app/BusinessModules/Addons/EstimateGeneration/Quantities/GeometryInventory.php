<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** A view of canonical entities, never an automatic assertion of completeness. */
final class GeometryInventory
{
    public const RELATIONS = [
        'room_walls' => ['room', 'wall', 'room_id'],
        'wall_openings' => ['wall', 'opening', 'wall_id'],
        'roof_facets' => ['roof', 'roof_facet', 'roof_id'],
        'roof_openings' => ['roof', 'roof_opening', 'roof_id'],
    ];

    public static function type(Entity $entity): string
    {
        return $entity->attributes['semantic_type'] ?? $entity->attributes['properties']['semantic_type'] ?? $entity->type;
    }

    /** @return array<string, Entity> */
    public static function entities(ProjectModelSnapshot $snapshot, ?array $documentVersions = null): array
    {
        $current = $documents = [];
        foreach ($snapshot->facts as $fact) {
            $current[$fact->entityId][$fact->sourceVersion] = true;
            if ($fact->origin === 'document') {
                $documents[$fact->entityId][$fact->sourceVersion] = true;
            }
        }
        $entities = [];
        foreach ($snapshot->entities as $entity) {
            $manual = isset($entity->attributes['identity']['manual_key']) || isset($entity->attributes['properties']['identity']['manual_key']);
            $documentCurrent = $documentVersions === null ? isset($documents[$entity->id][$entity->sourceVersion])
                : (($documentVersions[$entity->id]['source_version'] ?? null) === $entity->sourceVersion);
            if (($manual ? isset($current[$entity->id][$entity->sourceVersion]) : $documentCurrent)
                && in_array(self::type($entity), ['room', 'wall', 'opening', 'roof', 'roof_facet', 'roof_opening'], true)) {
                $entities[$entity->id] = $entity;
            }
        }
        ksort($entities, SORT_STRING);

        return $entities;
    }

    public static function capture(ProjectModelSnapshot $snapshot, string $parentId, string $relation, ?array $documentVersions = null): array
    {
        $definition = self::RELATIONS[$relation] ?? null;
        $entities = self::entities($snapshot, $documentVersions);
        $parent = $entities[$parentId] ?? null;
        if ($definition === null || $parent === null || self::type($parent) !== $definition[0]) {
            throw new InvalidArgumentException('geometry_inventory_target_unavailable');
        }
        $members = $unassigned = [];
        foreach ($entities as $entity) {
            if (self::type($entity) !== $definition[1]) {
                continue;
            }
            $linkedId = $entity->attributes[$definition[2]] ?? null;
            $linked = is_string($linkedId) ? ($entities[$linkedId] ?? null) : null;
            if ($linkedId === $parentId) {
                $members[] = self::identity($entity);
            } elseif ($linked === null || self::type($linked) !== $definition[0]) {
                $unassigned[] = self::identity($entity);
            }
        }
        $identity = ['contract' => 'geometry-inventory:v1', 'parent' => self::identity($parent), 'relation' => $relation,
            'members' => $members, 'unassigned' => $unassigned];
        if ($documentVersions !== null) {
            $identity['document_anchors'] = array_intersect_key($documentVersions,
                array_fill_keys([$parentId, ...array_column($members, 'id'), ...array_column($unassigned, 'id')], true));
        }

        return ['parent_entity_id' => $parentId, 'parent_type' => $definition[0], 'relation' => $relation,
            'fingerprint' => hash('sha256', CanonicalPipelineJson::encode($identity)),
            'member_ids' => array_column($members, 'id'), 'unassigned_ids' => array_column($unassigned, 'id'),
            'completeness_confirmed' => false];
    }

    private static function identity(Entity $entity): array
    {
        return ['id' => $entity->id, 'source_version' => $entity->sourceVersion,
            'stable_key' => $entity->stableKey, 'type' => self::type($entity), 'attributes' => $entity->attributes];
    }

    /** Trusted source anchors survive user parameter corrections, but not document replacement/exclusion. */
    public static function currentDocumentVersions(ProjectModelSnapshot $snapshot): array
    {
        $record = $snapshot->entities[0] ?? $snapshot->facts[0] ?? null;
        if ($record === null) {
            return [];
        }
        $documents = [];
        foreach (\App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()
            ->where('organization_id', $record->organizationId)->where('project_id', $record->projectId)->where('session_id', $record->sessionId)
            ->whereNull('ignored_at')->where('status', '<>', 'ignored')->orderBy('id')->sharedLock()->get() as $document) {
            if (preg_match('/\A[a-f0-9]{64}\z/i', (string) $document->checksum_sha256) === 1) {
                $documents[(int) $document->id] = \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion::fromDocument($document);
            }
        }
        $anchors = [];
        $entityVersions = array_column($snapshot->entities, 'sourceVersion', 'id');
        foreach (array_chunk(array_column($snapshot->entities, 'id'), 1000) as $entityIds) {
            $rows = \Illuminate\Support\Facades\DB::table('estimate_generation_project_model_assertions as fact')
                ->join('estimate_generation_project_model_entities as entity', 'entity.id', '=', 'fact.entity_id')
                ->join('estimate_generation_project_model_fact_evidence as binding', 'binding.fact_id', '=', 'fact.id')
                ->join('estimate_generation_evidence as proof', 'proof.id', '=', 'binding.evidence_id')
                ->where('fact.organization_id', $record->organizationId)->where('fact.project_id', $record->projectId)->where('fact.session_id', $record->sessionId)
                ->where('fact.fact_origin', 'document')->where('proof.source_type', 'document')->whereNull('proof.invalidated_at')
                ->whereIn('entity.stable_key', $entityIds)
                ->whereColumn('entity.source_version', 'fact.source_version')->whereColumn('binding.source_version', 'fact.source_version')
                ->whereColumn('proof.source_version', 'binding.evidence_source_version')->whereColumn('proof.invalidation_version', 'binding.evidence_invalidation_version');
            foreach (['organization_id', 'project_id', 'session_id'] as $field) {
                foreach (['entity', 'binding', 'proof'] as $table) {
                    $rows->whereColumn($table.'.'.$field, 'fact.'.$field);
                }
            }
            foreach ($rows->distinct()->orderBy('entity.stable_key')->orderBy('proof.id')->get([
                'entity.stable_key as entity_key', 'entity.source_version', 'proof.id', 'proof.source_ref', 'proof.locator',
            ]) as $row) {
                if (preg_match('/\Adocument:([1-9][0-9]*)\z/', $row->source_ref, $match) !== 1
                    || ($documents[(int) $match[1]] ?? null) !== $row->source_version
                    || ($entityVersions[$row->entity_key] ?? null) !== $row->source_version) {
                    continue;
                }
                $locator = json_decode($row->locator, true, 64, JSON_THROW_ON_ERROR);
                $anchors[$row->entity_key]['source_version'] = $row->source_version;
                $anchors[$row->entity_key]['evidence']['evidence:'.$row->id] = [
                    'id' => 'evidence:'.$row->id, 'document_id' => (int) $match[1], 'source_version' => $row->source_version,
                    'page' => $locator['page'] ?? $locator['unit_index'] ?? null, 'region' => $locator['region'] ?? null,
                    'native_reference' => $locator['native_reference'] ?? null,
                ];
            }
        }
        ksort($anchors, SORT_STRING);

        return $anchors;
    }
}
