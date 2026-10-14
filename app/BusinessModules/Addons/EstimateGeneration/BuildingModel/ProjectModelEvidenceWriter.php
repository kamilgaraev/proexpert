<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactConfidence;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactReducer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ClaimSemanticMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomIdentityObservation;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\VisualObjectIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\VisualObjectScopePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\PhysicalMeasurementPublicationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\VerifiedNativeNumericSources;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelFactIdentityCollision;
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
    private const NATIVE_NAMED_ROOM_CONTRACT = 'native-named-room:v2';

    public function __construct(
        private ProjectModelRepository $models,
        private EvidenceRepository $evidence,
    ) {}

    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
    {
        return $this->evidence->transaction($organizationId, $sessionId, $callback);
    }

    /** @param list<ObservationClaim> $claims @param list<ArbitrationDecision> $decisions */
    public function writeArbitration(array $claims, array $decisions, int $documentId, int $pageNumber, ?VerifiedNativeNumericSources $numericSources = null): void
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
        $decisions = (new PhysicalMeasurementPublicationPolicy)->admit($claims, $decisions, $numericSources);
        $decisions = (new CanonicalFactReducer)->reduce($claims, $decisions);
        (new CanonicalFactReducer)->assertReduced($byId, $decisions);
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
        $nativeRoomIdentities = $this->nativeRoomIdentities($byId, $decisions, $documentId, $pageNumber, $numericSources);
        $this->evidence->transaction($scope->organizationId, $scope->sessionId, function () use ($byId, $decisions, $documentId, $pageNumber, $scope, $numericSources, $nativeRoomIdentities): void {
            $entities = [];
            $facts = [];
            $factsByClaimId = [];
            $domainEvidence = [];
            foreach ($decisions as $decision) {
                $claim = $byId[$decision->claimId] ?? throw new InvalidArgumentException('Arbitration claim is absent.');
                $this->assertScope($claim, $scope);
                $evidenceIds = [];
                $numericProofs = [];
                foreach ($decision->supportingClaimIds as $supportingClaimId) {
                    $supportingClaim = $byId[$supportingClaimId]
                        ?? throw new InvalidArgumentException('Supporting arbitration claim is absent.');
                    $this->assertScope($supportingClaim, $scope);
                    if (($decision->status === 'accepted' || $claim->factType === 'room_name') && ! (new ClaimSemanticMatcher)->equivalent($claim, $supportingClaim)) {
                        continue;
                    }
                    if ($supportingClaim->evidenceRef === null
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
                        $numericSources?->certifies($supportingClaim) === true ? EvidenceProducer::NativeNumericParser->value : EvidenceProducer::DrawingAnalyzer->value,
                        'sha256:'.hash('sha256', $numericSources?->certifies($supportingClaim) === true ? 'native-numeric-source:v1' : 'document-arbitration:v3'),
                    ));
                    $evidenceId = 'evidence:'.$node->id;
                    $evidenceIds[] = $evidenceId;
                    if ($numericSources?->certifies($supportingClaim) === true && ($supportingClaim->value['type'] ?? null) === 'number') {
                        $numericProofs[$evidenceId] = ['source_version' => $node->sourceVersion,
                            'parameter' => FactVocabulary::parameter($supportingClaim->factType),
                            'measurement' => FactVocabulary::measurementSignature((string) $supportingClaim->value['data'], $supportingClaim->unit),
                            'evidence_fingerprint' => $node->fingerprint];
                    }
                    $domainEvidence[$evidenceId] = $this->domainEvidence($node);
                }
                $projection = $this->projectModelEntity($claim)
                    ?? throw new InvalidArgumentException('Project model claim is not projectable.');
                $entityIdentity = (string) ($projection['identity_key'] ?? $claim->entityKey);
                $documentEngineering = $projection['type'] === 'equipment'
                    && ($projection['attributes']['properties']['document_context'] ?? false) === true;
                if ($documentEngineering) {
                    // Equal marks on different source sheets do not prove one physical asset.
                    $entityIdentity .= '|document:'.$documentId.'|page:'.$pageNumber;
                }
                $roomIdentityFact = (new RoomIdentityObservation)->name($claim) !== null;
                $nativeNamedRoom = isset($nativeRoomIdentities[$claim->entityKey.(new ClaimSemanticMatcher)->entityScope($claim)]);
                if ($nativeNamedRoom) {
                    // New parser metadata must not collide with immutable older room rows.
                    $entityIdentity .= '|'.self::NATIVE_NAMED_ROOM_CONTRACT;
                }
                if ($roomIdentityFact && ! ($claim->explicitEvidence
                    && ($claim->locator['unit_type'] ?? null) === DocumentUnitType::SpreadsheetSheet->value
                    && ($claim->locator['document_id'] ?? null) === $documentId
                    && ($claim->locator['page'] ?? null) === $pageNumber
                    && isset($nativeRoomIdentities[$claim->entityKey.(new ClaimSemanticMatcher)->entityScope($claim)]))) {
                    // A repeated room label is not proof that separate photographs show the same room.
                    $entityIdentity .= '|document:'.$documentId.'|page:'.$pageNumber;
                }
                $entityScope = (new ClaimSemanticMatcher)->entityScope($claim);
                $entityIdentity .= $entityScope;
                foreach (['floor_id' => 'floor_id', 'zone_id' => 'zone_id'] as $locatorField => $attribute) {
                    if (isset($projection['attributes']['semantic_type']) && is_string($claim->locator[$locatorField] ?? null)) {
                        $projection['attributes'][$attribute] = $claim->locator[$locatorField];
                    }
                }
                $visualInventoryFact = in_array($claim->factType, [
                    'sanitary_fixture', 'kitchen_fixture', 'furniture', 'unknown_fixture',
                ], true) || ($claim->factType === 'equipment' && $this->isPlanObservation($claim));
                $entityId = 'entity:'.hash('sha256', implode('|', $decision->status === 'accepted' || $visualInventoryFact || $roomIdentityFact
                    ? [$projection['type'], $entityIdentity]
                    : [
                        $projection['type'],
                        $claim->entityKey,
                        $claim->id,
                        $scope->sourceVersion,
                        (string) $documentId,
                        (string) $pageNumber,
                    ]));
                if ($decision->status === 'accepted' || $visualInventoryFact || $roomIdentityFact) {
                    // Entity rows and fact bindings belong to one source version. Reuse old keys only within that version.
                    $legacyCanonicalEntityId = $entityId;
                    $entityId = 'entity:'.hash('sha256', implode('|', [$projection['type'], $scope->sourceVersion, $entityIdentity]));
                    $legacyIdentities = [$claim->entityKey];
                    if (! $visualInventoryFact) {
                        $normalizedIdentity = (new VisualObjectIdentity)->normalizeEntityKey($entityIdentity);
                        $tokens = array_values(array_filter(explode('.', $normalizedIdentity)));
                        foreach (['.', '_', '-', ':'] as $separator) {
                            $legacyIdentities[] = implode($separator, $tokens);
                        }
                    }
                    $legacyEntityIds = array_map(
                        static fn (string $identity): string => 'entity:'.hash('sha256', implode('|', [
                            $projection['type'],
                            $identity,
                        ])),
                        array_values(array_unique($legacyIdentities)),
                    );
                    $entityId = $this->models->resolveEntityStableKey(
                        $scope->organizationId,
                        $scope->projectId,
                        $scope->sessionId,
                        $scope->sourceVersion,
                        $projection['type'],
                        $entityId,
                        array_values(array_unique([$legacyCanonicalEntityId,
                            ...($entityScope === '' && ! $roomIdentityFact && ! $documentEngineering ? $legacyEntityIds : [])])),
                    );
                }
                if (isset($nativeRoomIdentities[$claim->entityKey.$entityScope])) {
                    // Measurements and the candidate name share this parser entity.
                    // Keep its name confirmation metadata when a later quantity is written.
                    $projection['attributes'] = array_replace($entities[$entityId]->attributes ?? [], $projection['attributes']);
                }
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
                $projectedStatus = match (true) {
                    $roomIdentityFact => $evidenceIds !== [] && in_array($decision->status, ['accepted', 'candidate'], true) ? 'candidate' : 'unresolved',
                    $visualInventoryFact => in_array($decision->status, ['accepted', 'candidate'], true) ? 'candidate' : 'unresolved',
                    default => match ($decision->status) {
                        'accepted' => 'confirmed',
                        'candidate' => 'candidate',
                        default => 'unresolved',
                    },
                };
                $factIdentity = $visualInventoryFact
                    ? 'visual|'.$projection['type'].'|'.$entityIdentity
                    : (new ClaimSemanticMatcher)->key($claim).$entityScope;
                if ($nativeNamedRoom) {
                    $factIdentity .= '|'.self::NATIVE_NAMED_ROOM_CONTRACT;
                }
                $factId = 'fact:'.hash('sha256', implode('|', [
                    ($projectedStatus === 'candidate' && ! $visualInventoryFact ? $factIdentity.'|'.$entityId : $factIdentity)
                        .($numericProofs === [] ? '' : '|native-numeric-proof:v3:'.hash('sha256', DerivedQuantityIdentity::canonicalJson($numericProofs))),
                    $projectedStatus,
                    $scope->sourceVersion,
                    (string) $documentId,
                    (string) $pageNumber,
                ]));
                $fact = new Fact(
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
                    $projectedStatus === 'unresolved' ? 'unresolved' : (PhysicalMeasurementPublicationPolicy::requiresNumericProof($claim) && $numericSources?->certifies($claim) !== true ? 'ai_inference' : 'document'),
                    $projectedStatus,
                    $evidenceIds,
                    nativeNumericProofs: array_map(static fn (array $proof): array => [...$proof, 'entity_id' => $entityId], $numericProofs),
                );
                if (isset($facts[$factId])) {
                    if (! hash_equals($this->canonicalFactPayload($facts[$factId]), $this->canonicalFactPayload($fact))) {
                        throw new ProjectModelFactIdentityCollision('project_model_fact_exact_identity_collision');
                    }
                    $fact = $this->withEvidenceUnion($fact, $facts[$factId]);
                }
                $facts[$factId] = $fact;
                $factsByClaimId[$claim->id] = $fact;
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

        $this->writeArbitration(
            $claims,
            (new CanonicalFactReducer)->reduce($claims, $decisions),
            $documentId,
            $pageNumber,
        );
    }

    private function evidenceScalar(ObservationClaim $claim): string|int|float|bool
    {
        $roomIdentity = (new RoomIdentityObservation)->fingerprint($claim);
        if ($roomIdentity !== null) {
            return $roomIdentity;
        }
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
        $roomName = (new RoomIdentityObservation)->name($claim);
        if ($roomName !== null) {
            return $roomName;
        }
        $value = $claim->value['data'];
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }

        return $value;
    }

    private function canonicalFactPayload(Fact $fact): string
    {
        return DerivedQuantityIdentity::canonicalJson([
            'entity_id' => $fact->entityId,
            'type' => $fact->type,
            'value' => $fact->value,
            'unit' => $fact->unit,
            'confidence' => $fact->confidence,
            'origin' => $fact->origin,
            'status' => $fact->status,
            'version' => $fact->version,
            'supersedes_fact_id' => $fact->supersedesFactId,
        ]);
    }

    private function withEvidenceUnion(Fact $left, Fact $right): Fact
    {
        $evidenceIds = array_values(array_unique([...$left->evidenceIds, ...$right->evidenceIds]));
        sort($evidenceIds, SORT_STRING);

        return new Fact(
            $left->id,
            $left->organizationId,
            $left->projectId,
            $left->sessionId,
            $left->sourceVersion,
            $left->entityId,
            $left->type,
            $left->value,
            $left->unit,
            $left->confidence,
            $left->origin,
            $left->status,
            $evidenceIds,
            $left->version,
            $left->supersedesFactId,
            [...$right->nativeNumericProofs, ...$left->nativeNumericProofs],
        );
    }

    /** @return array<string,mixed> */
    private function evidenceLocator(ObservationClaim $claim, int $documentId, int $pageNumber, string $hash): array
    {
        $locator = [
            'document_id' => $documentId,
            'page' => $pageNumber,
            'element_key' => 'element:'.$hash,
        ];
        foreach (['unit_type', 'unit_index', 'sheet', 'region_key', 'bbox', 'source_key', 'native_reference'] as $field) {
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

    /** Native numeric proofs bind a name only to the exact parser entity on this sheet. */
    private function nativeRoomIdentities(array $claims, array $decisions, int $documentId, int $page, ?VerifiedNativeNumericSources $sources): array
    {
        $identities = [];
        foreach ($decisions as $decision) {
            $claim = $claims[$decision->claimId];
            if ($decision->status === 'accepted' && $sources?->certifies($claim) === true
                && ($claim->value['type'] ?? null) === 'number'
                && str_starts_with($claim->entityKey, 'room:native.'.$documentId.'.'.$page.'.')
                && ($claim->locator['unit_type'] ?? null) === DocumentUnitType::SpreadsheetSheet->value
                && ($claim->locator['document_id'] ?? null) === $documentId
                && ($claim->locator['page'] ?? null) === $page) {
                $identities[$claim->entityKey.(new ClaimSemanticMatcher)->entityScope($claim)] = true;
            }
        }

        $named = [];
        foreach ($claims as $claim) {
            if ((new RoomIdentityObservation)->name($claim) !== null && $claim->explicitEvidence
                && ($claim->locator['unit_type'] ?? null) === DocumentUnitType::SpreadsheetSheet->value
                && ($claim->locator['document_id'] ?? null) === $documentId
                && ($claim->locator['page'] ?? null) === $page) {
                $named[$claim->entityKey.(new ClaimSemanticMatcher)->entityScope($claim)] = true;
            }
        }

        return array_intersect_key($identities, $named);
    }

    /** @return array{type:string,attributes:array<string,mixed>,identity_key?:string,fact_type?:string}|null */
    private function projectModelEntity(ObservationClaim $claim): ?array
    {
        $document = FactVocabulary::documentObservation($claim->factType, $claim->entityKey, $claim->value, $claim->unit);
        if ($document !== null) {
            $kind = $document['semantic_type'];
            $physical = in_array($kind, Entity::TYPES, true) ? $kind
                : (FactVocabulary::entityType($claim->entityKey) !== null && ! in_array($kind, FactVocabulary::engineeringTypes(), true) ? 'quantity' : 'equipment');
            $sourceKey = is_string($claim->locator['source_entity_key'] ?? null) ? $claim->locator['source_entity_key'] : $claim->entityKey;
            $name = preg_replace('/^[a-z_]+[:._-]/', '', $sourceKey) ?? $sourceKey;
            $attributes = $physical === 'equipment'
                ? ['name' => $name, 'equipment_code' => 'document:'.substr(hash('sha256', $claim->entityKey), 0, 32),
                    'properties' => ['semantic_type' => $kind, 'document_context' => true, 'source_entity_key' => $sourceKey]]
                : ['semantic_type' => $kind, 'identity' => ['source_entity_key' => $sourceKey, 'label' => $name]];

            return ['type' => $physical,
                'identity_key' => (new VisualObjectIdentity)->normalizeEntityKey($claim->entityKey),
                'attributes' => $attributes];
        }
        $type = mb_strtolower($claim->factType);
        $value = $claim->value['data'];
        $roomName = (new RoomIdentityObservation)->name($claim);
        if ($roomName !== null) {
            return ['type' => 'room', 'identity_key' => (new VisualObjectIdentity)->normalizeEntityKey($claim->entityKey),
                'attributes' => ['semantic_type' => 'room', 'name' => $roomName,
                    'properties' => ['identity_only' => true, 'requires_confirmation' => true]]];
        }

        if (in_array($type, ['sanitary_fixture', 'kitchen_fixture', 'furniture', 'unknown_fixture'], true)
            && is_string($value) && trim($value) !== '') {
            $identity = new VisualObjectIdentity;
            $objectType = $identity->objectType($value, $claim->entityKey, $type);
            $identityKey = $identity->identity($type, $claim->entityKey, $value);
            $name = $identity->canonicalLabel($objectType, $value);

            return ['type' => 'equipment', 'identity_key' => $identityKey, 'fact_type' => $type, 'attributes' => [
                'equipment_code' => 'observed:'.substr(hash('sha256', $identityKey), 0, 32),
                'name' => $name,
                'properties' => [
                    'visual_inventory_category' => $type,
                    'estimate_scope' => 'requires_confirmation',
                    'canonical_identity' => $identityKey,
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
        $unit = FactVocabulary::unit($claim->unit);
        $allowedUnits = ['mm', 'cm', 'm', 'in', 'ft', 'mm2', 'cm2', 'm2', 'mm3', 'cm3', 'm3', 'count', 'kg', 't', 'h'];
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
        $semanticType = FactVocabulary::entityType($claim->entityKey);
        if ($semanticType !== null && in_array($semanticType, FactVocabulary::engineeringTypes(), true)) {
            $measurement = ($claim->value['type'] ?? null) === 'number'
                ? FactVocabulary::engineeringObservation($claim->entityKey, $type, $value, $claim->unit) : null;
            if ($measurement === null) {
                return null;
            }
            $sourceKey = is_string($claim->locator['source_entity_key'] ?? null) ? $claim->locator['source_entity_key'] : $claim->entityKey;

            return ['type' => 'equipment', 'fact_type' => $measurement['parameter'],
                'identity_key' => (new VisualObjectIdentity)->normalizeEntityKey($claim->entityKey),
                'attributes' => ['name' => preg_replace('/^[a-z_]+[:._-]/', '', $sourceKey) ?? $sourceKey,
                    'equipment_code' => 'document:'.substr(hash('sha256', $claim->entityKey), 0, 32),
                    'properties' => ['semantic_type' => $semanticType, 'document_context' => true, 'source_entity_key' => $sourceKey]]];
        }
        if ($semanticType !== null
            && FactVocabulary::supports($semanticType, $type)
            && $positiveNumeric && is_string($unit) && in_array($unit, $allowedUnits, true)) {
            return [
                'type' => in_array($semanticType, Entity::TYPES, true) ? $semanticType : 'quantity',
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
                'attributes' => $kind === 'quantity' ? ['semantic_type' => 'quantity'] : ['measurement_kind' => $type],
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
            is_string($node->locator['native_reference'] ?? null) ? $node->locator['native_reference'] : 'evidence-node:'.$node->id,
            $node->producerName,
            $node->sourceRef,
            fingerprint: $node->fingerprint,
        );
    }
}
