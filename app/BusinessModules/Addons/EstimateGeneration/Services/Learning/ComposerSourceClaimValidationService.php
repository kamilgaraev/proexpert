<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerBatchPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class ComposerSourceClaimValidationService
{
    public const PREDICATE = 'composer-document-correspondence:v1';

    public const PARAMETER_ORIGIN = 'work_parameter_component:v1';

    public const REQUIREMENT_ORIGIN = 'work_parameter_component:v2';

    public const MAX_CLAIMS = 8;

    public const MAX_RECEIPTS = EstimateComposerBatchPlanner::MAX_PARTS;

    public const MAX_PROJECTIONS = self::MAX_RECEIPTS * self::MAX_CLAIMS;

    public const MAX_CANDIDATE_JSON_BYTES = self::MAX_CLAIMS * 4096;

    public function __construct(private EvidenceRepository $evidence, private DocumentProcessingUnitStore $units,
        private ?\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource $cellSources = null,
        private ?\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization $reviewAuthorization = null,
        private ?\Closure $clarifications = null,
        private ?DocumentUnitContentReader $contentReader = null,
        private ?\Closure $models = null) {}

    public function parameterOperands(array $scope, ProjectModelSnapshot $snapshot, array $workContext, array $documents, array &$cache = []): array
    {
        if ($this->contentReader === null || $this->models === null || count($scope) !== 3 || count($snapshot->facts) > 10000
            || count($documents) > 8 || ! is_string($workContext['binding_hash'] ?? null)
            || ! is_string($workContext['binding']['entity_id'] ?? null)) {
            return [];
        }
        $models = ($this->models)();
        if (! $models instanceof ProjectModelRepository) {
            return [];
        }
        $key = hash('sha256', CanonicalPipelineJson::encode([$scope, $documents,
            array_map(static fn (Fact $fact): array => [$fact->id, $fact->version, $fact->sourceVersion, $fact->value], $snapshot->facts),
            array_map(static fn ($proof): array => [$proof->id, $proof->sourceVersion, $proof->fingerprint], $snapshot->evidence)]));
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $this->nativeParameterOperands($scope, $snapshot, $documents, $models, $cache);
        }
        $result = [];
        foreach ($cache[$key] as $descriptor) {
            $identity = ['origin' => 'work_parameter_operand:v1', 'scope' => $scope, 'work_context' => $workContext,
                'source' => $descriptor];
            $ref = hash('sha256', CanonicalPipelineJson::encode($identity));
            $result[$ref] = $descriptor;
        }

        return $result;
    }

    public static function requirementProposal(Entity $entity, Fact $fact): ?array
    {
        return self::documentRequirementProposal($entity, $fact, 'confirmed');
    }

    public static function sourceReviewProposal(Entity $entity, Fact $fact): ?array
    {
        return self::documentRequirementProposal($entity, $fact, 'candidate');
    }

    private static function documentRequirementProposal(Entity $entity, Fact $fact, string $status): ?array
    {
        $descriptor = $entity->attributes['properties']['document_semantic_proposal'] ?? null;
        if (! is_array($descriptor) || ! self::keys($descriptor, ['schema_version', 'payload', 'field_locators', 'source_pins', 'status', 'proposal_hash'])
            || $descriptor['schema_version'] !== 1 || $descriptor['status'] !== 'proposed'
            || $fact->entityId !== $entity->id || $fact->sourceVersion !== $entity->sourceVersion
            || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== [$entity->organizationId, $entity->projectId, $entity->sessionId]
            || $fact->origin !== 'document' || $fact->status !== $status || $fact->unit !== null
            || ! is_string($fact->value) || $fact->evidenceIds === [] || ! is_array($descriptor['payload'])
            || ! is_array($descriptor['field_locators']) || ! is_array($descriptor['source_pins'])
            || ! self::keys($descriptor['source_pins'], ['organization_id', 'project_id', 'session_id', 'document_id', 'page_number', 'processing_unit_id', 'source_version'])
            || [$descriptor['source_pins']['organization_id'], $descriptor['source_pins']['project_id'], $descriptor['source_pins']['session_id']]
                !== [$fact->organizationId, $fact->projectId, $fact->sessionId]
            || count(array_filter(array_intersect_key($descriptor['source_pins'], array_flip(['document_id', 'page_number', 'processing_unit_id'])),
                static fn (mixed $id): bool => is_int($id) && $id > 0)) !== 3
            || $descriptor['source_pins']['source_version'] !== $fact->sourceVersion) {
            return null;
        }
        try {
            $sourceKey = $entity->attributes['properties']['source_entity_key'] ?? $entity->attributes['identity']['source_entity_key'] ?? null;
            if (! is_string($sourceKey)) {
                return null;
            }
            $proposal = FactVocabulary::semanticProposal($descriptor['payload'], $fact->type, $sourceKey,
                ['type' => 'string', 'data' => $fact->value], null);
            if (($proposal['kind'] ?? null) !== 'source_requirement' || $proposal['dimension'] !== 'length'
                || $proposal['parameter']['data'] === null || $proposal['comparator']['data'] !== 'gte'
                || $proposal['threshold']['data'] === null || $proposal['unit']['data'] === null) {
                return null;
            }
            FactVocabulary::meetsMinimumLength($proposal['threshold']['data'], $proposal['unit']['data'], $proposal['threshold']['data'], $proposal['unit']['data']);
            $identity = array_intersect_key($descriptor, array_flip(['schema_version', 'payload', 'field_locators', 'source_pins']));
            if (CanonicalPipelineJson::encode($proposal) !== CanonicalPipelineJson::encode($descriptor['payload'])
                || ! is_string($descriptor['proposal_hash']) || ! hash_equals($descriptor['proposal_hash'], hash('sha256',
                    \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity::canonicalJson($identity)))) {
                return null;
            }
            $fields = FactVocabulary::semanticSourceFields($proposal);
            $expected = array_keys(array_filter($fields, static fn (array $field): bool => $field['evidenceRef'] !== null));
            $actual = array_keys($descriptor['field_locators']);
            sort($expected, SORT_STRING);
            sort($actual, SORT_STRING);
            if ($expected !== $actual || array_filter(['parameter', 'comparator', 'threshold', 'unit'],
                static fn (string $field): bool => ! isset($descriptor['field_locators'][$field])) !== []) {
                return null;
            }
        } catch (\InvalidArgumentException|\JsonException) {
            return null;
        }

        return $descriptor;
    }

    public static function sourceReviewReference(array $scope, array $association): string
    {
        return hash('sha256', CanonicalPipelineJson::encode(['document-source-review:v1', $scope, $association]));
    }

    public static function sourceReviewPublicationId(array $scope, string $ref, string $requestId): string
    {
        return 'fact:source-review:'.hash('sha256', CanonicalPipelineJson::encode([$scope, $ref, $requestId]));
    }

    public function sourceReviewSources(array $scope, ProjectModelSnapshot $snapshot, array $documents): array
    {
        if (count($scope) !== 3 || count($snapshot->facts) > self::MAX_CLAIMS || count($documents) !== 1
            || $this->contentReader === null) {
            return [];
        }
        $entities = array_column($snapshot->entities, null, 'id');
        $candidates = [];
        foreach ($snapshot->facts as $fact) {
            $entity = $entities[$fact->entityId] ?? null;
            $descriptor = $entity instanceof Entity ? self::sourceReviewProposal($entity, $fact) : null;
            if ($descriptor !== null) {
                $candidates[] = ['fact' => $fact, 'entity' => $entity, 'descriptor' => $descriptor];
            }
        }
        $index = ['candidates' => $candidates, 'proofs' => array_column($snapshot->evidence, null, 'id')];
        $result = [];
        foreach ($this->captureRequirementSources($scope, $index, array_column($documents, null, 'document_id')) as $association) {
            $result[self::sourceReviewReference($scope, $association)] = $association;
        }

        return $result;
    }

    public function documentSourceReviewCandidates(array $scope, int $documentId): ?array
    {
        $models = $this->models === null ? null : ($this->models)();
        if (! $models instanceof ProjectModelRepository || count($scope) !== 3) {
            return null;
        }
        $documentQuery = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query();
        $documentQuery->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2])
            ->whereNull('ignored_at')->whereIn('status', ['ready', 'needs_review']);
        $document = $documentQuery->find($documentId);
        if (! $document instanceof \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument) {
            return null;
        }
        $candidates = $models->sourceRequirementCandidates(...[...$scope, $documentId, (string) $document->getAttribute('source_version'), self::MAX_CLAIMS]);
        if ($candidates === null) {
            return null;
        }
        $facts = $candidates->facts;
        $proofs = [];
        foreach ($facts as $fact) {
            foreach ($fact->evidenceIds as $id) {
                if (isset($proofs[$id]) || preg_match('/\Aevidence:([1-9][0-9]*)\z/D', $id, $match) !== 1) continue;
                $node = $this->evidence->node(...[...$scope, (int) $match[1]]);
                if ($node === null || $node->invalidatedAt !== null || $node->sourceType !== EvidenceSourceType::Document
                    || $node->sourceRef !== 'document:'.$documentId || $node->sourceVersion !== $document->getAttribute('source_version')
                    || ! is_int($node->locator['page'] ?? null) || $node->locator['page'] < 1) continue;
                $proofs[$id] = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence($id,
                    $scope[0], $scope[1], $scope[2], $node->sourceVersion, $node->sourceRef, 'document', $node->locator['page'],
                    producerName: $node->producerName, sourceReference: $node->sourceRef, fingerprint: $node->fingerprint);
            }
        }
        $snapshot = new ProjectModelSnapshot($candidates->entities, $facts, array_values($proofs), []);
        $documents = [['document_id' => $documentId, 'source_version' => $document->getAttribute('source_version'),
            'checksum_sha256' => $document->getAttribute('checksum_sha256'), 'status' => $document->getAttribute('status')]];

        return ['snapshot' => $snapshot, 'sources' => $this->sourceReviewSources($scope, $snapshot, $documents)];
    }

    public function currentSourceReview(array $scope, string $publicationId, string $reviewFactId): ?array
    {
        if (! str_starts_with($publicationId, 'fact:source-review:') || $this->models === null) return null;
        $models = ($this->models)();
        if (! $models instanceof ProjectModelRepository) return null;
        $facts = $models->factsByIds(...[...$scope, [$publicationId, $reviewFactId]]);
        $byId = array_column($facts, null, 'id');
        if (! isset($byId[$publicationId], $byId[$reviewFactId])) return null;
        $current = array_column($models->currentFacts(...[...$scope, $byId[$reviewFactId]->entityId]), null, 'id');
        if (! isset($current[$reviewFactId])) return null;
        $proofs = [];
        foreach ($facts as $fact) {
            foreach ($fact->evidenceIds as $id) {
                if (isset($proofs[$id]) || preg_match('/\Aevidence:([1-9][0-9]*)\z/D', $id, $match) !== 1) continue;
                $node = $this->evidence->node(...[...$scope, (int) $match[1]]);
                if ($node === null || $node->invalidatedAt !== null) continue;
                $proofs[$id] = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence($id,
                    $scope[0], $scope[1], $scope[2], $node->sourceVersion, $node->sourceRef, $node->sourceType->value, $node->locator['page'] ?? null,
                    nativeReference: $node->locator['native_reference'] ?? null, producerName: $node->producerName,
                    sourceReference: $node->sourceRef, choiceHash: $node->sourceType === EvidenceSourceType::UserInput ? $node->value['fact_value'] ?? null : null,
                    fingerprint: $node->fingerprint);
            }
        }
        $snapshot = new ProjectModelSnapshot([], $facts, array_values($proofs), []);

        return $this->captureSourceReview($scope, $snapshot, $models->decisionsForSelectedFacts(...[...$scope, [$reviewFactId]]), $byId[$publicationId]);
    }

    public function captureSourceReview(array $scope, ProjectModelSnapshot $snapshot, array $decisions, Fact $source): ?array
    {
        if (! str_starts_with($source->id, 'fact:source-review:') || $this->reviewAuthorization === null || $this->models === null) {
            return null;
        }
        $reviews = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'evaluation_answer'
            && is_array($fact->value) && ($fact->value['kind'] ?? null) === 'document_source_review'
            && ($fact->value['publication']['fact_id'] ?? null) === $source->id));
        if (count($reviews) !== 1) return null;
        $review = $reviews[0];
        $models = ($this->models)();
        if (! $models instanceof ProjectModelRepository) return null;
        $currentReviews = array_values(array_filter($models->currentFacts(...[...$scope, $review->entityId]),
            static fn (Fact $fact): bool => $fact->type === 'evaluation_answer' && $fact->sourceVersion === $review->sourceVersion));
        if (count($currentReviews) !== 1 || $currentReviews[0]->id !== $review->id
            || $currentReviews[0]->version !== $review->version
            || CanonicalPipelineJson::encode($currentReviews[0]->value) !== CanonicalPipelineJson::encode($review->value)) return null;
        $value = $review->value;
        $decision = array_column($decisions, null, 'id')[$value['decision_id'] ?? ''] ?? null;
        if (! self::keys($value, ['schema_version', 'kind', 'review_kind', 'review_ref', 'decision', 'source', 'publication', 'decision_id', 'reason'])
            || $value['schema_version'] !== 1 || $value['review_kind'] !== 'source_requirement' || $value['decision'] !== 'accept'
            || ! is_array($value['source']) || ! is_array($value['publication'])
            || ! self::keys($value['publication'], ['fact_id', 'version']) || $value['publication']['version'] !== $source->version
            || $review->origin !== 'user_input' || $review->status !== 'confirmed'
            || [$review->organizationId, $review->projectId, $review->sessionId] !== $scope
            || ! $decision instanceof Decision || $decision->selectedFactId !== $review->id || $decision->actorType !== 'user'
            || $decision->targetType !== 'fact' || $decision->targetId !== ($review->supersedesFactId ?? $review->id)
            || $decision->reason !== $value['reason']
            || [$decision->organizationId, $decision->projectId, $decision->sessionId] !== $scope
            || $decision->sourceVersion !== $review->sourceVersion || $decision->version !== $review->version
            || $decision->evidenceIds !== $review->evidenceIds || $review->evidenceIds === []
            || array_intersect($review->evidenceIds, $source->evidenceIds) !== []) return null;
        $proofs = array_column($snapshot->evidence, null, 'id');
        $humanEvidence = [];
        foreach ($review->evidenceIds as $id) {
            $proof = $proofs[$id] ?? null;
            if ($proof === null || [$proof->organizationId, $proof->projectId, $proof->sessionId] !== $scope
                || $proof->sourceType !== 'user_input' || $proof->producerName !== 'user_input_normalizer'
                || $proof->sourceArtifactId !== 'input:'.$scope[2]
                || $proof->sourceVersion !== $review->sourceVersion || $proof->sourceReference !== 'input:'.$scope[2]
                || $proof->choiceHash !== hash('sha256', CanonicalPipelineJson::encode($value))
                || preg_match('/\Aevidence:([1-9][0-9]*)\z/D', $id, $match) !== 1) return null;
            $node = $this->evidence->node(...[...$scope, (int) $match[1]]);
            if ($node === null || $node->invalidatedAt !== null || $node->sourceType !== EvidenceSourceType::UserInput
                || $node->sourceRef !== $proof->sourceReference || $node->producerName !== 'user_input_normalizer'
                || $node->sourceVersion !== $review->sourceVersion || ($node->value['fact_value'] ?? null) !== $proof->choiceHash
                || $node->fingerprint !== $proof->fingerprint || $node->fingerprint !== $node->data->fingerprint()) return null;
            $humanEvidence[$id] = ['evidence_id' => $id, 'fingerprint' => $node->fingerprint,
                'invalidation_version' => $node->invalidationVersion];
        }
        $session = EstimateGenerationSession::query()->where('organization_id', $scope[0])->where('project_id', $scope[1])->find($scope[2]);
        $actor = \App\Models\User::query()->where('is_active', true)->find($decision->actorId);
        if ($session === null || $actor === null || (int) $actor->getAttribute('current_organization_id') !== $scope[0]) return null;
        try {
            $this->reviewAuthorization->authorize($actor, $session, 'estimate_generation.view');
            $this->reviewAuthorization->authorize($actor, $session, 'estimate_generation.review');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return null;
        }
        $capture = $this->documentSourceReviewCandidates($scope, (int) ($value['source']['document_pin']['document_id'] ?? 0));
        $association = $capture['sources'][$value['review_ref']] ?? null;
        $original = array_column($capture['snapshot']->facts ?? [], null, 'id')[$value['source']['source_fact_id'] ?? ''] ?? null;
        if ($association === null || ! $original instanceof Fact
            || CanonicalPipelineJson::encode($association) !== CanonicalPipelineJson::encode($value['source'])
            || $source->entityId !== $original->entityId || $source->sourceVersion !== $original->sourceVersion
            || $source->type !== $original->type || $source->value !== $original->value || $source->unit !== null
            || $source->origin !== 'document' || $source->status !== 'confirmed' || $source->nativeNumericProofs !== []
            || $source->evidenceIds !== $original->evidenceIds || $source->version <= $original->version
            || $source->supersedesFactId !== $original->id) return null;

        ksort($humanEvidence, SORT_STRING);

        return ['fact_id' => $review->id, 'version' => $review->version, 'decision_id' => $decision->id,
            'value_hash' => hash('sha256', CanonicalPipelineJson::encode($value)), 'evidence' => array_values($humanEvidence)];
    }

    public function requirementSources(array $scope, ProjectModelSnapshot $snapshot, array $workContext, array $documents, array &$cache = []): array
    {
        if (! array_is_list($scope) || count($scope) !== 3 || count(array_filter($scope, static fn (mixed $id): bool => is_int($id) && $id > 0)) !== 3
            || $this->contentReader === null || count($snapshot->facts) > 10000 || count($documents) > 8
            || ! is_array($workContext['binding'] ?? null) || ! is_string($workContext['binding_hash'] ?? null)
            || ! hash_equals($workContext['binding_hash'], hash('sha256', CanonicalPipelineJson::encode($workContext['binding'])))) {
            return [];
        }
        foreach ($documents as $document) {
            if (! is_array($document) || ! self::keys($document, ['document_id', 'source_version', 'checksum_sha256', 'status'])
                || ! is_int($document['document_id']) || $document['document_id'] < 1 || ! is_string($document['source_version'])
                || ! is_string($document['checksum_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $document['checksum_sha256']) !== 1
                || ! in_array($document['status'], ['ready', 'needs_review'], true)) {
                return [];
            }
        }
        $pins = array_column($documents, null, 'document_id');
        if (count($pins) !== count($documents)) {
            return [];
        }
        $snapshotKey = 'requirement-snapshot:'.spl_object_id($snapshot);
        if (! isset($cache[$snapshotKey]) || ($cache[$snapshotKey]['snapshot'] ?? null) !== $snapshot) {
            $entities = array_column($snapshot->entities, null, 'id');
            $candidates = [];
            foreach ($snapshot->facts as $fact) {
                $entity = $entities[$fact->entityId] ?? null;
                if ($entity instanceof Entity && ($descriptor = self::requirementProposal($entity, $fact)) !== null) {
                    $candidates[] = ['fact' => $fact, 'entity' => $entity, 'descriptor' => $descriptor];
                }
            }
            $cache[$snapshotKey] = ['snapshot' => $snapshot, 'entities' => $entities,
                'proofs' => array_column($snapshot->evidence, null, 'id'), 'candidates' => $candidates, 'captures' => []];
        }
        $index = &$cache[$snapshotKey];
        if (! isset($index['decisions']) && array_filter($snapshot->facts, static fn (Fact $fact): bool => str_starts_with($fact->id, 'fact:source-review:')) !== []) {
            $models = $this->models === null ? null : ($this->models)();
            $index['decisions'] = $models instanceof ProjectModelRepository
                ? $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]) : [];
        }
        $target = $index['entities'][$workContext['binding']['entity_id'] ?? ''] ?? null;
        if (! $target instanceof Entity
            || [$target->organizationId, $target->projectId, $target->sessionId] !== $scope) {
            return [];
        }
        ksort($pins, SORT_NUMERIC);
        $captureKey = hash('sha256', CanonicalPipelineJson::encode([$scope, $pins]));
        if (! array_key_exists($captureKey, $index['captures'])) {
            $index['captures'][$captureKey] = $this->captureRequirementSources($scope, $index, $pins);
        }
        $result = [];
        foreach ($index['captures'][$captureKey] as $association) {
            $result[self::requirementReference($scope, $workContext, $association)] = $association;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function captureRequirementSources(array $scope, array $index, array $pins): array
    {
        $proofs = $index['proofs'];
        $cache = [];
        $result = [];
        $examined = 0;
        foreach ($index['candidates'] as $candidate) {
            ['fact' => $fact, 'entity' => $entity, 'descriptor' => $descriptor] = $candidate;
            if ([$entity->organizationId, $entity->projectId, $entity->sessionId] !== $scope) {
                continue;
            }
            $source = $descriptor['source_pins'];
            $pin = $pins[$source['document_id'] ?? 0] ?? null;
            if ($pin === null || [$source['organization_id'] ?? null, $source['project_id'] ?? null, $source['session_id'] ?? null] !== $scope
                || ($source['source_version'] ?? null) !== $entity->sourceVersion || $pin['source_version'] !== $entity->sourceVersion
                || ! is_int($source['processing_unit_id'] ?? null) || ! is_int($source['page_number'] ?? null)) {
                continue;
            }
            if (++$examined > self::MAX_CLAIMS * self::MAX_CLAIMS) {
                return [];
            }
            $unitKey = 'unit:'.hash('sha256', CanonicalPipelineJson::encode([$scope, $pin, $source['processing_unit_id']]));
            if (! array_key_exists($unitKey, $cache)) {
                $cache[$unitKey] = EstimateGenerationProcessingUnit::query()->with('document', 'page')->where('organization_id', $scope[0])
                    ->where('project_id', $scope[1])->where('session_id', $scope[2])->where('document_id', $pin['document_id'])
                    ->where('source_version', $pin['source_version'])->where('status', 'completed')->find($source['processing_unit_id']);
            }
            $unit = $cache[$unitKey];
            if (! $unit instanceof EstimateGenerationProcessingUnit) {
                continue;
            }
            $document = $unit->getRelation('document');
            $page = $unit->getRelation('page');
            $unitLocator = $unit->getAttribute('locator');
            $unitType = $unit->getAttribute('unit_type');
            $outputVersion = $unit->getAttribute('output_version');
            if (! $document instanceof \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument
                || ! $page instanceof \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage
                || ! is_array($unitLocator) || ! $unitType instanceof DocumentUnitType
                || [(int) $document->getAttribute('organization_id'), (int) $document->getAttribute('project_id'), (int) $document->getAttribute('session_id')] !== $scope
                || [(int) $page->getAttribute('organization_id'), (int) $page->getAttribute('project_id'), (int) $page->getAttribute('session_id')] !== $scope
                || (int) $page->getAttribute('document_id') !== $pin['document_id']
                || (int) $page->getAttribute('processing_unit_id') !== (int) $unit->getKey() || $page->getAttribute('excluded_at') !== null
                || $document->getAttribute('ignored_at') !== null || ! in_array($document->getAttribute('status'), ['ready', 'needs_review'], true)
                || $document->getAttribute('source_version') !== $pin['source_version'] || $document->getAttribute('checksum_sha256') !== $pin['checksum_sha256']
                || ! hash_equals('sha256:'.$pin['checksum_sha256'], $pin['source_version'])
                || ! is_string($outputVersion) || $outputVersion === '' || strlen($outputVersion) > 80
                || $page->getAttribute('output_version') !== $outputVersion || (int) $unit->getAttribute('output_count') < 1
                || $page->getAttribute('source_version') !== $pin['source_version']
                || (int) $page->getAttribute('page_number') !== $source['page_number'] || $page->getAttribute('status') !== 'ready') {
                continue;
            }
            $locatorsValid = true;
            foreach ($descriptor['field_locators'] as $locator) {
                if (! is_array($locator) || [$locator['organization_id'] ?? null, $locator['project_id'] ?? null, $locator['session_id'] ?? null] !== $scope
                    || ($locator['document_id'] ?? null) !== $pin['document_id'] || ($locator['source_version'] ?? null) !== $pin['source_version']
                    || ($locator['processing_unit_id'] ?? null) !== (int) $unit->getKey() || ($locator['page_id'] ?? null) !== (int) $page->getKey()
                    || \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactSourceLocator::fromLocator($locator) === null) {
                    $locatorsValid = false;
                    break;
                }
            }
            if (! $locatorsValid) {
                continue;
            }
            $witnesses = [];
            foreach ($fact->evidenceIds as $id) {
                $proof = $proofs[$id] ?? null;
                if ($proof === null || preg_match('/\Aevidence:([1-9][0-9]*)\z/D', $id, $match) !== 1) {
                    continue;
                }
                $nodeKey = 'evidence:'.$id;
                if (! array_key_exists($nodeKey, $cache)) {
                    $cache[$nodeKey] = $this->evidence->node(...[...$scope, (int) $match[1]]);
                }
                $node = $cache[$nodeKey];
                if ($node !== null && $node->invalidatedAt === null && $node->type === EvidenceType::SourceFact
                    && $node->sourceType === EvidenceSourceType::Document && $node->producerName === 'drawing_analyzer'
                    && [$proof->organizationId, $proof->projectId, $proof->sessionId] === $scope
                    && $proof->sourceType === 'document' && $proof->sourceVersion === $pin['source_version']
                    && $proof->sourceArtifactId === 'document:'.$pin['document_id'] && $proof->sourceReference === $proof->sourceArtifactId
                    && $node->sourceRef === 'document:'.$pin['document_id'] && $node->sourceVersion === $pin['source_version']
                    && $proof->fingerprint === $node->fingerprint && $node->fingerprint === $node->data->fingerprint()
                    && ($node->locator['document_id'] ?? null) === $pin['document_id'] && ($node->locator['page'] ?? null) === $source['page_number']) {
                    $witnesses[$id] = ['evidence_id' => $id, 'evidence_fingerprint' => $node->fingerprint,
                        'invalidation_version' => $node->invalidationVersion];
                }
            }
            if ($witnesses === []) {
                continue;
            }
            ksort($witnesses, SORT_STRING);
            $outputIdentity = ['processing_unit_id' => (int) $unit->getKey(), 'page_id' => (int) $page->getKey(), 'version' => $outputVersion];
            $artifactKey = 'requirement-artifact:'.hash('sha256', CanonicalPipelineJson::encode([$scope, $outputIdentity, $pin, $unitLocator]));
            if (! array_key_exists($artifactKey, $cache)) {
                try {
                    $context = new DocumentUnitExecutionContext((int) $unit->getKey(), ...[...$scope, $pin['document_id'], $unitType,
                        (int) $unit->getAttribute('unit_index'), $pin['source_version'], $unitLocator, (string) $document->getAttribute('storage_path'),
                        (string) $document->getAttribute('mime_type'), (string) $document->getAttribute('filename'), '', (int) $unit->getAttribute('attempt_count'), 0, '', (int) $page->getKey()]);
                    $body = $this->contentReader->read($context);
                    $cache[$artifactKey] = ($unitLocator['artifact_bytes'] ?? null) === strlen($body)
                        && ($unitLocator['artifact_sha256'] ?? null) === 'sha256:'.hash('sha256', $body)
                        ? ['sha256' => 'sha256:'.hash('sha256', $body), 'bytes' => strlen($body)] : null;
                } catch (\RuntimeException|\InvalidArgumentException) {
                    $cache[$artifactKey] = null;
                }
            }
            if ($cache[$artifactKey] === null) {
                continue;
            }
            $humanReview = null;
            if (str_starts_with($fact->id, 'fact:source-review:')) {
                $humanReview = $this->captureSourceReview($scope, $index['snapshot'], $index['decisions'] ?? [], $fact);
                if ($humanReview === null) continue;
            }
            $association = ['source_fact_id' => $fact->id, 'source_fact_version' => $fact->version,
                'source_value_hash' => hash('sha256', json_encode($fact->value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), 'source_entity_id' => $entity->id,
                'descriptor' => $descriptor, 'document_pin' => $pin, 'output_identity' => $outputIdentity,
                'evidence' => array_values($witnesses), 'artifact' => $cache[$artifactKey]];
            if ($humanReview !== null) $association['human_review'] = $humanReview;
            $sourceKey = hash('sha256', CanonicalPipelineJson::encode($association));
            if (isset($result[$sourceKey])) {
                return [];
            }
            $result[$sourceKey] = $association;
            if (count($result) > self::MAX_CLAIMS) {
                return [];
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    public static function requirementReference(array $scope, array $workContext, array $association): string
    {
        return hash('sha256', CanonicalPipelineJson::encode(['origin' => self::REQUIREMENT_ORIGIN, 'scope' => $scope,
            'work_context' => array_intersect_key($workContext, array_flip(['schema_version', 'binding', 'binding_hash', 'policy', 'dependencies', 'question'])),
            'source' => $association]));
    }

    private function nativeParameterOperands(array $scope, ProjectModelSnapshot $snapshot, array $documents,
        ProjectModelRepository $models, array &$cache): array
    {
        $documentIds = array_column($documents, 'document_id');
        if ($documentIds === []) {
            return [];
        }
        $proofs = array_column($snapshot->evidence, null, 'id');
        $facts = array_values(array_filter($snapshot->facts, static function (Fact $fact) use ($proofs, $documentIds): bool {
            if ($fact->origin !== 'document' || $fact->status !== 'confirmed' || $fact->nativeNumericProofs === []
                || FactVocabulary::factor('length', $fact->unit) === null) {
                return false;
            }
            foreach ($fact->evidenceIds as $id) {
                $proof = $proofs[$id] ?? null;
                if ($proof !== null && preg_match('/\Adocument:([1-9][0-9]*)\z/D', $proof->sourceReference ?? '', $match) === 1
                    && in_array((int) $match[1], $documentIds, true)) {
                    return true;
                }
            }

            return false;
        }));
        if (count($facts) > 64) {
            return [];
        }
        $session = EstimateGenerationSession::query()->where('organization_id', $scope[0])->where('project_id', $scope[1])->find($scope[2]);
        if ($session === null) {
            return [];
        }
        $result = [];
        foreach ($facts as $fact) {
            $locators = [];
            foreach ($fact->evidenceIds as $id) {
                if (preg_match('/\Aevidence:([1-9][0-9]*)\z/D', $id, $match) !== 1) {
                    continue;
                }
                $node = $this->evidence->node(...[...$scope, (int) $match[1]]);
                if ($node !== null && $node->invalidatedAt === null && $node->producerName === 'native_numeric_parser'
                    && in_array($node->locator['document_id'] ?? null, $documentIds, true)
                    && ($node->locator['unit_type'] ?? null) === 'spreadsheet_sheet') {
                    $locator = ['document_id' => $node->locator['document_id'], 'index' => $node->locator['unit_index'] ?? null,
                        'source_version' => $node->sourceVersion];
                    $locators[CanonicalPipelineJson::encode($locator)] = $locator;
                }
            }
            if (count($locators) !== 1) {
                continue;
            }
            $locator = array_values($locators)[0];
            $units = EstimateGenerationProcessingUnit::query()->with('document', 'page')->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])->where('session_id', $scope[2])->where('document_id', $locator['document_id'])
                ->where('unit_type', 'spreadsheet_sheet')->where('unit_index', $locator['index'])->where('source_version', $locator['source_version'])
                ->where('status', 'completed')->limit(2)->get();
            if ($units->count() !== 1) {
                continue;
            }
            $unit = $units->first();
            $document = $unit->document;
            $pin = array_values(array_filter($documents, static fn (array $pin): bool => $pin['document_id'] === (int) $unit->document_id));
            if ($document === null || count($pin) !== 1 || $pin[0]['source_version'] !== $unit->source_version
                || $pin[0]['checksum_sha256'] !== $document->checksum_sha256) {
                continue;
            }
            $context = new DocumentUnitExecutionContext((int) $unit->id, ...[...$scope, (int) $document->id,
                DocumentUnitType::SpreadsheetSheet, (int) $unit->unit_index, $unit->source_version, $unit->locator,
                $document->storage_path, $document->mime_type, $document->filename, '', (int) $unit->attempt_count,
                (int) $session->state_version, $session->status->value, $unit->page === null ? null : (int) $unit->page->id]);
            $artifactKey = 'artifact:'.hash('sha256', CanonicalPipelineJson::encode([$scope, $unit->id, $unit->source_version, $unit->locator]));
            try {
                if (! array_key_exists($artifactKey, $cache)) {
                    $cache[$artifactKey] = $this->contentReader->read($context);
                }
                if (! is_string($cache[$artifactKey])) {
                    continue;
                }
                $descriptor = (new NativeNumericFactFactory)->readLengthOperand($context, $cache[$artifactKey], $fact->id, $models, $this->evidence);
            } catch (\RuntimeException|\JsonException|\InvalidArgumentException) {
                $cache[$artifactKey] = null;
                continue;
            }
            if ($descriptor !== null) {
                $result[] = $descriptor;
                if (count($result) > self::MAX_CLAIMS) {
                    return [];
                }
            }
        }

        return $result;
    }

    public function capture(array $scope, ProjectModelSnapshot $snapshot, array $decisions, array $input,
        array $candidate, array $anchors, array &$checkedAnswers = [], ?string $contractVersion = null, ?string $localEstimateKey = null): ?array
    {
        if (! array_is_list($scope) || count($scope) !== 3
            || count(array_filter($scope, static fn ($id): bool => is_int($id) && $id > 0)) !== 3 || ! self::isCandidate($candidate)) {
            return null;
        }
        $facts = array_column($snapshot->facts, null, 'id');
        $answer = $facts[$candidate['source_answer_fact_id']] ?? null;
        $entity = array_column($snapshot->entities, null, 'id')[$candidate['entity_id']] ?? null;
        if (! $answer instanceof Fact || ! $entity instanceof Entity
            || [$answer->organizationId, $answer->projectId, $answer->sessionId] !== $scope
            || [$entity->organizationId, $entity->projectId, $entity->sessionId] !== $scope
            || GeometryInventory::type($entity) !== 'equipment') {
            return null;
        }
        $work = null;
        if (is_array($answer->value) && ($answer->value['composer_context']['kind'] ?? null) === 'work_technology') {
            if (! in_array($contractVersion, EstimateComposerInput::WORK_SOURCE_CLAIM_CONTRACTS, true)) {
                return null;
            }
            $work = $this->currentWorkSourceClaimContexts($scope, $snapshot, $decisions, $input, $localEstimateKey)[$answer->id] ?? null;
            if ($work === null || $work['context']['binding']['entity_id'] !== $candidate['entity_id']) {
                return null;
            }
            $context = ['work_sections' => ['equipment'], 'target_entity_id' => $work['context']['binding']['entity_id']];
        } else {
            $context = ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, $decisions, $input, $checkedAnswers);
        }
        if ($context === null || ! in_array('equipment', $context['work_sections'], true)
            || (isset($context['target_entity_id']) && $context['target_entity_id'] !== $candidate['entity_id'])) {
            return null;
        }
        $decision = array_column($decisions, null, 'id')[$answer->value['decision_id']] ?? null;
        if (! $decision instanceof Decision) {
            return null;
        }
        $sources = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->entityId === $entity->id
            && $fact->type === 'equipment_specification' && $fact->origin === 'document' && $fact->status === 'confirmed'
            && $fact->unit === null));
        if (count($sources) !== 1 || $sources[0]->id !== $candidate['document_fact_ids'][0]
            || $sources[0]->id === $answer->id || array_intersect($answer->evidenceIds, $sources[0]->evidenceIds) !== []) {
            return null;
        }
        $source = $sources[0];
        if (isset($entity->attributes['properties']['document_cell_review_anchor'])
            && $this->captureCellReview($scope, $snapshot, $decisions, $source) === null) {
            return null;
        }
        if ([$source->organizationId, $source->projectId, $source->sessionId] !== $scope
            || $source->sourceVersion !== $entity->sourceVersion || $source->nativeNumericProofs !== []) {
            return null;
        }
        $binding = $this->documentBinding($scope, $snapshot, $source, $candidate, $anchors);
        if ($binding === null) {
            return null;
        }
        $pins = $work === null ? [] : array_values(array_filter($work['documents'],
            static fn (array $pin): bool => in_array($pin['document_id'], $work['answer']['document_ids'], true)));
        if ($work !== null && array_filter($pins, static fn (array $pin): bool => $pin['document_id'] === $binding['literal_witness']['document_id']
            && $pin['source_version'] === $source->sourceVersion) === []) {
            return null;
        }
        $identity = ['predicate' => self::PREDICATE, 'scope' => $scope, 'candidate' => $candidate,
            'answer' => ['fact_id' => $answer->id, 'version' => $answer->version, 'source_version' => $answer->sourceVersion,
                'value_hash' => hash('sha256', CanonicalPipelineJson::encode($answer->value)),
                'decision_id' => $decision->id, 'decision_version' => $decision->version,
                'decision_hash' => hash('sha256', CanonicalPipelineJson::encode(get_object_vars($decision)))],
            'source' => ['fact_id' => $source->id, 'version' => $source->version, 'source_version' => $source->sourceVersion,
                'entity_id' => $source->entityId, ...$binding],
            ...($work === null ? [] : ['work_context' => $work['context'], 'document_pins' => $pins, 'contract_version' => $contractVersion])];

        return ['source_claim_key' => hash('sha256', CanonicalPipelineJson::encode($identity)), 'identity' => $identity,
            'interpretation_status' => 'proposed', 'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved',
            ...($work === null ? [] : ['numeric_parameters_confirmed' => false])];
    }

    public function currentWorkSourceClaimContexts(array $scope, ProjectModelSnapshot $snapshot, array $decisions, array $input,
        ?string $localEstimateKey = null): array
    {
        if ($this->clarifications === null || count($scope) !== 3) {
            return [];
        }
        $policy = ResolveCurrentEstimateClarification::composerPolicy($input);
        if ($policy === null) {
            return [];
        }
        $catalog = ($this->clarifications)();
        if (! $catalog instanceof \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog) {
            return [];
        }
        $entries = $catalog->currentWorkContexts(...[...$scope, $snapshot, $decisions, $localEstimateKey]);

        return array_filter(EstimateComposerInput::workSourceClaimContexts($entries), static fn (array $entry): bool =>
            CanonicalPipelineJson::encode($entry['context']['policy'] ?? null) === CanonicalPipelineJson::encode($policy));
    }

    public function parameterComponents(array $scope, ProjectModelSnapshot $snapshot, array $decisions, array $input,
        ?string $localEstimateKey = null, array &$cache = []): array
    {
        $policy = ResolveCurrentEstimateClarification::composerPolicy($input);
        if ($this->clarifications === null || count($scope) !== 3 || $policy === null) {
            return [];
        }
        $catalog = ($this->clarifications)();
        if (! $catalog instanceof \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog) {
            return [];
        }
        $entries = $duplicates = [];
        foreach ($catalog->currentWorkContexts(...[...$scope, $snapshot, $decisions, $localEstimateKey]) as $entry) {
            $answer = $entry['answer'] ?? null;
            if (($entry['status'] ?? null) !== 'answered' || ! is_array($answer)
                || (! is_array($answer['parameter'] ?? null) && ! is_array($answer['requirement'] ?? null))
                || ($answer['response'] ?? null) !== 'other' || ($answer['trust'] ?? null) !== 'user_assumption'
                || ($answer['engineering_applicability'] ?? null) !== 'unresolved' || ($answer['numeric_parameters_confirmed'] ?? null) !== false
                || ! is_string($answer['fact_id'] ?? null) || ! is_array($answer['document_ids'] ?? null)
                || $answer['document_ids'] === [] || count($answer['document_ids']) > self::MAX_CLAIMS
                || CanonicalPipelineJson::encode($entry['context']['policy'] ?? null) !== CanonicalPipelineJson::encode($policy)) {
                continue;
            }
            if (isset($entries[$answer['fact_id']])) {
                $duplicates[$answer['fact_id']] = true;
            }
            $entries[$answer['fact_id']] = $entry;
        }
        $entries = array_diff_key($entries, $duplicates);
        if (count($entries) > self::MAX_CLAIMS) {
            return [];
        }
        $facts = array_column($snapshot->facts, null, 'id');
        $result = [];
        foreach ($entries as $answerId => $entry) {
            $answer = $facts[$answerId] ?? null;
            $parameter = $answer?->value['parameter'] ?? null;
            if (! is_array($parameter) || ! is_array($parameter['association'] ?? null) || ! is_array($parameter['binding'] ?? null)) {
                continue;
            }
            try {
                $typed = ResolveCurrentEstimateClarification::workParameterInput($parameter['input'] ?? []);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($typed === null) {
                continue;
            }
            $pins = array_values(array_filter($entry['documents'], static fn (array $pin): bool => in_array($pin['document_id'], $entry['answer']['document_ids'], true)));
            if (($typed['schema_version'] ?? null) === 2) {
                if (! is_int($answer->value['composer_context']['state_version'] ?? null) || $answer->value['composer_context']['state_version'] < 0) {
                    continue;
                }
                $fresh = $this->requirementSources($scope, $snapshot, $entry['context'], $pins, $cache)[$typed['source_requirement_ref']] ?? null;
                $entity = array_column($snapshot->entities, null, 'id')[$entry['context']['binding']['entity_id']] ?? null;
                if ($fresh === null || $entity === null || CanonicalPipelineJson::encode($fresh) !== CanonicalPipelineJson::encode($parameter['association'])
                    || CanonicalPipelineJson::encode($parameter['binding']) !== CanonicalPipelineJson::encode([
                        'entity_id' => $entity->id, 'entity_source_version' => $entity->sourceVersion,
                        'work_key' => $entry['context']['binding']['work_key'], 'work_binding_hash' => $entry['context']['binding_hash']])) {
                    continue;
                }
                $identity = ['origin' => self::REQUIREMENT_ORIGIN, 'scope' => $scope, 'work_context' => $entry['context'],
                    'answer' => ['fact_id' => $answer->id, 'version' => $answer->version, 'decision_id' => $entry['answer']['decision_id'],
                        'value_hash' => hash('sha256', CanonicalPipelineJson::encode($answer->value))],
                    'parameter' => $typed, 'source' => $fresh, 'document_pins' => $pins];
                $details = self::requirementDetails($fresh);
                unset($details['source'], $details['status']);
                $result[$answerId] = ['source_claim_key' => hash('sha256', CanonicalPipelineJson::encode($identity)), 'identity' => $identity,
                    'answer_state_version' => $answer->value['composer_context']['state_version'],
                    'component' => ['schema_version' => 2, 'condition' => 'minimum_length', 'selected_ref' => $typed['source_requirement_ref'],
                        ...$details, 'confirmation_level' => 'user_confirmed', 'interpretation_status' => 'user_assumption',
                        'observed' => null, 'comparison' => null]];
                continue;
            }
            $fresh = $this->parameterOperands($scope, $snapshot, $entry['context'], $pins, $cache)[$typed['source_operand_ref']] ?? null;
            $entity = array_column($snapshot->entities, null, 'id')[$entry['context']['binding']['entity_id']] ?? null;
            if ($fresh === null || $entity === null || CanonicalPipelineJson::encode($fresh) !== CanonicalPipelineJson::encode($parameter['association'])
                || CanonicalPipelineJson::encode($parameter['binding']) !== CanonicalPipelineJson::encode([
                    'entity_id' => $entity->id, 'entity_source_version' => $entity->sourceVersion,
                    'work_key' => $entry['context']['binding']['work_key'], 'work_binding_hash' => $entry['context']['binding_hash']])) {
                continue;
            }
            $definition = ['evidence_id' => $fresh['evidence_id'], 'source_version' => $fresh['document_source_version'],
                'evidence_fingerprint' => $fresh['evidence_fingerprint']];
            $operands = [];
            foreach (['observed_value', 'minimum_length_requirement'] as $role) {
                $fact = $facts[$parameter[$role === 'observed_value' ? 'observed_fact_id' : 'requirement_fact_id'] ?? ''] ?? null;
                $attributes = FactVocabulary::engineeringParameterAttributes(...[...$scope, $parameter['binding'], $typed['name'], $role, $definition]);
                if (! $fact instanceof Fact || $fact->status !== 'unresolved' || $fact->origin !== 'user_assumption' || $fact->nativeNumericProofs !== []
                    || CanonicalPipelineJson::encode($fact->semanticAttributes) !== CanonicalPipelineJson::encode($attributes)
                    || $fact->entityId !== ($role === 'observed_value' ? $parameter['binding']['entity_id'] : $fresh['raw_entity_id'])
                    || $fact->value !== ($role === 'observed_value' ? $typed['value'] : $fresh['value'])
                    || $fact->unit !== ($role === 'observed_value' ? $typed['unit'] : $fresh['unit'])
                    || array_diff($answer->evidenceIds, $fact->evidenceIds) !== []
                    || array_diff($fact->evidenceIds, [...$answer->evidenceIds, $fresh['evidence_id']]) !== []
                    || ! in_array($fresh['evidence_id'], $fact->evidenceIds, true)) {
                    break;
                }
                $operands[$role] = ['fact_id' => $fact->id, 'version' => $fact->version,
                    'fingerprint' => hash('sha256', CanonicalPipelineJson::encode(get_object_vars($fact)))];
            }
            if (count($operands) !== 2) {
                continue;
            }
            $identity = ['origin' => self::PARAMETER_ORIGIN, 'scope' => $scope, 'work_context' => $entry['context'],
                'answer' => ['fact_id' => $answer->id, 'version' => $answer->version, 'decision_id' => $entry['answer']['decision_id'],
                    'value_hash' => hash('sha256', CanonicalPipelineJson::encode($answer->value))],
                'parameter' => $typed, 'source' => $fresh, 'operands' => $operands, 'document_pins' => $pins];
            $result[$answerId] = ['source_claim_key' => hash('sha256', CanonicalPipelineJson::encode($identity)), 'identity' => $identity,
                'answer_state_version' => $answer->value['composer_context']['state_version'],
                'component' => ['condition' => 'minimum_length', 'value' => $typed['value'], 'unit' => $typed['unit'],
                    'minimum' => $fresh['value'], 'minimum_unit' => $fresh['unit'],
                    'meets_minimum' => FactVocabulary::meetsMinimumLength($typed['value'], $typed['unit'], $fresh['value'], $fresh['unit']),
                    'interpretation_status' => 'user_assumption', 'source_correspondence' => 'passed',
                    'engineering_applicability' => 'unresolved', 'numeric_parameters_confirmed' => false, 'numeric_inputs_verified' => false]];
        }

        return $result;
    }

    public static function requirementDetails(array $source): array
    {
        $descriptor = $source['descriptor'];
        $proposal = $descriptor['payload'];
        $locator = $descriptor['field_locators']['threshold'];
        $factSource = \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactSourceLocator::fromLocator($locator);

        return ['status' => 'current', 'parameter' => $proposal['parameter']['data'], 'comparator' => 'gte',
            'threshold' => $proposal['threshold']['data'], 'unit' => $proposal['unit']['data'],
            'subject' => $proposal['appliesTo']['subject']['data'],
            'document_edition' => $proposal['documentEdition']['data'], 'clause' => $proposal['clause']['data'],
            'conditions' => $proposal['conditions'] === null ? null : array_column($proposal['conditions'], 'data'),
            'equipment_identity' => $proposal['appliesTo']['equipmentIdentity'] === null ? null
                : array_map(static fn (array $value): ?string => $value['data'], $proposal['appliesTo']['equipmentIdentity']),
            'source_correspondence' => 'unresolved', 'source_authority' => 'unresolved', 'edition_confirmation' => 'unresolved',
            'engineering_applicability' => 'unresolved', 'numeric_parameters_confirmed' => false, 'numeric_inputs_verified' => false,
            'source' => ['reference' => $source['evidence'][0]['evidence_id'], 'document_id' => $descriptor['source_pins']['document_id'],
                'source_version' => $descriptor['source_pins']['source_version'], 'page_number' => $descriptor['source_pins']['page_number'],
                'region' => $factSource?->region(), 'native_reference' => $factSource?->value['kind'] === 'native' ? $factSource->value['value'] : null,
                'coordinate_space' => $factSource?->region() !== null ? 'normalized_source_v1' : null]];
    }

    public function captureCellReview(array $scope, ProjectModelSnapshot $snapshot, array $decisions, Fact $source): ?array
    {
        if ($this->cellSources === null || $this->reviewAuthorization === null
            || [$source->organizationId, $source->projectId, $source->sessionId] !== $scope
            || $source->type !== 'equipment_specification' || $source->origin !== 'document' || $source->status !== 'confirmed'
            || $source->unit !== null || ! is_string($source->value) || $source->nativeNumericProofs !== []) {
            return null;
        }
        $entity = array_column($snapshot->entities, null, 'id')[$source->entityId] ?? null;
        $anchor = $entity?->attributes['properties']['document_cell_review_anchor'] ?? null;
        $reviews = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->entityId === $anchor
            && $fact->type === 'evaluation_answer' && is_array($fact->value) && ($fact->value['kind'] ?? null) === 'document_cell_review'));
        if (count($reviews) !== 1 || ! $entity instanceof Entity || GeometryInventory::type($entity) !== 'equipment'
            || $entity->sourceVersion !== $source->sourceVersion) {
            return null;
        }
        $review = $reviews[0];
        $value = $review->value;
        $decision = array_column($decisions, null, 'id')[$value['decision_id'] ?? ''] ?? null;
        if ([$review->organizationId, $review->projectId, $review->sessionId] !== $scope || $review->origin !== 'user_input'
            || $review->status !== 'confirmed' || ($value['decision'] ?? null) !== 'accept'
            || ($value['identity_confirmation'] ?? null) !== 'new_document_local_equipment'
            || ! $decision instanceof Decision || $decision->selectedFactId !== $review->id || $decision->actorType !== 'user'
            || [$decision->organizationId, $decision->projectId, $decision->sessionId] !== $scope
            || $decision->sourceVersion !== $review->sourceVersion || $decision->version !== $review->version
            || $review->evidenceIds === [] || $decision->evidenceIds !== $review->evidenceIds
            || array_intersect($review->evidenceIds, $source->evidenceIds) !== []) {
            return null;
        }
        $proofs = array_column($snapshot->evidence, null, 'id');
        foreach ($review->evidenceIds as $id) {
            $proof = $proofs[$id] ?? null;
            if ($proof === null || $proof->sourceType !== 'user_input' || $proof->producerName !== 'user_input_normalizer'
                || $proof->sourceVersion !== $review->sourceVersion
                || $proof->choiceHash !== hash('sha256', CanonicalPipelineJson::encode($value))
                || preg_match('/\Aevidence:([1-9][0-9]*)\z/D', $id, $match) !== 1) {
                return null;
            }
            $node = $this->evidence->node(...[...$scope, (int) $match[1]]);
            if ($node === null || $node->invalidatedAt !== null || $node->sourceType !== EvidenceSourceType::UserInput
                || $node->producerName !== 'user_input_normalizer' || $node->sourceVersion !== $review->sourceVersion
                || ($node->value['fact_value'] ?? null) !== $proof->choiceHash || $node->fingerprint !== $proof->fingerprint) {
                return null;
            }
        }
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])->find($scope[2]);
        $actor = \App\Models\User::query()->where('is_active', true)->find($decision->actorId);
        if ($session === null || $actor === null || (int) $actor->current_organization_id !== $scope[0]) {
            return null;
        }
        try {
            $this->reviewAuthorization->authorize($actor, $session, 'estimate_generation.review');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return null;
        }
        $frozen = $value['binding'] ?? [];
        $row = $this->cellSources->resolve($session, (int) ($frozen['document_id'] ?? 0), (int) ($frozen['unit_id'] ?? 0), (string) ($frozen['row_id'] ?? ''));
        if ($row === null || CanonicalPipelineJson::encode(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource::wire([$row])[0])
                !== CanonicalPipelineJson::encode($frozen)
            || \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource::proposal($value['proposal'] ?? null, [$row]) === null) {
            return null;
        }
        $cell = array_column($row['cells'], null, 'cell_id')[$value['proposal']['value_cell_id']] ?? null;
        if ($cell === null || $cell['raw_value'] !== $source->value) {
            return null;
        }
        $candidate = ['source_review_decision_id' => $decision->id, 'entity_id' => $source->entityId,
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => $source->value], 'unit' => null,
            'document_fact_ids' => [$source->id]];
        $binding = $this->documentBinding($scope, $snapshot, $source, $candidate, GeometryInventory::currentDocumentVersions($snapshot, false));
        if ($binding === null || $binding['literal_witness']['entity_key'] !== ($value['entity_key'] ?? null)
            || $binding['literal_witness']['cell'] !== $cell['address']) {
            return null;
        }
        $identity = ['predicate' => 'document-cell-review-correspondence:v1', 'scope' => $scope, 'candidate' => $candidate,
            'review' => ['fact_id' => $review->id, 'version' => $review->version, 'decision_id' => $decision->id,
                'value_hash' => hash('sha256', CanonicalPipelineJson::encode($value))],
            'source' => ['fact_id' => $source->id, 'version' => $source->version, 'source_version' => $source->sourceVersion,
                'entity_id' => $source->entityId, ...$binding]];

        return ['candidate' => $candidate, 'source_claim_key' => hash('sha256', CanonicalPipelineJson::encode($identity)), 'identity' => $identity,
            'human_interpretation' => 'confirmed_by_user', 'interpretation_status' => 'proposed',
            'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved'];
    }

    private function documentBinding(array $scope, ProjectModelSnapshot $snapshot, Fact $source, array $candidate, array $anchors): ?array
    {
        $proofs = array_column($snapshot->evidence, null, 'id');
        $bindings = [];
        foreach ($source->evidenceIds as $id) {
            $anchor = $anchors[$source->entityId]['evidence'][$id] ?? null;
            $snapshotProof = $proofs[$id] ?? null;
            if (! is_array($anchor) || $snapshotProof === null || preg_match('/^evidence:([1-9][0-9]*)$/D', $id, $match) !== 1) {
                continue;
            }
            $node = $this->evidence->node(...[...$scope, (int) $match[1]]);
            $witness = $node?->value['native_literal_source'] ?? null;
            if ($node === null || $node->invalidatedAt !== null || $node->type !== EvidenceType::SourceFact
                || $node->sourceType !== EvidenceSourceType::Document || $node->producerName !== 'drawing_analyzer'
                || $node->sourceVersion !== $source->sourceVersion || $snapshotProof->fingerprint !== $node->fingerprint
                || ! hash_equals($node->fingerprint, $node->data->fingerprint()) || ! is_array($witness)
                || ! self::keys($witness, ['schema_version', 'producer', 'artifact_sha256', 'document_id', 'unit_id', 'cell', 'entity_key',
                    'fact_type', 'value_hash', 'organization_id', 'project_id', 'session_id', 'source_version'])
                || ($witness['schema_version'] ?? null) !== 1 || ($witness['producer'] ?? null) !== 'native_spreadsheet_literal:v1'
                || [$witness['organization_id'], $witness['project_id'], $witness['session_id']] !== $scope
                || $witness['source_version'] !== $source->sourceVersion || $witness['fact_type'] !== $source->type
                || $witness['document_id'] !== $anchor['document_id'] || $node->sourceRef !== 'document:'.$witness['document_id']
                || ($node->locator['document_id'] ?? null) !== $witness['document_id']
                || ($node->locator['unit_type'] ?? null) !== DocumentUnitType::SpreadsheetSheet->value
                || ($node->locator['native_reference'] ?? null) !== $snapshotProof->nativeReference
                || ($anchor['native_reference'] ?? null) !== $snapshotProof->nativeReference
                || ! is_int($witness['unit_id']) || $witness['unit_id'] < 1 || ! is_string($witness['cell'])
                || preg_match('/^[A-Z]{1,3}[1-9][0-9]*$/D', $witness['cell']) !== 1
                || ! is_string($witness['entity_key'])
                || FactVocabulary::documentObservation($source->type, $witness['entity_key'], $candidate['value'], null) === null
                || ! self::literalValueCorresponds($candidate, $source->value, $witness)
                || ! str_starts_with((string) ($node->locator['native_reference'] ?? ''), 'xlsx:sheet:')
                || ! str_ends_with((string) ($node->locator['native_reference'] ?? ''), '!'.$witness['cell'])) {
                continue;
            }
            $unit = $this->units->find($witness['unit_id']);
            if ($unit === null || $unit->status !== DocumentProcessingUnitStatus::Completed
                || [$unit->organizationId, $unit->projectId, $unit->sessionId] !== $scope || $unit->documentId !== $witness['document_id']
                || $unit->unit->type !== DocumentUnitType::SpreadsheetSheet || $unit->unit->sourceVersion !== $source->sourceVersion
                || ($node->locator['unit_index'] ?? null) !== $unit->unit->index
                || ($unit->unit->locator['artifact_sha256'] ?? null) !== $witness['artifact_sha256']) {
                continue;
            }
            $bindings[] = ['evidence_id' => $id, 'evidence_fingerprint' => $node->fingerprint, 'literal_witness' => $witness];
        }

        return count($bindings) === 1 ? $bindings[0] : null;
    }

    public static function isCandidate(mixed $candidate): bool
    {
        if (! is_array($candidate) || array_is_list($candidate)
            || ! self::keys($candidate, ['source_answer_fact_id', 'entity_id', 'fact_type', 'value', 'unit', 'document_fact_ids'])
            || ! self::identifier($candidate['source_answer_fact_id']) || ! self::identifier($candidate['entity_id'])
            || $candidate['fact_type'] !== 'equipment_specification' || $candidate['unit'] !== null
            || ! is_array($candidate['value']) || ! self::keys($candidate['value'], ['type', 'data'])
            || FactVocabulary::documentObservation('equipment_specification', 'equipment:source_claim', $candidate['value'], null) === null
            || ! is_array($candidate['document_fact_ids']) || ! array_is_list($candidate['document_fact_ids'])
            || count($candidate['document_fact_ids']) !== 1 || ! self::identifier($candidate['document_fact_ids'][0])
            || $candidate['document_fact_ids'][0] === $candidate['source_answer_fact_id']) {
            return false;
        }

        return true;
    }

    public static function foldReceipts(array $receipts): array
    {
        $answers = [];
        $count = 0;
        foreach ($receipts as $receipt) {
            if (! is_int($receipt['run_id'] ?? null) || $receipt['run_id'] < 1
                || ! is_array($receipt['candidates'] ?? null) || ! array_is_list($receipt['candidates'])
                || count($receipt['candidates']) > self::MAX_CLAIMS) {
                return [];
            }
            $seen = [];
            foreach ($receipt['candidates'] as $candidate) {
                if (++$count > self::MAX_PROJECTIONS || ! self::isCandidate($candidate)) {
                    return [];
                }
                $answerId = $candidate['source_answer_fact_id'];
                if (isset($seen[$answerId])) {
                    return [];
                }
                $seen[$answerId] = true;
                $key = hash('sha256', CanonicalPipelineJson::encode($candidate));
                if (! isset($answers[$answerId][$key]) || $receipt['run_id'] < $answers[$answerId][$key]['run_id']) {
                    $answers[$answerId][$key] = ['run_id' => $receipt['run_id'], 'candidate' => $candidate];
                }
            }
        }
        if (count($answers) > self::MAX_CLAIMS) {
            return [];
        }
        ksort($answers, SORT_STRING);
        $result = [];
        foreach ($answers as $candidates) {
            if (count($candidates) === 1) {
                $result[] = array_values($candidates)[0];
            }
        }

        return $result;
    }

    public static function literalValueCorresponds(array $candidate, mixed $originalValue, array $witness): bool
    {
        return (self::isCandidate($candidate) || self::isCellReviewCandidate($candidate)) && is_string($originalValue)
            && $candidate['value']['data'] === $originalValue
            && is_string($witness['value_hash'] ?? null)
            && hash_equals($witness['value_hash'], hash('sha256', json_encode($originalValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    public static function isCellReviewCandidate(mixed $candidate): bool
    {
        return is_array($candidate) && self::keys($candidate, ['source_review_decision_id', 'entity_id', 'fact_type', 'value', 'unit', 'document_fact_ids'])
            && self::identifier($candidate['source_review_decision_id']) && self::identifier($candidate['entity_id'])
            && $candidate['fact_type'] === 'equipment_specification' && $candidate['unit'] === null
            && is_array($candidate['value']) && self::keys($candidate['value'], ['type', 'data'])
            && FactVocabulary::documentObservation('equipment_specification', 'equipment:review', $candidate['value'], null) !== null
            && is_array($candidate['document_fact_ids']) && array_is_list($candidate['document_fact_ids'])
            && count($candidate['document_fact_ids']) === 1 && self::identifier($candidate['document_fact_ids'][0]);
    }

    private static function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/iD', $value) === 1;
    }
}
