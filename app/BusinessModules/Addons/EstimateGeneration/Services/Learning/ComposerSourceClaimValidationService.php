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
            if (($entry['status'] ?? null) !== 'answered' || ! is_array($answer) || ! is_array($answer['parameter'] ?? null)
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
