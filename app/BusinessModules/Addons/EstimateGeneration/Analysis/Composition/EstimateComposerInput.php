<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use InvalidArgumentException;

final readonly class EstimateComposerInput
{
    public const MAX_SOURCE_CLAIMS = 8;

    public const WORK_SOURCE_CLAIM_CONTRACTS = ['estimate-composer:answers:v5', 'estimate-composer:legacy-answers:v5',
        'estimate-composer:answer-cells:v4', 'estimate-composer:legacy-answer-cells:v4'];

    public array $claimAnswerFactIds;

    public const WORK_SECTIONS = ['structures', 'finishing', 'roofing', 'earthworks', 'water_supply', 'sewerage', 'heating',
        'ventilation', 'electricity', 'automation', 'technological_pipelines', 'equipment'];
    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $derivedQuantities
     * @param  list<array<string, mixed>>  $decisions
     * @param  list<array{candidate_id:string,work_key:string,name:string,unit:?string,quantity:?string,quantity_formula:?string,source_fact_ids:list<string>,technology_package_candidate:?string}>  $candidates
     * @param  list<array<string, mixed>>  $missingDocuments
     */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $snapshotToken,
        public array $facts,
        public array $derivedQuantities,
        public array $decisions,
        public array $candidates,
        public array $missingDocuments,
        public string $contractVersion,
        public ?AiSessionWireScope $wireScope = null,
        public ?string $batchKey = null,
        public ?array $contextManifest = null,
        public array $allowedWorkSections = [],
        ?array $claimAnswerFactIds = null,
        public array $sourceClaimReferences = [],
        public array $nativeCellContext = [],
        public array $directQuantityBindings = [],
        public array $workTechnologyContext = [],
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/D', $contractVersion) !== 1
            || ! array_is_list($facts) || count($facts) > 10000
            || ! array_is_list($derivedQuantities) || count($derivedQuantities) > 2000
            || ! array_is_list($decisions) || count($decisions) > 2000
            || ! array_is_list($candidates) || count($candidates) > 10000
            || ($batchKey !== null && preg_match('/^[a-f0-9]{64}$/D', $batchKey) !== 1)
            || ($contextManifest !== null && (! is_string($contextManifest['parent_input_fingerprint'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $contextManifest['parent_input_fingerprint']) !== 1))
            || ($facts === [] && $derivedQuantities === [] && $candidates === [])
            || ! array_is_list($missingDocuments) || count($missingDocuments) > 200) {
            throw new InvalidArgumentException('estimate_composer_input_invalid');
        }
        if (! array_is_list($workTechnologyContext) || count($workTechnologyContext) > 200
            || ($workTechnologyContext !== [] && ! $this->acceptsTechnologyQuestions())) {
            throw new InvalidArgumentException('estimate_composer_entity_context_too_large');
        }
        foreach ($facts as $fact) {
            if (! is_array($fact) || ! self::identifier($fact['id'] ?? null)) {
                throw new InvalidArgumentException('estimate_composer_fact_invalid');
            }
        }
        if (! array_is_list($allowedWorkSections) || count($allowedWorkSections) > 12
            || count(array_filter($allowedWorkSections, 'is_string')) !== count($allowedWorkSections)
            || count(array_unique($allowedWorkSections)) !== count($allowedWorkSections)
            || array_diff($allowedWorkSections, self::WORK_SECTIONS) !== []) {
            throw new InvalidArgumentException('estimate_composer_work_sections_invalid');
        }
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)
                || array_keys($candidate) !== [
                    'candidate_id', 'work_key', 'name', 'unit', 'quantity', 'quantity_formula',
                    'source_fact_ids', 'technology_package_candidate',
                ]
                || ! self::identifier($candidate['candidate_id'])
                || ! self::identifier($candidate['work_key'])
                || ! is_string($candidate['name']) || trim($candidate['name']) === '' || strlen($candidate['name']) > 300
                || ($candidate['unit'] !== null && (! is_string($candidate['unit']) || trim($candidate['unit']) === '' || strlen($candidate['unit']) > 32))
                || ! self::quantity($candidate['quantity'])
                || ($candidate['quantity_formula'] !== null
                    && (! is_string($candidate['quantity_formula']) || trim($candidate['quantity_formula']) === '' || strlen($candidate['quantity_formula']) > 300))
                || ! self::identifierList($candidate['source_fact_ids'])
                || ($candidate['technology_package_candidate'] !== null
                    && ! self::identifier($candidate['technology_package_candidate']))) {
                throw new InvalidArgumentException('estimate_composer_candidate_invalid');
            }
        }
        $answers = [];
        if ($this->acceptsSourceClaims()) {
            foreach ($facts as $fact) {
                if (($fact['context_kind'] ?? null) === 'composer_answer' && ($fact['type'] ?? null) === 'evaluation_answer'
                    && ($fact['origin'] ?? null) === 'user_assumption' && ($fact['status'] ?? null) === 'confirmed'
                    && ($fact['value']['kind'] ?? null) === 'composer_answer'
                    && ($fact['value']['numeric_parameters_confirmed'] ?? null) === false
                    && in_array('equipment', $fact['answer_work_sections'] ?? [], true)) {
                    $answers[] = $fact['id'];
                }
            }
        }
        if ($this->acceptsWorkSourceClaims()) {
            $answers = [...$answers, ...array_keys(self::workSourceClaimContexts($workTechnologyContext))];
        }
        $answers = array_values(array_unique($answers));
        sort($answers, SORT_STRING);
        if ($claimAnswerFactIds !== null && (! array_is_list($claimAnswerFactIds)
            || count($claimAnswerFactIds) > self::MAX_SOURCE_CLAIMS || array_diff($claimAnswerFactIds, $answers) !== []
            || count(array_unique($claimAnswerFactIds)) !== count($claimAnswerFactIds))) {
            throw new InvalidArgumentException('estimate_composer_claim_answers_invalid');
        }
        $this->claimAnswerFactIds = $claimAnswerFactIds ?? array_slice($answers, 0, self::MAX_SOURCE_CLAIMS);
        if (! array_is_list($sourceClaimReferences) || count($sourceClaimReferences) > self::MAX_SOURCE_CLAIMS) {
            throw new InvalidArgumentException('estimate_composer_source_claim_refs_invalid');
        }
        foreach ($sourceClaimReferences as $reference) {
            if (! self::sourceClaimReference($reference, $facts, $this->claimAnswerFactIds,
                $this->acceptsWorkSourceClaims() ? $workTechnologyContext : [])) {
                throw new InvalidArgumentException('estimate_composer_source_claim_refs_invalid');
            }
        }
        if (! array_is_list($nativeCellContext) || count($nativeCellContext) > self::MAX_SOURCE_CLAIMS
            || count(array_filter($nativeCellContext, \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource::isWireRow(...))) !== count($nativeCellContext)
            || count(array_unique(array_column($nativeCellContext, 'row_id'))) !== count($nativeCellContext)) {
            throw new InvalidArgumentException('estimate_composer_cell_context_invalid');
        }
        $quantityIds = array_fill_keys(array_column($derivedQuantities, 'id'), true);
        foreach ($directQuantityBindings as $id => $binding) {
            if (! isset($quantityIds[$id]) || ! is_array($binding) || count($binding) !== 5
                || array_diff(array_keys($binding), ['work_item_key', 'entity_id', 'binding_fact_id', 'decision_id', 'quantity_hash']) !== []
                || ! self::identifier($binding['work_item_key'] ?? null) || ! self::identifier($binding['entity_id'] ?? null)
                || ! self::identifier($binding['binding_fact_id'] ?? null) || ! self::identifier($binding['decision_id'] ?? null)
                || ! is_string($binding['quantity_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $binding['quantity_hash']) !== 1) {
                throw new InvalidArgumentException('estimate_composer_quantity_bindings_invalid');
            }
        }
    }

    public function acceptsSourceClaims(): bool
    {
        return in_array($this->baseContractVersion(), ['estimate-composer:answers:v5', 'estimate-composer:legacy-answers:v5',
            'estimate-composer:answers:v4', 'estimate-composer:legacy-answers:v4',
            'estimate-composer:answers:v2', 'estimate-composer:legacy-answers:v2',
            'estimate-composer:answers:v3', 'estimate-composer:legacy-answers:v3'], true);
    }

    public function acceptsWorkSourceClaims(): bool
    {
        return in_array($this->contractVersion, self::WORK_SOURCE_CLAIM_CONTRACTS, true);
    }

    public function acceptsWorkRequirements(): bool
    {
        return $this->acceptsTechnologyQuestions() || in_array($this->contractVersion, ['estimate-composer:v5', 'estimate-composer:legacy:v2',
            'estimate-composer:answers:v3', 'estimate-composer:legacy-answers:v3', 'estimate-composer:cells:v2',
            'estimate-composer:legacy-cells:v2', 'estimate-composer:answer-cells:v2', 'estimate-composer:legacy-answer-cells:v2'], true);
    }

    public function acceptsTechnologyQuestions(): bool
    {
        return in_array($this->contractVersion, ['estimate-composer:v6', 'estimate-composer:legacy:v3',
            'estimate-composer:answers:v4', 'estimate-composer:legacy-answers:v4', 'estimate-composer:cells:v3',
            'estimate-composer:legacy-cells:v3', 'estimate-composer:answer-cells:v3', 'estimate-composer:legacy-answer-cells:v3',
            ...self::WORK_SOURCE_CLAIM_CONTRACTS], true);
    }

    public function withSourceClaimReferences(array $references): self
    {
        return new self($this->organizationId, $this->projectId, $this->sessionId, $this->snapshotToken,
            $this->facts, $this->derivedQuantities, $this->decisions, $this->candidates, $this->missingDocuments,
            $this->contractVersion, $this->wireScope, $this->batchKey, $this->contextManifest, $this->allowedWorkSections,
            $this->claimAnswerFactIds, $references, $this->nativeCellContext, $this->directQuantityBindings, $this->workTechnologyContext);
    }

    public function baseContractVersion(): string
    {
        $base = array_search($this->contractVersion, RunEstimateComposer::CELL_PROMPT_CONTRACTS, true);
        return is_string($base) ? $base : (['estimate-composer:cells:v1' => 'estimate-composer:v4',
            'estimate-composer:legacy-cells:v1' => 'estimate-composer:legacy:v1',
            'estimate-composer:answer-cells:v1' => 'estimate-composer:answers:v2',
            'estimate-composer:legacy-answer-cells:v1' => 'estimate-composer:legacy-answers:v2',
            'estimate-composer:cells:v2' => 'estimate-composer:v5', 'estimate-composer:legacy-cells:v2' => 'estimate-composer:legacy:v2',
            'estimate-composer:answer-cells:v2' => 'estimate-composer:answers:v3',
            'estimate-composer:legacy-answer-cells:v2' => 'estimate-composer:legacy-answers:v3'][$this->contractVersion] ?? $this->contractVersion);
    }

    public function withNativeCellContext(array $rows): self
    {
        $versions = RunEstimateComposer::CELL_PROMPT_CONTRACTS + ['estimate-composer:v4' => 'estimate-composer:cells:v1',
            'estimate-composer:legacy:v1' => 'estimate-composer:legacy-cells:v1',
            'estimate-composer:answers:v2' => 'estimate-composer:answer-cells:v1',
            'estimate-composer:legacy-answers:v2' => 'estimate-composer:legacy-answer-cells:v1',
            'estimate-composer:v5' => 'estimate-composer:cells:v2', 'estimate-composer:legacy:v2' => 'estimate-composer:legacy-cells:v2',
            'estimate-composer:answers:v3' => 'estimate-composer:answer-cells:v2',
            'estimate-composer:legacy-answers:v3' => 'estimate-composer:legacy-answer-cells:v2'];
        $version = $rows === [] ? $this->baseContractVersion() : ($versions[$this->baseContractVersion()]
            ?? throw new InvalidArgumentException('estimate_composer_cell_context_invalid'));

        return new self($this->organizationId, $this->projectId, $this->sessionId, $this->snapshotToken,
            $this->facts, $this->derivedQuantities, $this->decisions, $this->candidates, $this->missingDocuments,
            $version, $this->wireScope, $this->batchKey, $this->contextManifest, $this->allowedWorkSections,
            $this->claimAnswerFactIds, $this->sourceClaimReferences, $rows, $this->directQuantityBindings, $this->workTechnologyContext);
    }

    public static function sourceClaimReference(mixed $reference, array $facts, array $answerIds, array $workContexts = []): bool
    {
        if (! is_array($reference) || count($reference) !== 10 || ! is_int($reference['learning_example_id'] ?? null)
            || $reference['learning_example_id'] < 1 || ($reference['interpretation_status'] ?? null) !== 'proposed'
            || ($reference['source_correspondence'] ?? null) !== 'passed'
            || ($reference['engineering_applicability'] ?? null) !== 'unresolved') {
            return false;
        }
        if (array_key_exists('source_review_decision_id', $reference)) {
            $candidate = array_intersect_key($reference, array_flip(['source_review_decision_id', 'entity_id', 'fact_type', 'value', 'unit', 'document_fact_ids']));
            if (! \App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService::isCellReviewCandidate($candidate)) {
                return false;
            }
            $document = array_column($facts, null, 'id')[$candidate['document_fact_ids'][0]] ?? null;
            $review = array_values(array_filter($facts, static fn (array $fact): bool => ($fact['type'] ?? null) === 'evaluation_answer'
                && ($fact['origin'] ?? null) === 'user_input' && ($fact['status'] ?? null) === 'confirmed'
                && ($fact['value']['kind'] ?? null) === 'document_cell_review' && ($fact['value']['decision'] ?? null) === 'accept'
                && ($fact['value']['source_review_decision_id'] ?? null) === $candidate['source_review_decision_id']));

            return count($review) === 1 && self::sourceClaimDocument($document) && $document['entity_id'] === $candidate['entity_id']
                && ($document['source_review_decision_id'] ?? null) === $candidate['source_review_decision_id'];
        }
        if (! in_array($reference['source_answer_fact_id'] ?? null, $answerIds, true)) {
            return false;
        }

        return self::sourceClaimCandidate(array_intersect_key($reference, array_flip([
            'source_answer_fact_id', 'entity_id', 'fact_type', 'value', 'unit', 'document_fact_ids',
        ])), $facts, $answerIds, $workContexts);
    }

    public static function sourceClaimCandidate(mixed $candidate, array $facts, array $answerIds, array $workContexts = []): bool
    {
        if (! is_array($candidate) || count($candidate) !== 6
            || array_diff(array_keys($candidate), ['source_answer_fact_id', 'entity_id', 'fact_type', 'value', 'unit', 'document_fact_ids']) !== []
            || ! in_array($candidate['source_answer_fact_id'] ?? null, $answerIds, true)
            || ! self::identifier($candidate['entity_id'] ?? null)
            || ($candidate['fact_type'] ?? null) !== 'equipment_specification'
            || ! array_key_exists('unit', $candidate) || $candidate['unit'] !== null
            || ! is_array($candidate['value'] ?? null) || count($candidate['value']) !== 2
            || ($candidate['value']['type'] ?? null) !== 'string'
            || ! is_string($candidate['value']['data'] ?? null) || trim($candidate['value']['data']) === ''
            || mb_strlen($candidate['value']['data']) > 500
            || ! is_array($candidate['document_fact_ids'] ?? null) || ! array_is_list($candidate['document_fact_ids'])
            || count($candidate['document_fact_ids']) !== 1 || ! self::identifier($candidate['document_fact_ids'][0])) {
            return false;
        }
        $byId = array_column($facts, null, 'id');
        $document = $byId[$candidate['document_fact_ids'][0]] ?? null;
        $work = self::workSourceClaimContexts($workContexts)[$candidate['source_answer_fact_id']] ?? null;
        if ($work !== null) {
            $pins = array_filter($work['documents'], static fn (array $pin): bool => in_array($pin['document_id'], $work['answer']['document_ids'], true));

            return self::sourceClaimDocument($document) && $document['entity_id'] === $candidate['entity_id']
                && $candidate['entity_id'] === $work['context']['binding']['entity_id']
                && ($document['value'] ?? null) === $candidate['value']['data']
                && in_array($document['source_version'] ?? null, array_column($pins, 'source_version'), true);
        }

        return isset($byId[$candidate['source_answer_fact_id']])
            && (! array_key_exists('target_entity_id', $byId[$candidate['source_answer_fact_id']]['value'] ?? [])
                || $byId[$candidate['source_answer_fact_id']]['value']['target_entity_id'] === $candidate['entity_id'])
            && $candidate['document_fact_ids'][0] !== $candidate['source_answer_fact_id']
            && self::sourceClaimDocument($document) && $document['entity_id'] === $candidate['entity_id'];
    }

    public static function workSourceClaimContexts(array $entries): array
    {
        $result = $duplicates = [];
        foreach ($entries as $entry) {
            $answer = $entry['answer'] ?? null;
            $context = $entry['context'] ?? null;
            $binding = $context['binding'] ?? null;
            if (($entry['status'] ?? null) !== 'answered' || ! is_array($answer) || ! is_array($binding)
                || ($context['schema_version'] ?? null) !== 1 || ($binding['work_section'] ?? null) !== 'equipment'
                || ! self::identifier($binding['entity_id'] ?? null) || ! self::identifier($binding['work_key'] ?? null)
                || ! is_string($context['binding_hash'] ?? null)
                || ! hash_equals($context['binding_hash'], hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($binding)))
                || ($answer['response'] ?? null) !== 'other' || ($answer['trust'] ?? null) !== 'user_assumption'
                || ($answer['engineering_applicability'] ?? null) !== 'unresolved' || ($answer['numeric_parameters_confirmed'] ?? null) !== false
                || ! self::identifier($answer['fact_id'] ?? null) || ! self::identifier($answer['decision_id'] ?? null)
                || ! is_string($answer['other'] ?? null) || trim($answer['other']) === '' || mb_strlen($answer['other']) > 500
                || ! is_array($answer['document_ids'] ?? null) || ! array_is_list($answer['document_ids'])
                || $answer['document_ids'] === [] || count($answer['document_ids']) > self::MAX_SOURCE_CLAIMS
                || count(array_unique($answer['document_ids'])) !== count($answer['document_ids'])
                || count(array_filter($answer['document_ids'], static fn ($id): bool => is_int($id) && $id > 0)) !== count($answer['document_ids'])
                || ! is_array($entry['documents'] ?? null) || ! array_is_list($entry['documents'])
                || count(array_filter($entry['documents'], 'is_array')) !== count($entry['documents'])) {
                continue;
            }
            $pins = array_filter($entry['documents'], static fn (mixed $pin): bool => is_array($pin)
                && in_array($pin['document_id'] ?? null, $answer['document_ids'], true)
                && in_array($pin['status'] ?? null, ['ready', 'needs_review'], true)
                && is_string($pin['checksum_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $pin['checksum_sha256']) === 1
                && ($pin['source_version'] ?? null) === 'sha256:'.$pin['checksum_sha256']);
            if (count($pins) !== count($answer['document_ids'])
                || count(array_unique(array_column($pins, 'document_id'))) !== count($pins)) {
                continue;
            }
            if (isset($result[$answer['fact_id']])) {
                $duplicates[$answer['fact_id']] = true;
            }
            $result[$answer['fact_id']] = $entry;
        }
        ksort($result, SORT_STRING);

        return array_diff_key($result, $duplicates);
    }

    public static function sourceClaimDocument(mixed $document): bool
    {
        return is_array($document) && ($document['origin'] ?? null) === 'document' && ($document['status'] ?? null) === 'confirmed'
            && ($document['type'] ?? null) === 'equipment_specification' && ($document['entity_type'] ?? null) === 'equipment'
            && self::identifier($document['entity_id'] ?? null) && ($document['unit'] ?? null) === null;
    }

    public function fingerprint(): string
    {
        $payload = $this->canonicalPayload();
        if ($this->wireScope?->creditOperationId !== null) {
            $payload['approved_operation_id'] = $this->wireScope->creditOperationId;
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        $payload = [
            'scope' => [
                'organization_id' => $this->organizationId,
                'project_id' => $this->projectId,
                'session_id' => $this->sessionId,
            ],
            'snapshot_token' => $this->snapshotToken,
            'facts' => $this->sorted($this->facts),
            'derived_quantities' => $this->sorted($this->derivedQuantities),
            'decisions' => $this->sorted($this->decisions),
            'candidates' => $this->candidates,
            'missing_documents' => $this->sorted($this->missingDocuments),
            'contract_version' => $this->contractVersion,
        ];
        if ($this->batchKey !== null) {
            $payload['batch_key'] = $this->batchKey;
        }
        if ($this->contextManifest !== null) {
            $payload['context_manifest'] = $this->contextManifest;
        }
        if ($this->allowedWorkSections !== []) {
            $payload['allowed_work_sections'] = $this->allowedWorkSections;
        }
        if ($this->workTechnologyContext !== []) {
            $payload['work_technology_context'] = array_map(static function (array $entry): array {
                $ids = ($entry['answer']['response'] ?? null) === 'other' ? $entry['answer']['document_ids'] : [];
                return [...array_intersect_key($entry, array_flip(['context', 'answer', 'status'])), 'documents' => array_values(array_filter($entry['documents'],
                    static fn (array $document): bool => in_array($document['document_id'], $ids, true)))];
            }, $this->workTechnologyContext);
        }
        if ($this->acceptsSourceClaims()) {
            $payload['claim_answer_fact_ids'] = $this->claimAnswerFactIds;
        }
        if ($this->sourceClaimReferences !== []) {
            $payload['source_claim_refs'] = array_map(static fn (array $reference): array => array_intersect_key($reference, array_flip([
                'learning_example_id', 'entity_id', 'fact_type', 'value', 'unit', 'document_fact_ids',
            ])), $this->sourceClaimReferences);
        }
        if ($this->nativeCellContext !== []) {
            $payload['native_cell_context'] = $this->nativeCellContext;
        }

        return $payload;
    }

    /** @param list<array<string, mixed>> $records @return list<array<string, mixed>> */
    private function sorted(array $records): array
    {
        usort($records, static fn (array $left, array $right): int => json_encode($left, JSON_THROW_ON_ERROR)
            <=> json_encode($right, JSON_THROW_ON_ERROR));

        return $records;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $value) === 1;
    }

    private static function quantity(mixed $value): bool
    {
        return $value === null || (is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?$/D', $value) === 1
            && preg_match('/^0(?:\.0+)?$/D', $value) !== 1);
    }

    private static function identifierList(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > 256
            || count($values) !== count(array_unique($values, SORT_STRING))) {
            return false;
        }
        foreach ($values as $value) {
            if (! self::identifier($value)) {
                return false;
            }
        }

        return true;
    }
}
