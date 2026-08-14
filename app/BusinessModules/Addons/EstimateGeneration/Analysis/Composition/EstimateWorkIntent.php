<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use InvalidArgumentException;

final readonly class EstimateWorkIntent
{
    /** @param list<string> $sourceFactIds @param list<string> $assumptions @param list<string> $exclusions @param list<string> $missingDocumentRecommendations */
    public function __construct(
        public string $kind,
        public string $candidateId,
        public ?string $workKey,
        public ?string $name,
        public ?string $derivedQuantityId,
        public array $sourceFactIds,
        public ?string $technologyPackageCandidate,
        public array $assumptions,
        public array $exclusions,
        public array $missingDocumentRecommendations,
    ) {
        if (! in_array($kind, ['existing', 'supplementary'], true)
            || ! self::identifier($candidateId)
            || ($technologyPackageCandidate !== null && ! self::identifier($technologyPackageCandidate))) {
            throw new InvalidArgumentException('estimate_work_intent_value_invalid');
        }
        self::assertStringList($sourceFactIds, 256, 160, true);
        self::assertStringList($assumptions, 20, 500, false);
        self::assertStringList($exclusions, 20, 500, false);
        self::assertStringList($missingDocumentRecommendations, 20, 800, false);
        if ($kind === 'existing' && ($workKey !== null || $name !== null || $derivedQuantityId !== null)) {
            throw new InvalidArgumentException('estimate_work_intent_existing_shape_invalid');
        }
        if ($kind === 'supplementary' && (
            ! str_starts_with($candidateId, 'supplementary:')
            || ! self::identifier($workKey ?? '')
            || ! is_string($name) || trim($name) === '' || strlen($name) > 300
            || ($derivedQuantityId !== null && ! self::identifier($derivedQuantityId))
            || $sourceFactIds === []
        )) {
            throw new InvalidArgumentException('estimate_work_intent_supplementary_shape_invalid');
        }
        if ($kind === 'supplementary' && ! self::humanReadable((string) $name)) {
            throw new InvalidArgumentException('estimate_work_intent_text_invalid');
        }
    }

    public static function fromArray(array $payload): self
    {
        $actualKeys = array_keys($payload);
        $expectedKeys = [
            'kind',
            'candidate_id',
            'work_key',
            'name',
            'derived_quantity_id',
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
            kind: is_string($payload['kind']) ? $payload['kind'] : '',
            candidateId: is_string($payload['candidate_id']) ? $payload['candidate_id'] : '',
            workKey: is_string($payload['work_key'] ?? null) ? $payload['work_key'] : null,
            name: is_string($payload['name'] ?? null) ? $payload['name'] : null,
            derivedQuantityId: is_string($payload['derived_quantity_id'] ?? null) ? $payload['derived_quantity_id'] : null,
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
            'kind' => $this->kind,
            'candidate_id' => $this->candidateId,
            'work_key' => $this->workKey,
            'name' => $this->name,
            'derived_quantity_id' => $this->derivedQuantityId,
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
            if (! $identifier && ! self::humanReadable($value)) {
                throw new InvalidArgumentException('estimate_work_intent_text_invalid');
            }
        }
    }

    private static function humanReadable(string $value): bool
    {
        return preg_match('/\p{Cyrillic}/u', $value) === 1
            && preg_match('/\b(?:provider|payload|dto|exception|sql|constraint|fallback|legacy|openai|timeweb|gpt|confidence|model[_ -]?version)\b/iu', $value) !== 1
            && preg_match('/^нужно уточнить[.!]?$/iu', trim($value)) !== 1;
    }

    private static function identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $value) === 1;
    }
}
