<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DecimalValue;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceAttribute;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use InvalidArgumentException;

final readonly class ProjectModelEvidenceWriter
{
    public function __construct(
        private ProjectModelRepository $models,
        private EvidenceRepository $evidence,
    ) {}

    /** @param list<ObservationClaim> $claims @param list<ArbitrationDecision> $decisions */
    public function writeArbitration(array $claims, array $decisions, int $documentId, int $pageNumber): void
    {
        if ($claims === [] || $decisions === []) {
            throw new InvalidArgumentException('Arbitration projection cannot be empty.');
        }
        $byId = [];
        foreach ($claims as $claim) {
            $byId[$claim->id] = $claim;
        }
        $scope = $claims[0];
        $this->evidence->transaction($scope->organizationId, $scope->sessionId, function () use ($byId, $decisions, $documentId, $pageNumber, $scope): void {
            $entities = [];
            $facts = [];
            $domainEvidence = [];
            foreach ($decisions as $decision) {
                $claim = $byId[$decision->claimId] ?? throw new InvalidArgumentException('Arbitration claim is absent.');
                $this->assertScope($claim, $scope);
                $evidenceIds = [];
                foreach ($decision->supportingClaimIds as $supportingClaimId) {
                    $supportingClaim = $byId[$supportingClaimId]
                        ?? throw new InvalidArgumentException('Supporting arbitration claim is absent.');
                    $this->assertScope($supportingClaim, $scope);
                    if ($supportingClaim->evidenceRef === null
                        || ! in_array($supportingClaim->evidenceRef, $decision->evidenceRefs, true)) {
                        continue;
                    }
                    $hash = hash('sha256', $supportingClaim->id.'|'.$supportingClaim->sourceVersion.'|'.$supportingClaim->evidenceRef);
                    $node = $this->evidence->insertOrGet(new EvidenceData(
                        $scope->organizationId,
                        $scope->projectId,
                        $scope->sessionId,
                        EvidenceType::SourceFact,
                        EvidenceSourceType::Document,
                        'document:'.$documentId,
                        $scope->sourceVersion,
                        $this->evidenceLocator($supportingClaim, $documentId, $pageNumber, $hash),
                        [
                            'fact_key' => $this->evidenceFactKey($supportingClaim),
                            'fact_value' => $this->evidenceScalar($supportingClaim),
                        ],
                        $decision->status === 'accepted' && $supportingClaim->explicitEvidence ? 1.0 : 0.0,
                        EvidenceProducer::DrawingAnalyzer->value,
                        'sha256:'.hash('sha256', 'document-arbitration:v1'),
                    ));
                    $evidenceId = 'evidence:'.$node->id;
                    $evidenceIds[] = $evidenceId;
                    $domainEvidence[$evidenceId] = $this->domainEvidence($node);
                }
                $entityId = 'entity:'.hash('sha256', $claim->entityKey);
                $entities[$entityId] = new Entity(
                    $entityId,
                    $scope->organizationId,
                    $scope->projectId,
                    $scope->sessionId,
                    $scope->sourceVersion,
                    $this->entityType($claim),
                    $entityId,
                    ['source_key' => $claim->entityKey],
                );
                $factId = 'fact:'.hash('sha256', $claim->id.'|'.$decision->status.'|'.$scope->sourceVersion);
                $facts[$factId] = new Fact(
                    $factId,
                    $scope->organizationId,
                    $scope->projectId,
                    $scope->sessionId,
                    $scope->sourceVersion,
                    $entityId,
                    mb_substr($claim->factType, 0, 120),
                    $claim->value['data'],
                    $claim->unit,
                    $decision->status === 'accepted' ? 1.0 : 0.0,
                    $decision->status === 'unresolved' ? 'unresolved' : 'document',
                    match ($decision->status) {
                        'accepted' => 'confirmed',
                        'candidate' => 'candidate',
                        'unresolved' => 'unresolved',
                    },
                    $evidenceIds,
                );
            }
            $this->models->saveSourceModel(array_values($entities), array_values($facts), array_values($domainEvidence));
        });
    }

    private function evidenceScalar(ObservationClaim $claim): string|int|float|bool
    {
        $value = $claim->value['data'];
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return 'material:'.hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function evidenceLocator(ObservationClaim $claim, int $documentId, int $pageNumber, string $hash): array
    {
        $locator = [
            'document_id' => $documentId,
            'page' => $pageNumber,
            'element_key' => 'element:'.$hash,
        ];
        foreach (['unit_type', 'unit_index', 'sheet', 'region_key', 'bbox', 'source_key'] as $field) {
            if (array_key_exists($field, $claim->locator)) {
                $locator[$field] = $claim->locator[$field];
            }
        }

        return $locator;
    }

    private function evidenceFactKey(ObservationClaim $claim): string
    {
        $type = mb_strtolower($claim->factType);
        $native = EvidenceAttribute::tryFrom($type);
        if ($native !== null) {
            return $native->value;
        }

        return match (true) {
            str_contains($type, 'material'), str_contains($type, 'finish') => EvidenceAttribute::MaterialCode->value,
            str_contains($type, 'room') && str_contains($type, 'area') => EvidenceAttribute::RoomArea->value,
            str_contains($type, 'area') => EvidenceAttribute::Area->value,
            str_contains($type, 'perimeter') => EvidenceAttribute::Perimeter->value,
            str_contains($type, 'width') => EvidenceAttribute::OpeningWidth->value,
            str_contains($type, 'height') => EvidenceAttribute::WallHeight->value,
            str_contains($type, 'count'), str_contains($type, 'quantity') => EvidenceAttribute::Quantity->value,
            default => EvidenceAttribute::ElementTypeCode->value,
        };
    }

    private function assertScope(ObservationClaim $claim, ObservationClaim $scope): void
    {
        if ($claim->organizationId !== $scope->organizationId || $claim->projectId !== $scope->projectId
            || $claim->sessionId !== $scope->sessionId || $claim->sourceVersion !== $scope->sourceVersion) {
            throw new InvalidArgumentException('Arbitration claims do not share an exact scope.');
        }
    }

    private function entityType(ObservationClaim $claim): string
    {
        $haystack = mb_strtolower($claim->entityKey.' '.$claim->factType);

        return match (true) {
            str_contains($haystack, 'room') || str_contains($haystack, 'помещ') => 'room',
            str_contains($haystack, 'wall') || str_contains($haystack, 'стен') => 'wall',
            str_contains($haystack, 'opening') || str_contains($haystack, 'проем') || str_contains($haystack, 'проём') => 'opening',
            str_contains($haystack, 'equipment') || str_contains($haystack, 'оборуд') => 'equipment',
            str_contains($haystack, 'dimension') || str_contains($haystack, 'размер') => 'dimension',
            str_contains($haystack, 'quantity') || str_contains($haystack, 'колич') => 'quantity',
            default => 'material',
        };
    }

    public function write(StoredBuildingModel $stored, array $units, array $evidenceIds): void
    {
        $context = $stored->context;
        $nodes = $evidenceIds === [] ? [] : $this->evidence->activeNodesForUpdate(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            $evidenceIds,
        );
        if (array_map(static fn (EvidenceNode $node): int => $node->id, $nodes) !== $evidenceIds) {
            throw new InvalidArgumentException('Project model evidence must be active in the requested scope.');
        }

        $entities = [];
        $facts = [];
        $domainEvidence = [];
        foreach ($this->candidates($units) as $candidate) {
            $entityKey = $candidate['entity_key'];
            $attributes = $candidate['entity'];
            unset($attributes['kind'], $attributes['key']);
            $entities[$entityKey] = new Entity(
                $entityKey,
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $stored->contentVersion,
                $candidate['entity']['kind'],
                $entityKey,
                $attributes,
            );
            $node = $candidate['source'] === 'ai_candidate'
                ? null
                : $this->matchingEvidence($nodes, $candidate);
            $evidenceId = $node === null ? null : 'evidence:'.$node->id;
            if ($node !== null) {
                $domainEvidence[$evidenceId] = $this->domainEvidence($node);
            }
            $value = $candidate['value']['value'] ?? $candidate['value'];
            if (is_int($value) || is_float($value)) {
                $value = DecimalValue::canonical((string) $value);
            }
            $unit = is_string($candidate['value']['unit'] ?? null) ? $candidate['value']['unit'] : null;
            $factId = $this->stableKey(
                'fact',
                $entityKey,
                $candidate['assertion_type'],
                $candidate['source'],
                (string) $candidate['unit']->documentId,
                (string) $candidate['unit']->index,
                ProjectModelValueFingerprint::for($candidate['value']),
            );
            $facts[$factId] = new Fact(
                $factId,
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $stored->contentVersion,
                $entityKey,
                $candidate['assertion_type'],
                $value,
                $unit,
                $candidate['confidence'],
                $candidate['source'] === 'ai_candidate' ? 'ai_inference' : 'document',
                $node === null ? 'candidate' : 'confirmed',
                $evidenceId === null ? [] : [$evidenceId],
            );
        }

        $conflicts = $this->conflicts($facts);
        $conflictedIds = [];
        foreach ($conflicts as $conflict) {
            foreach ($conflict->facts as $fact) {
                $conflictedIds[$fact->id] = true;
            }
        }
        foreach ($conflictedIds as $factId => $_) {
            $fact = $facts[$factId];
            $facts[$factId] = new Fact(
                $fact->id,
                $fact->organizationId,
                $fact->projectId,
                $fact->sessionId,
                $fact->sourceVersion,
                $fact->entityId,
                $fact->type,
                $fact->value,
                $fact->unit,
                $fact->confidence,
                $fact->origin,
                'conflicted',
                $fact->evidenceIds,
                $fact->version,
                $fact->supersedesFactId,
            );
        }
        if ($conflictedIds !== []) {
            $conflicts = $this->conflicts($facts);
        }

        $this->models->saveSourceModel(
            array_values($entities),
            array_values($facts),
            array_values($domainEvidence),
            $conflicts,
        );
    }

    private function matchingEvidence(array $nodes, array $candidate): ?EvidenceNode
    {
        foreach ($nodes as $node) {
            if ($node->sourceVersion !== $candidate['unit']->sourceVersion
                || $node->sourceRef !== 'document:'.$candidate['unit']->documentId
                || (($node->locator['unit_index'] ?? $node->locator['page'] ?? null) !== $candidate['unit']->index)) {
                continue;
            }
            if (ProjectModelEvidenceContract::confirms($candidate['source'], [
                'type' => $node->type->value,
                'source_type' => $node->sourceType->value,
                'producer_name' => $node->producerName,
                'producer_version' => $node->producerVersion,
                'source_ref' => $node->sourceRef,
                'locator' => $node->locator,
                'value' => $node->value,
            ], $candidate['value'], $candidate['locator'])) {
                return $node;
            }
        }

        return null;
    }

    private function domainEvidence(EvidenceNode $node): Evidence
    {
        $page = $node->locator['page'] ?? $node->locator['unit_index'] ?? null;
        $page = is_int($page) && $page > 0 ? $page : null;
        $region = null;
        $bbox = $node->locator['bbox'] ?? null;
        if (is_array($bbox) && count($bbox) === 4 && array_filter($bbox, 'is_numeric') === $bbox) {
            [$left, $top, $right, $bottom] = array_map('floatval', $bbox);
            if ($left >= 0 && $top >= 0 && $right > $left && $bottom > $top) {
                $region = ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top];
            }
        }

        return new Evidence(
            'evidence:'.$node->id,
            $node->organizationId,
            $node->projectId,
            $node->sessionId,
            $node->sourceVersion,
            $node->sourceRef,
            $node->sourceType->value,
            $page,
            $region,
            'evidence-node:'.$node->id,
        );
    }

    private function conflicts(array $facts): array
    {
        $groups = [];
        foreach ($facts as $fact) {
            if ($fact->status !== 'confirmed' && $fact->status !== 'conflicted') {
                continue;
            }
            $groups[$fact->entityId.'|'.$fact->type][] = $fact;
        }
        $result = [];
        foreach ($groups as $key => $group) {
            $values = [];
            foreach ($group as $fact) {
                $values[ProjectModelValueFingerprint::for(['value' => $fact->value, 'unit' => $fact->unit])] = true;
            }
            if (count($values) > 1) {
                $result[] = Conflict::between(
                    'conflict:'.substr(hash('sha256', $key.'|'.implode('|', array_keys($values))), 0, 48),
                    $group,
                    'cross_source_value_mismatch',
                );
            }
        }

        return $result;
    }

    private function candidates(array $units): array
    {
        $candidates = [];
        foreach ($units as $unit) {
            $candidates = [...$candidates, ...$this->explicitCandidates($unit), ...$this->visionRoomCandidates($unit)];
        }
        usort($candidates, static fn (array $left, array $right): int => [
            $left['entity_key'], $left['assertion_type'], $left['source'], $left['unit']->documentId, $left['unit']->index,
        ] <=> [
            $right['entity_key'], $right['assertion_type'], $right['source'], $right['unit']->documentId, $right['unit']->index,
        ]);

        return $candidates;
    }

    private function explicitCandidates(SessionBuildingModelUnitData $unit): array
    {
        $items = $unit->payload['project_model_candidates'] ?? [];
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $index => $item) {
            $entity = is_array($item) ? ($item['entity'] ?? null) : null;
            $assertion = is_array($item) ? ($item['assertion'] ?? null) : null;
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
            $payload = [...$entity, 'kind' => $kind, 'key' => $entityKey];
            try {
                ProjectModelEntity::assertEntityPayload($kind, $entityKey, $payload);
            } catch (InvalidArgumentException) {
                continue;
            }
            $result[] = [
                'unit' => $unit,
                'entity_key' => $entityKey,
                'entity' => $payload,
                'assertion_type' => $type,
                'value' => $value,
                'source' => $source,
                'confidence' => $this->confidence($assertion['confidence'] ?? $unit->confidence),
                'locator' => is_array($item['locator'] ?? null) ? $item['locator'] : [],
            ];
        }

        return $result;
    }

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
            $result[] = [
                'unit' => $unit,
                'entity_key' => $entityKey,
                'entity' => ['kind' => 'room', 'key' => $entityKey, 'area_m2' => $area['area_m2']],
                'assertion_type' => 'area',
                'value' => ['value' => $area['area_m2'], 'unit' => 'm2'],
                'source' => 'explicit_dimension',
                'confidence' => $this->confidence($element['confidence'] ?? $unit->confidence),
                'locator' => $this->roomEvidenceLocator($unit, $element, $index),
            ];
        }

        return $result;
    }

    private function roomEvidenceLocator(SessionBuildingModelUnitData $unit, array $element, int $index): array
    {
        $points = is_array($element['polygon'] ?? null) ? $element['polygon'] : [];
        $x = [];
        $y = [];
        foreach ($points as $point) {
            if (is_array($point) && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
                $x[] = (float) $point[0];
                $y[] = (float) $point[1];
            }
        }
        $key = is_string($element['key'] ?? null) ? $element['key'] : (string) $index;

        return [
            'document_id' => $unit->documentId,
            'unit_type' => $unit->type->value,
            'unit_index' => $unit->index,
            'page' => $unit->index,
            'region_key' => 'region:'.hash('sha256', $unit->unitId.'|'.$key),
            'element_key' => 'element:'.hash('sha256', $unit->unitId.'|'.$key),
            'bbox' => $x === [] ? null : [min($x), min($y), max($x), max($y)],
        ];
    }

    private function entityKey(string $kind, array $identity, SessionBuildingModelUnitData $unit, int $index): string
    {
        $join = $this->unambiguousJoin($identity);
        $identityKey = $join ?? $unit->documentId.'|'.$unit->sourceVersion.'|'.$unit->index.'|'.$index;

        return $this->stableKey($kind, $identityKey);
    }

    private function unambiguousJoin(array $identity): ?string
    {
        $room = $this->identityToken($identity['room_number'] ?? null);
        $floor = $this->identityToken($identity['floor'] ?? null);
        $marker = $this->identityToken($identity['section_marker'] ?? $identity['elevation'] ?? null);
        if ($room !== null && $floor !== null && $marker !== null) {
            return 'room:'.$room.'|floor:'.$floor.'|marker:'.$marker;
        }
        $axis = $this->identityToken($identity['axis'] ?? null);
        $entityType = $this->identityToken($identity['entity_type'] ?? null);
        $measurement = $this->identityToken($identity['measurement'] ?? null);
        $role = $this->identityToken($identity['role'] ?? null);
        $drawing = $this->identityToken(
            $identity['drawing_identity'] ?? $identity['section_identity'] ?? $identity['elevation_identity'] ?? null,
        );

        return $axis !== null && $entityType !== null && $measurement !== null && $role !== null && $drawing !== null
            ? 'drawing:'.$drawing.'|axis:'.$axis.'|entity:'.$entityType.'|measurement:'.$measurement.'|role:'.$role
            : null;
    }

    private function identityToken(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $token = mb_strtolower(trim((string) $value));

        return preg_match('/^[\pL\pN._:+-]{1,80}$/uD', $token) === 1 ? $token : null;
    }

    private function validAssertion(string $kind, string $type, array $value): bool
    {
        $positive = static fn (mixed $number): bool => (is_int($number) || is_float($number))
            && is_finite((float) $number) && $number > 0;

        return match ($type) {
            'area' => $kind === 'room' && $positive($value['value'] ?? null)
                && ($value['unit'] ?? null) === 'm2' && count($value) === 2,
            'dimension' => $kind === 'dimension' && $positive($value['value'] ?? null)
                && is_string($value['unit'] ?? null) && count($value) === 2,
            'room_purpose' => $kind === 'room' && is_string($value['value'] ?? null)
                && trim($value['value']) !== '' && count($value) === 1,
            'opening' => $kind === 'opening' && in_array($value['type'] ?? null, ['door', 'window', 'gate'], true)
                && $positive($value['width_m'] ?? null) && $positive($value['height_m'] ?? null) && count($value) === 3,
            default => false,
        };
    }

    private function confidence(mixed $value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.0;

        return is_finite($confidence) ? min(1.0, max(0.0, $confidence)) : 0.0;
    }

    private function stableKey(string ...$parts): string
    {
        return $parts[0].':'.substr(hash('sha256', implode('|', $parts)), 0, 48);
    }
}
