<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use InvalidArgumentException;

final readonly class EstimateComposerInput
{
    public const MAX_SOURCE_CLAIMS = 8;

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
            if (! self::sourceClaimReference($reference, $facts, $this->claimAnswerFactIds)) {
                throw new InvalidArgumentException('estimate_composer_source_claim_refs_invalid');
            }
        }
    }

    public function acceptsSourceClaims(): bool
    {
        return in_array($this->contractVersion, [RunEstimateComposer::ANSWER_PROMPT_CONTRACT,
            RunEstimateComposer::LEGACY_ANSWER_PROMPT_CONTRACT], true);
    }

    public function withSourceClaimReferences(array $references): self
    {
        return new self($this->organizationId, $this->projectId, $this->sessionId, $this->snapshotToken,
            $this->facts, $this->derivedQuantities, $this->decisions, $this->candidates, $this->missingDocuments,
            $this->contractVersion, $this->wireScope, $this->batchKey, $this->contextManifest, $this->allowedWorkSections,
            $this->claimAnswerFactIds, $references);
    }

    public static function sourceClaimReference(mixed $reference, array $facts, array $answerIds): bool
    {
        if (! is_array($reference) || count($reference) !== 10 || ! is_int($reference['learning_example_id'] ?? null)
            || $reference['learning_example_id'] < 1 || ($reference['interpretation_status'] ?? null) !== 'proposed'
            || ($reference['source_correspondence'] ?? null) !== 'passed'
            || ($reference['engineering_applicability'] ?? null) !== 'unresolved'
            || ! in_array($reference['source_answer_fact_id'] ?? null, $answerIds, true)) {
            return false;
        }

        return self::sourceClaimCandidate(array_intersect_key($reference, array_flip([
            'source_answer_fact_id', 'entity_id', 'fact_type', 'value', 'unit', 'document_fact_ids',
        ])), $facts, $answerIds);
    }

    public static function sourceClaimCandidate(mixed $candidate, array $facts, array $answerIds): bool
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

        return isset($byId[$candidate['source_answer_fact_id']])
            && $candidate['document_fact_ids'][0] !== $candidate['source_answer_fact_id']
            && self::sourceClaimDocument($document) && $document['entity_id'] === $candidate['entity_id'];
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
        if ($this->acceptsSourceClaims()) {
            $payload['claim_answer_fact_ids'] = $this->claimAnswerFactIds;
        }
        if ($this->sourceClaimReferences !== []) {
            $payload['source_claim_refs'] = array_map(static fn (array $reference): array => array_intersect_key($reference, array_flip([
                'learning_example_id', 'entity_id', 'fact_type', 'value', 'unit', 'document_fact_ids',
            ])), $this->sourceClaimReferences);
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
