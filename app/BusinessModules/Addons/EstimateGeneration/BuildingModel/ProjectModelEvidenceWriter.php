<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactConfidence;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactReducer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ClaimSemanticMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\VisualObjectIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\VisualObjectScopePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;
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

    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
    {
        return $this->evidence->transaction($organizationId, $sessionId, $callback);
    }

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
        foreach ($decisions as $decision) {
            $claim = $byId[$decision->claimId] ?? throw new InvalidArgumentException('Arbitration claim is absent.');
            $this->assertScope($claim, $scope);
        }
        $conditionalVisualInventory = $this->hasConditionalVisualInventoryNote($claims);
        $decisions = (new CanonicalFactReducer)->reduce($byId, $decisions);
        $decisions = array_values(array_filter(
            $decisions,
            fn (ArbitrationDecision $decision): bool => $this->shouldProjectClaim(
                $byId[$decision->claimId],
                $conditionalVisualInventory,
            ),
        ));
        if ($decisions === []) {
            return;
        }
        $this->evidence->transaction($scope->organizationId, $scope->sessionId, function () use ($byId, $decisions, $documentId, $pageNumber, $scope, $conditionalVisualInventory): void {
            $entities = [];
            $facts = [];
            $factsByClaimId = [];
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
                        || ! $this->shouldProjectClaim($supportingClaim, $conditionalVisualInventory)
                        || ! in_array($supportingClaim->evidenceRef, $decision->evidenceRefs, true)) {
                        continue;
                    }
                    $hash = hash('sha256', implode('|', [
                        $supportingClaim->id,
                        $supportingClaim->sourceVersion,
                        (string) $documentId,
                        (string) $pageNumber,
                        $supportingClaim->evidenceRef,
                    ]));
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
                        $supportingClaim->explicitEvidence
                            ? $supportingClaim->confidence
                            : 0.0,
                        EvidenceProducer::DrawingAnalyzer->value,
                        'sha256:'.hash('sha256', 'document-arbitration:v3'),
                    ));
                    $evidenceId = 'evidence:'.$node->id;
                    $evidenceIds[] = $evidenceId;
                    $domainEvidence[$evidenceId] = $this->domainEvidence($node);
                }
                $projection = $this->projectModelEntity($claim)
                    ?? throw new InvalidArgumentException('Project model claim is not projectable.');
                $entityIdentity = (string) ($projection['identity_key'] ?? $claim->entityKey);
                $entityId = 'entity:'.hash('sha256', implode('|', $decision->status === 'accepted'
                    ? [$projection['type'], $entityIdentity]
                    : [
                        $projection['type'],
                        $claim->entityKey,
                        $claim->id,
                        $scope->sourceVersion,
                        (string) $documentId,
                        (string) $pageNumber,
                    ]));
                $entities[$entityId] = new Entity(
                    $entityId,
                    $scope->organizationId,
                    $scope->projectId,
                    $scope->sessionId,
                    $scope->sourceVersion,
                    $projection['type'],
                    $entityId,
                    $projection['attributes'],
                );
                $factId = 'fact:'.hash('sha256', implode('|', [
                    (new ClaimSemanticMatcher)->key($claim),
                    $decision->status,
                    $scope->sourceVersion,
                    (string) $documentId,
                    (string) $pageNumber,
                ]));
                $visualInventoryFact = in_array($claim->factType, [
                    'sanitary_fixture', 'kitchen_fixture', 'furniture', 'unknown_fixture',
                ], true) || ($claim->factType === 'equipment' && $this->isPlanObservation($claim));
                $facts[$factId] = new Fact(
                    $factId,
                    $scope->organizationId,
                    $scope->projectId,
                    $scope->sessionId,
                    $scope->sourceVersion,
                    $entityId,
                    mb_substr((string) ($projection['fact_type'] ?? $claim->factType), 0, 120),
                    $this->projectModelFactValue($claim),
                    $claim->unit,
                    (new CanonicalFactConfidence)->forDecision($decision, $byId),
                    $decision->status === 'unresolved' ? 'unresolved' : 'document',
                    $visualInventoryFact ? 'candidate' : match ($decision->status) {
                        'accepted' => 'confirmed',
                        'candidate' => 'candidate',
                        'unresolved' => 'unresolved',
                    },
                    $evidenceIds,
                );
                $factsByClaimId[$claim->id] = $facts[$factId];
            }
            $conflicts = $this->unresolvedConflicts($byId, $decisions, $factsByClaimId);
            $this->models->saveSourceModel(
                array_values($entities),
                array_values($facts),
                array_values($domainEvidence),
                $conflicts,
            );
        });
    }

    /**
     * @param  array<string, ObservationClaim>  $claims
     * @param  list<ArbitrationDecision>  $decisions
     * @param  array<string, Fact>  $factsByClaimId
     * @return list<Conflict>
     */
    private function unresolvedConflicts(array $claims, array $decisions, array $factsByClaimId): array
    {
        $groups = [];
        foreach ($decisions as $decision) {
            $claim = $claims[$decision->claimId] ?? null;
            $fact = $factsByClaimId[$decision->claimId] ?? null;
            if (! $claim instanceof ObservationClaim || ! $fact instanceof Fact) {
                continue;
            }
            $key = mb_strtolower($claim->entityKey)."\0".mb_strtolower($fact->type);
            $groups[$key]['facts'][] = $fact;
            $groups[$key]['unresolved'] = ($groups[$key]['unresolved'] ?? false)
                || $decision->status === 'unresolved';
        }

        $conflicts = [];
        foreach ($groups as $group) {
            $facts = $group['facts'] ?? [];
            if (($group['unresolved'] ?? false) !== true || count($facts) < 2) {
                continue;
            }
            $values = array_unique(array_map(
                static fn (Fact $fact): string => hash('sha256', json_encode(
                    [$fact->value, $fact->unit],
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                )),
                $facts,
            ));
            if (count($values) < 2) {
                continue;
            }
            $factIds = array_map(static fn (Fact $fact): string => $fact->id, $facts);
            sort($factIds, SORT_STRING);
            $conflictId = 'conflict:document-arbitration:'.substr(hash(
                'sha256',
                implode('|', [...$factIds, $facts[0]->sourceVersion, 'document-arbitration-conflict:v1']),
            ), 0, 48);
            $conflicts[] = Conflict::between($conflictId, $facts, 'document_arbitration_unresolved');
        }

        return $conflicts;
    }

    /** @param list<ObservationClaim> $claims */
    public function writeIndependentObservations(array $claims, int $documentId, int $pageNumber): void
    {
        if ($claims === []) {
            return;
        }
        $decisions = array_map(static fn (ObservationClaim $claim): ArbitrationDecision => new ArbitrationDecision(
            claimId: $claim->id,
            status: 'candidate',
            supportingClaimIds: [$claim->id],
            evidenceRefs: $claim->evidenceRef === null ? [] : [$claim->evidenceRef],
            reasonCode: 'independent_observation_preserved',
            canonicalClaim: null,
        ), $claims);

        $this->writeArbitration($claims, $decisions, $documentId, $pageNumber);
    }

    private function evidenceScalar(ObservationClaim $claim): string|int|float|bool
    {
        $value = $claim->value['data'];
        if (($claim->value['type'] ?? null) === 'number' && CanonicalSourceDecimal::isValid($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return 'material:'.hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function projectModelFactValue(ObservationClaim $claim): mixed
    {
        $value = $claim->value['data'];
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }

        return $value;
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

    /** @return array{type:string,attributes:array<string,mixed>,identity_key?:string,fact_type?:string}|null */
    private function projectModelEntity(ObservationClaim $claim): ?array
    {
        $type = mb_strtolower($claim->factType);
        $value = $claim->value['data'];

        if (in_array($type, ['sanitary_fixture', 'kitchen_fixture', 'furniture', 'unknown_fixture'], true)
            && is_string($value) && trim($value) !== '') {
            $identity = new VisualObjectIdentity;
            $objectType = $identity->objectType($value, $claim->entityKey);
            $identityKey = $identity->identity($type, $claim->entityKey, $value);
            $name = $identity->canonicalLabel($objectType, $value);

            return ['type' => 'equipment', 'identity_key' => $identityKey, 'fact_type' => $type, 'attributes' => [
                'equipment_code' => 'observed:'.substr(hash('sha256', $identityKey), 0, 32),
                'name' => $name,
                'properties' => [
                    'visual_inventory_category' => $type,
                    'estimate_scope' => 'requires_confirmation',
                    'room_key' => $identity->roomKey($claim->entityKey),
                    'object_type' => $objectType,
                ],
            ]];
        }

        if (in_array($type, ['material', 'equipment'], true)
            && is_string($value) && trim($value) !== '') {
            $name = mb_substr(trim($value), 0, 240);
            $codeField = $type.'_code';
            $visualEquipment = $type === 'equipment' && $this->isPlanObservation($claim);

            return ['type' => $type, 'attributes' => [
                $codeField => 'observed:'.substr(hash('sha256', $name), 0, 32),
                'name' => $name,
                'properties' => $visualEquipment ? [
                    'visual_inventory_category' => 'equipment',
                    'estimate_scope' => 'contextual_only',
                    'room_key' => preg_match('/\A(room[.:_-][a-z0-9_-]+)/i', $claim->entityKey, $matches) === 1
                        ? $matches[1]
                        : null,
                ] : [],
            ]];
        }

        $canonicalNumeric = ($claim->value['type'] ?? null) === 'number'
            && CanonicalSourceDecimal::isValid($value);
        $nonNegativeNumeric = $canonicalNumeric && CanonicalSourceDecimal::isNonNegative($value);
        $positiveNumeric = $canonicalNumeric && CanonicalSourceDecimal::isPositive($value);
        $unit = $claim->unit;
        $allowedUnits = ['m', 'm2', 'm3', 'pcs', 'kg', 't', 'h'];
        if ($type === 'area' && $positiveNumeric && $unit === 'm2'
            && $claim->entityKey === 'building_area_total') {
            return [
                'type' => 'room',
                'identity_key' => 'building.area.total',
                'attributes' => [
                    'semantic_type' => 'room',
                    'document_role' => 'building_floor',
                ],
            ];
        }
        if ($type === 'area' && $positiveNumeric && $unit === 'm2'
            && preg_match('/^room[:._-]/D', mb_strtolower($claim->entityKey)) === 1) {
            return [
                'type' => 'room',
                'identity_key' => (new VisualObjectIdentity)->normalizeEntityKey($claim->entityKey),
                'attributes' => ['semantic_type' => 'room'],
            ];
        }
        $semanticType = $this->semanticEntityType($claim->entityKey);
        $semanticFactTypes = [
            'room' => ['length', 'width'],
            'wall' => ['wall_length', 'wall_height'],
            'site' => ['area', 'depth'],
            'roof' => ['plan_area', 'slope_rise', 'slope_run'],
            'roof_facet' => ['plan_area', 'slope_rise', 'slope_run'],
            'opening' => ['opening_width', 'opening_height'],
            'roof_opening' => ['area'],
        ];
        if ($semanticType !== null
            && in_array($type, $semanticFactTypes[$semanticType], true)
            && $positiveNumeric && is_string($unit) && in_array($unit, $allowedUnits, true)) {
            return [
                'type' => $semanticType,
                'identity_key' => (new VisualObjectIdentity)->normalizeEntityKey($claim->entityKey),
                'attributes' => ['semantic_type' => $semanticType],
            ];
        }
        $kind = $type === 'quantity'
            ? 'quantity'
            : (in_array($type, ['area', 'dimension_chain', 'elevation', 'level'], true) ? 'dimension' : null);
        if ($type === 'elevation' && $canonicalNumeric && is_string($unit) && in_array($unit, $allowedUnits, true)) {
            return ['type' => 'dimension', 'attributes' => [
                'measurement_kind' => 'elevation',
            ], 'identity_key' => (new VisualObjectIdentity)->normalizeEntityKey($claim->entityKey)];
        }
        if ($type === 'level' && $nonNegativeNumeric && is_string($unit) && in_array($unit, $allowedUnits, true)) {
            return ['type' => 'dimension', 'attributes' => [
                'measurement_kind' => 'level',
            ], 'identity_key' => (new VisualObjectIdentity)->normalizeEntityKey($claim->entityKey)];
        }
        if ($kind !== null && $positiveNumeric && is_string($unit) && in_array($unit, $allowedUnits, true)) {
            return [
                'type' => $kind,
                'identity_key' => (new VisualObjectIdentity)->normalizeEntityKey($claim->entityKey),
                'attributes' => ['measurement_kind' => $type],
            ];
        }

        return null;
    }

    /** @param list<ObservationClaim> $claims */
    private function hasConditionalVisualInventoryNote(array $claims): bool
    {
        $policy = new VisualObjectScopePolicy;
        foreach ($claims as $claim) {
            if ($policy->isConditionalNote($claim->factType, $claim->value['data'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function shouldProjectClaim(ObservationClaim $claim, bool $conditional): bool
    {
        $value = $claim->value['data'] ?? null;
        if (is_string($value) && in_array($claim->factType, [
            'sanitary_fixture', 'kitchen_fixture', 'furniture', 'unknown_fixture',
        ], true)) {
            return (new VisualObjectScopePolicy)->scope($claim->factType, $value, $conditional)
                === 'requires_confirmation';
        }
        if ($claim->factType === 'equipment' && $this->isPlanObservation($claim) && is_string($value)) {
            return false;
        }

        return $this->projectModelEntity($claim) !== null;
    }

    private function isPlanObservation(ObservationClaim $claim): bool
    {
        return in_array($claim->locator['document_role'] ?? null, ['floor_plan', 'plan'], true);
    }

    private function semanticEntityType(string $entityKey): ?string
    {
        foreach (['roof_opening', 'roof_facet', 'opening', 'room', 'wall', 'site', 'roof'] as $type) {
            if (preg_match('/^'.preg_quote($type, '/').'[:._-]/D', mb_strtolower($entityKey)) === 1) {
                return $type;
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
}
