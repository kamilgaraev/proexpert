<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions\NormativeRerankingInvalidResponse;

final readonly class NormativeRerankResultData
{
    public const SCHEMA_VERSION = 'normative-rerank-v1';

    private const RESPONSE_FIELDS = [
        'selected_candidate_id', 'ordering', 'explanation_codes', 'evidence_refs', 'confidence', 'schema_version',
    ];

    private const EXPLANATION_CODES = [
        'unit_match', 'material_match', 'technology_match', 'structure_match', 'section_match',
        'semantic_match', 'lexical_match', 'insufficient_evidence',
    ];

    /** @param list<string> $ordering @param list<string> $explanationCodes @param list<string> $evidenceRefs */
    public function __construct(
        public ?string $selectedCandidateId,
        public array $ordering,
        public array $explanationCodes,
        public array $evidenceRefs,
        public float $confidence,
        public string $status,
        public string $schemaVersion,
        public string $provider,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $exactCandidateIds
     * @param list<string> $allowedEvidenceRefs
     */
    public static function fromProviderArray(
        array $payload,
        array $exactCandidateIds,
        array $allowedEvidenceRefs = [],
        string $provider = 'llm',
    ): self {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $expected = self::RESPONSE_FIELDS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected || ($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ! array_is_list($exactCandidateIds) || $exactCandidateIds === []
            || count($exactCandidateIds) > 32 || count(array_unique($exactCandidateIds)) !== count($exactCandidateIds)) {
            throw new NormativeRerankingInvalidResponse('Closed schema violation.');
        }
        foreach ($exactCandidateIds as $candidateId) {
            if (! is_string($candidateId) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $candidateId) !== 1) {
                throw new NormativeRerankingInvalidResponse('Candidate set is invalid.');
            }
        }
        $ordering = $payload['ordering'];
        if (! is_array($ordering) || ! array_is_list($ordering)
            || count($ordering) !== count($exactCandidateIds)
            || count(array_unique($ordering)) !== count($ordering)
            || array_diff($ordering, $exactCandidateIds) !== []
            || array_diff($exactCandidateIds, $ordering) !== []) {
            throw new NormativeRerankingInvalidResponse('Ordering is invalid.');
        }
        $selected = $payload['selected_candidate_id'];
        if (! is_string($selected) || $selected !== ($ordering[0] ?? null)) {
            throw new NormativeRerankingInvalidResponse('Selected candidate is invalid.');
        }
        $codes = $payload['explanation_codes'];
        $evidence = $payload['evidence_refs'];
        $confidence = $payload['confidence'];
        if (! is_array($codes) || ! array_is_list($codes) || count($codes) > 8
            || count(array_unique($codes)) !== count($codes) || array_diff($codes, self::EXPLANATION_CODES) !== []
            || ! is_array($evidence) || ! array_is_list($evidence) || count($evidence) > 12
            || count(array_unique($evidence)) !== count($evidence)
            || ! is_int($confidence) && ! is_float($confidence)
            || ! is_finite((float) $confidence) || (float) $confidence < 0 || (float) $confidence > 1
            || preg_match('/^[a-z0-9._-]{1,80}$/D', $provider) !== 1) {
            throw new NormativeRerankingInvalidResponse('Decision fields are invalid.');
        }
        foreach ($evidence as $reference) {
            if (! is_string($reference) || strlen($reference) > 128
                || ($allowedEvidenceRefs !== [] && ! in_array($reference, $allowedEvidenceRefs, true))) {
                throw new NormativeRerankingInvalidResponse('Evidence reference is invalid.');
            }
        }

        return new self(
            $selected,
            array_values($ordering),
            array_values($codes),
            array_values($evidence),
            (float) $confidence,
            'reranked',
            self::SCHEMA_VERSION,
            $provider,
        );
    }
}
