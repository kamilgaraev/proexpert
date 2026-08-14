<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use InvalidArgumentException;

final readonly class EstimateWorkIntent
{
    /** @param list<string> $sourceFactIds @param list<string> $assumptions @param list<string> $exclusions @param list<string> $missingDocumentRecommendations */
    public function __construct(
        public string $candidateId,
        public array $sourceFactIds,
        public ?string $technologyPackageCandidate,
        public array $assumptions,
        public array $exclusions,
        public array $missingDocumentRecommendations,
    ) {
        if (! self::identifier($candidateId)
            || ($technologyPackageCandidate !== null && ! self::identifier($technologyPackageCandidate))) {
            throw new InvalidArgumentException('estimate_work_intent_value_invalid');
        }
        self::assertStringList($sourceFactIds, 256, 160, true);
        self::assertStringList($assumptions, 20, 500, false);
        self::assertStringList($exclusions, 20, 500, false);
        self::assertStringList($missingDocumentRecommendations, 20, 800, false);
    }

    public static function fromArray(array $payload): self
    {
        $actualKeys = array_keys($payload);
        $expectedKeys = [
            'candidate_id',
            'source_fact_ids',
            'technology_package_candidate',
            'assumptions',
            'exclusions',
            'missing_document_recommendations',
        ];
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException('estimate_work_intent_shape_invalid');
        }

        return new self(
            candidateId: is_string($payload['candidate_id']) ? $payload['candidate_id'] : '',
            sourceFactIds: is_array($payload['source_fact_ids']) ? $payload['source_fact_ids'] : [''],
            technologyPackageCandidate: is_string($payload['technology_package_candidate'] ?? null)
                ? $payload['technology_package_candidate']
                : null,
            assumptions: is_array($payload['assumptions']) ? $payload['assumptions'] : [''],
            exclusions: is_array($payload['exclusions']) ? $payload['exclusions'] : [''],
            missingDocumentRecommendations: is_array($payload['missing_document_recommendations'])
                ? $payload['missing_document_recommendations']
                : [''],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidate_id' => $this->candidateId,
            'source_fact_ids' => $this->sourceFactIds,
            'technology_package_candidate' => $this->technologyPackageCandidate,
            'assumptions' => $this->assumptions,
            'exclusions' => $this->exclusions,
            'missing_document_recommendations' => $this->missingDocumentRecommendations,
        ];
    }

    private static function assertStringList(array $values, int $maxItems, int $maxBytes, bool $identifier): void
    {
        if (! array_is_list($values) || count($values) > $maxItems || count($values) !== count(array_unique($values, SORT_STRING))) {
            throw new InvalidArgumentException('estimate_work_intent_list_invalid');
        }
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '' || strlen($value) > $maxBytes
                || ($identifier && ! self::identifier($value))) {
                throw new InvalidArgumentException('estimate_work_intent_list_invalid');
            }
        }
    }

    private static function identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $value) === 1;
    }
}
