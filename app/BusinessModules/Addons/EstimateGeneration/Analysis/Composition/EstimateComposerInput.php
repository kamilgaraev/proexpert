<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use InvalidArgumentException;

final readonly class EstimateComposerInput
{
    /**
     * @param list<array<string, mixed>> $facts
     * @param list<array<string, mixed>> $derivedQuantities
     * @param list<array<string, mixed>> $decisions
     * @param list<array{candidate_id:string,work_key:string,name:string,unit:?string,quantity:?string,quantity_formula:?string,source_fact_ids:list<string>,technology_package_candidate:?string}> $candidates
     * @param list<array<string, mixed>> $missingDocuments
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
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotToken) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/D', $contractVersion) !== 1
            || ! array_is_list($facts) || count($facts) > 10000
            || ! array_is_list($derivedQuantities) || count($derivedQuantities) > 2000
            || ! array_is_list($decisions) || count($decisions) > 2000
            || ! array_is_list($candidates) || $candidates === [] || count($candidates) > 500
            || ! array_is_list($missingDocuments) || count($missingDocuments) > 200) {
            throw new InvalidArgumentException('estimate_composer_input_invalid');
        }
        foreach ($facts as $fact) {
            if (! is_array($fact) || ! self::identifier($fact['id'] ?? null)) {
                throw new InvalidArgumentException('estimate_composer_fact_invalid');
            }
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
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->canonicalPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
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
