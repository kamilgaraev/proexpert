<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerBatchPlanner;
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

final readonly class ComposerSourceClaimValidationService
{
    public const PREDICATE = 'composer-document-correspondence:v1';

    public const MAX_CLAIMS = 8;

    public const MAX_RECEIPTS = EstimateComposerBatchPlanner::MAX_PARTS;

    public const MAX_PROJECTIONS = self::MAX_RECEIPTS * self::MAX_CLAIMS;

    public const MAX_CANDIDATE_JSON_BYTES = self::MAX_CLAIMS * 4096;

    public function __construct(private EvidenceRepository $evidence, private DocumentProcessingUnitStore $units,
        private ?\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource $cellSources = null,
        private ?\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization $reviewAuthorization = null) {}

    public function capture(array $scope, ProjectModelSnapshot $snapshot, array $decisions, array $input,
        array $candidate, array $anchors, array &$checkedAnswers = []): ?array
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
        $context = ResolveCurrentEstimateClarification::composerAnswerContext($answer, $snapshot, $decisions, $input, $checkedAnswers);
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
        $identity = ['predicate' => self::PREDICATE, 'scope' => $scope, 'candidate' => $candidate,
            'answer' => ['fact_id' => $answer->id, 'version' => $answer->version, 'source_version' => $answer->sourceVersion,
                'value_hash' => hash('sha256', CanonicalPipelineJson::encode($answer->value)),
                'decision_id' => $decision->id, 'decision_version' => $decision->version,
                'decision_hash' => hash('sha256', CanonicalPipelineJson::encode(get_object_vars($decision)))],
            'source' => ['fact_id' => $source->id, 'version' => $source->version, 'source_version' => $source->sourceVersion,
                'entity_id' => $source->entityId, ...$binding]];

        return ['source_claim_key' => hash('sha256', CanonicalPipelineJson::encode($identity)), 'identity' => $identity,
            'interpretation_status' => 'proposed', 'source_correspondence' => 'passed', 'engineering_applicability' => 'unresolved'];
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
        $binding = $this->documentBinding($scope, $snapshot, $source, $candidate, GeometryInventory::currentDocumentVersions($snapshot));
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
