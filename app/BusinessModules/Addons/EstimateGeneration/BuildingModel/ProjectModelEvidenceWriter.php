<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use JsonException;

final readonly class ProjectModelEvidenceWriter
{
    public function __construct(private Connection $database) {}

    /** @param list<SessionBuildingModelUnitData> $units */
    public function write(StoredBuildingModel $stored, array $units): void
    {
        if (! $stored->created) {
            return;
        }

        $context = $stored->context;
        $this->database->transaction(function () use ($stored, $context, $units): void {
            $this->database->table('estimate_generation_building_models')
                ->where('id', $stored->id)
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('session_id', $context->sessionId)
                ->where('content_version', $stored->contentVersion)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($this->candidates($units) as $candidate) {
                $this->persist($stored, $candidate);
            }
        }, 3);
    }

    /**
     * @param list<SessionBuildingModelUnitData> $units
     * @return list<array{unit: SessionBuildingModelUnitData, entity_key: string, entity: array<string,mixed>, assertion_type: string, value: array<string,mixed>, source: string, confidence: float, locator: array<string,mixed>}>
     */
    private function candidates(array $units): array
    {
        $candidates = [];
        foreach ($units as $unit) {
            if (! $unit instanceof SessionBuildingModelUnitData) {
                throw new InvalidArgumentException('Project model source units are invalid.');
            }
            foreach ($this->explicitCandidates($unit) as $candidate) {
                $candidates[] = $candidate;
            }
            foreach ($this->visionRoomCandidates($unit) as $candidate) {
                $candidates[] = $candidate;
            }
        }
        usort($candidates, static fn (array $left, array $right): int => [
            $left['entity_key'], $left['assertion_type'], $left['source'], $left['unit']->documentId, $left['unit']->index,
        ] <=> [
            $right['entity_key'], $right['assertion_type'], $right['source'], $right['unit']->documentId, $right['unit']->index,
        ]);

        return $candidates;
    }

    /** @return list<array{unit: SessionBuildingModelUnitData, entity_key: string, entity: array<string,mixed>, assertion_type: string, value: array<string,mixed>, source: string, confidence: float, locator: array<string,mixed>}> */
    private function explicitCandidates(SessionBuildingModelUnitData $unit): array
    {
        $items = $unit->payload['project_model_candidates'] ?? [];
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                continue;
            }
            $entity = $item['entity'] ?? null;
            $assertion = $item['assertion'] ?? null;
            if (! is_array($entity) || ! is_array($assertion)) {
                continue;
            }
            $kind = $entity['kind'] ?? null;
            $type = $assertion['type'] ?? null;
            $source = $assertion['source'] ?? 'ai_candidate';
            $value = $assertion['value'] ?? null;
            if (! is_string($kind) || ! is_string($type) || ! is_string($source) || ! is_array($value)
                || ! in_array($source, ['ai_candidate', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry'], true)
                || ! $this->validAssertion($kind, $type, $value)) {
                continue;
            }
            $identity = is_array($item['identity'] ?? null) ? $item['identity'] : [];
            $entityKey = $this->entityKey($kind, $identity, $unit, $index);
            $payload = $entity;
            $payload['kind'] = $kind;
            $payload['key'] = $entityKey;
            try {
                ProjectModelEntity::assertEntityPayload($kind, $entityKey, $payload);
            } catch (InvalidArgumentException) {
                continue;
            }
            $confidence = $this->confidence($assertion['confidence'] ?? $unit->confidence);
            $locator = is_array($item['locator'] ?? null) ? $item['locator'] : [];
            $result[] = [
                'unit' => $unit,
                'entity_key' => $entityKey,
                'entity' => $payload,
                'assertion_type' => $type,
                'value' => $value,
                'source' => $source,
                'confidence' => $confidence,
                'locator' => $locator,
            ];
        }

        return $result;
    }

    /** @return list<array{unit: SessionBuildingModelUnitData, entity_key: string, entity: array<string,mixed>, assertion_type: string, value: array<string,mixed>, source: string, confidence: float, locator: array<string,mixed>}> */
    private function visionRoomCandidates(SessionBuildingModelUnitData $unit): array
    {
        $analysis = $unit->payload['vision_analysis'] ?? null;
        $elements = is_array($analysis) && is_array($analysis['elements'] ?? null) ? $analysis['elements'] : [];
        $parser = new RoomAreaAnnotationParser;
        $result = [];
        foreach ($elements as $index => $element) {
            if (! is_array($element) || ($element['type'] ?? null) !== 'room') {
                continue;
            }
            $area = $parser->parse(is_string($element['label'] ?? null) ? $element['label'] : null);
            if ($area === null) {
                continue;
            }
            $identity = is_array($element['identity'] ?? null) ? $element['identity'] : [];
            $modelRoomKey = $element['key'] ?? null;
            $entityKey = is_string($modelRoomKey) && preg_match('/^[a-zA-Z][a-zA-Z0-9._:-]{0,79}$/D', $modelRoomKey) === 1
                ? $modelRoomKey
                : $this->entityKey('room', $identity, $unit, $index);
            $confidence = $this->confidence($element['confidence'] ?? $unit->confidence);
            $result[] = [
                'unit' => $unit,
                'entity_key' => $entityKey,
                'entity' => ['kind' => 'room', 'key' => $entityKey, 'area_m2' => $area['area_m2']],
                'assertion_type' => 'area',
                'value' => ['value' => $area['area_m2'], 'unit' => 'm2'],
                'source' => 'ai_candidate',
                'confidence' => $confidence,
                'locator' => ['element_index' => $index],
            ];
        }

        return $result;
    }

    /** @param array{unit: SessionBuildingModelUnitData, entity_key: string, entity: array<string,mixed>, assertion_type: string, value: array<string,mixed>, source: string, confidence: float, locator: array<string,mixed>} $candidate */
    private function persist(StoredBuildingModel $stored, array $candidate): void
    {
        $context = $stored->context;
        $entityId = $this->entity($stored, $candidate);
        $assertionKey = $this->stableKey('assertion', $candidate['entity_key'], $candidate['assertion_type'], $candidate['source'], (string) $candidate['unit']->documentId, (string) $candidate['unit']->index, ProjectModelValueFingerprint::for($candidate['value']));
        $assertionId = $this->assertion($stored, $entityId, $assertionKey, $candidate);
        if ($candidate['source'] === 'ai_candidate') {
            return;
        }
        $evidence = $this->activeEvidence($stored, $candidate['unit'], $candidate['locator']);
        if ($evidence === null) {
            return;
        }
        $this->database->table('estimate_generation_project_model_evidence_bindings')->insertOrIgnore([
            'building_model_id' => $stored->id,
            'organization_id' => $context->organizationId,
            'project_id' => $context->projectId,
            'session_id' => $context->sessionId,
            'source_version' => $stored->contentVersion,
            'entity_id' => $entityId,
            'assertion_id' => $assertionId,
            'correction_id' => null,
            'evidence_id' => (int) $evidence->id,
            'candidate_source' => $candidate['source'],
            'candidate_value_fingerprint' => ProjectModelValueFingerprint::for($candidate['value']),
            'evidence_source_version' => (string) $evidence->source_version,
            'evidence_invalidation_version' => (int) $evidence->invalidation_version,
            'created_at' => now(),
        ]);
    }

    /** @param array{entity_key: string, entity: array<string,mixed>, confidence: float} $candidate */
    private function entity(StoredBuildingModel $stored, array $candidate): int
    {
        $context = $stored->context;
        $this->database->table('estimate_generation_project_model_entities')->insertOrIgnore([
            'building_model_id' => $stored->id,
            'organization_id' => $context->organizationId,
            'project_id' => $context->projectId,
            'session_id' => $context->sessionId,
            'source_version' => $stored->contentVersion,
            'stable_key' => $candidate['entity_key'],
            'entity_kind' => $candidate['entity']['kind'],
            'payload' => $this->json($candidate['entity']),
            'confidence' => $candidate['confidence'],
            'created_at' => now(),
        ]);
        $id = $this->database->table('estimate_generation_project_model_entities')
            ->where('building_model_id', $stored->id)->where('stable_key', $candidate['entity_key'])->value('id');
        if (! is_int($id) && ! ctype_digit((string) $id)) {
            throw new InvalidArgumentException('Project model entity persistence failed.');
        }

        return (int) $id;
    }

    /** @param array{entity_key: string, assertion_type: string, value: array<string,mixed>, source: string, confidence: float} $candidate */
    private function assertion(StoredBuildingModel $stored, int $entityId, string $stableKey, array $candidate): int
    {
        $context = $stored->context;
        $payload = ['source' => $candidate['source'], ...$candidate['value']];
        $this->database->table('estimate_generation_project_model_assertions')->insertOrIgnore([
            'building_model_id' => $stored->id,
            'organization_id' => $context->organizationId,
            'project_id' => $context->projectId,
            'session_id' => $context->sessionId,
            'source_version' => $stored->contentVersion,
            'stable_key' => $stableKey,
            'entity_id' => $entityId,
            'assertion_type' => $candidate['assertion_type'],
            'payload' => $this->json($payload),
            'confidence' => $candidate['confidence'],
            'created_at' => now(),
        ]);
        $id = $this->database->table('estimate_generation_project_model_assertions')
            ->where('building_model_id', $stored->id)->where('stable_key', $stableKey)->value('id');
        if (! is_int($id) && ! ctype_digit((string) $id)) {
            throw new InvalidArgumentException('Project model assertion persistence failed.');
        }

        return (int) $id;
    }

    private function activeEvidence(StoredBuildingModel $stored, SessionBuildingModelUnitData $unit, array $locator): ?object
    {
        $context = $stored->context;
        $query = $this->database->table('estimate_generation_building_model_evidence as link')
            ->join('estimate_generation_evidence as evidence', function ($join): void {
                $join->on('evidence.id', '=', 'link.evidence_id')
                    ->on('evidence.organization_id', '=', 'link.organization_id')
                    ->on('evidence.project_id', '=', 'link.project_id')
                    ->on('evidence.session_id', '=', 'link.session_id');
            })
            ->where('link.building_model_id', $stored->id)
            ->where('link.organization_id', $context->organizationId)
            ->where('link.project_id', $context->projectId)
            ->where('link.session_id', $context->sessionId)
            ->where('evidence.source_version', $unit->sourceVersion)
            ->whereNull('evidence.invalidated_at')
            ->where('evidence.source_ref', 'document:'.$unit->documentId);
        foreach ($query->orderBy('evidence.id')->get(['evidence.id', 'evidence.source_version', 'evidence.invalidation_version', 'evidence.locator']) as $row) {
            $evidenceLocator = $this->decode($row->locator);
            if (($evidenceLocator['document_id'] ?? null) !== $unit->documentId
                || (($evidenceLocator['unit_index'] ?? $evidenceLocator['page'] ?? null) !== $unit->index)) {
                continue;
            }
            $matches = true;
            foreach ($locator as $key => $value) {
                if (! array_key_exists($key, $evidenceLocator) || $evidenceLocator[$key] !== $value) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $identity */
    private function entityKey(string $kind, array $identity, SessionBuildingModelUnitData $unit, int $index): string
    {
        $join = $this->unambiguousJoin($identity);
        $suffix = $join === null
            ? 'source:'.hash('sha256', $unit->documentId.'|'.$unit->sourceVersion.'|'.$unit->index.'|'.$index)
            : 'join:'.hash('sha256', $join);

        return $this->stableKey($kind, $suffix);
    }

    /** @param array<string,mixed> $identity */
    private function unambiguousJoin(array $identity): ?string
    {
        $room = $this->identityToken($identity['room_number'] ?? null);
        $floor = $this->identityToken($identity['floor'] ?? null);
        $marker = $this->identityToken($identity['section_marker'] ?? $identity['elevation'] ?? null);
        $axis = $this->identityToken($identity['axis'] ?? null);

        if ($room !== null && $floor !== null && $marker !== null) {
            return 'room:'.$room.'|floor:'.$floor.'|marker:'.$marker;
        }

        // An axis is only useful when the entity kind, measurement and role make
        // it unique across sheets. Axis alone collides between plans and sections.
        $entityType = $this->identityToken($identity['entity_type'] ?? null);
        $measurement = $this->identityToken($identity['measurement'] ?? null);
        $role = $this->identityToken($identity['role'] ?? null);

        return $axis !== null && $entityType !== null && $measurement !== null && $role !== null
            ? 'axis:'.$axis.'|entity:'.$entityType.'|measurement:'.$measurement.'|role:'.$role
            : null;
    }

    private function identityToken(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $token = mb_strtolower(trim((string) $value));

        return preg_match('/^[\pL\pN._:-]{1,80}$/uD', $token) === 1 ? $token : null;
    }

    private function confidence(mixed $value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.0;

        return is_finite($confidence) ? min(1.0, max(0.0, $confidence)) : 0.0;
    }

    /** @param array<string,mixed> $value */
    private function validAssertion(string $kind, string $type, array $value): bool
    {
        $positive = static fn (mixed $number): bool => (is_int($number) || is_float($number))
            && is_finite((float) $number) && $number > 0;

        return match ($type) {
            'area' => $kind === 'room' && $positive($value['value'] ?? null)
                && ($value['unit'] ?? null) === 'm2' && count($value) === 2,
            'dimension' => $kind === 'dimension' && $positive($value['value'] ?? null)
                && is_string($value['unit'] ?? null)
                && in_array($value['unit'], ['m', 'm2', 'm3', 'pcs', 'kg', 't', 'h'], true) && count($value) === 2,
            'room_purpose' => $kind === 'room' && is_string($value['value'] ?? null)
                && trim($value['value']) !== '' && count($value) === 1,
            'opening' => $kind === 'opening' && in_array($value['type'] ?? null, ['door', 'window', 'gate'], true)
                && $positive($value['width_m'] ?? null) && $positive($value['height_m'] ?? null) && count($value) === 3,
            default => false,
        };
    }

    private function stableKey(string ...$parts): string
    {
        return $parts[0].':'.substr(hash('sha256', implode('|', $parts)), 0, 48);
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new InvalidArgumentException('Project model value cannot be serialized.');
        }
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }
}
