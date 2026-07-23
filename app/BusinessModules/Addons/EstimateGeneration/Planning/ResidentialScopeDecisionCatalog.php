<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final class ResidentialScopeDecisionCatalog
{
    public const VERSION = 'residential-scope-decisions:v1';

    private const DEFINITIONS = [
        'heating_source' => [
            'options' => ['electric_boiler', 'gas_boiler', 'heat_pump', 'district_heating'],
            'statuses' => ['preliminary', 'documented', 'needs_data'],
            'preliminary_default' => 'electric_boiler',
        ],
        'wastewater_destination' => [
            'options' => ['central_sewer', 'septic'],
            'statuses' => ['preliminary', 'documented', 'needs_data'],
            'preliminary_default' => 'septic',
        ],
    ];

    /**
     * @return array<string, array{options: list<string>, statuses: list<string>, preliminary_default: string}>
     */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array<string, array{options: list<string>, statuses: list<string>, preliminary_default: string}> */
    public function aiDefinitions(): array
    {
        return array_map(
            static fn (array $definition): array => [
                ...$definition,
                'statuses' => ['preliminary', 'needs_data'],
            ],
            self::DEFINITIONS,
        );
    }

    /**
     * @param  list<string>  $allowedEvidenceIds
     * @return array<string, array{option: ?string, status: string, confidence: float, evidence_ids: list<string>}>|null
     */
    public function validate(mixed $rows, array $allowedEvidenceIds, array $documentedOptions = []): ?array
    {
        if (! is_array($rows)
            || ! array_is_list($rows)
            || count($rows) !== count(self::DEFINITIONS)) {
            return null;
        }

        $allowedEvidence = array_fill_keys($allowedEvidenceIds, true);
        $decisions = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['confidence', 'evidence_ids', 'key', 'option', 'status']) {
                return null;
            }

            $key = $row['key'] ?? null;
            $status = $row['status'] ?? null;
            $option = $row['option'] ?? null;
            $confidence = $row['confidence'] ?? null;
            $evidenceIds = $row['evidence_ids'] ?? null;
            if (! is_string($key)
                || ! isset(self::DEFINITIONS[$key])
                || isset($decisions[$key])
                || ! is_string($status)
                || ! in_array($status, self::DEFINITIONS[$key]['statuses'], true)
                || ! is_numeric($confidence)
                || (float) $confidence < 0
                || (float) $confidence > 1
                || ! is_array($evidenceIds)
                || ! array_is_list($evidenceIds)
                || count($evidenceIds) > 32) {
                return null;
            }

            $normalizedEvidenceIds = [];
            foreach ($evidenceIds as $evidenceId) {
                if (! is_string($evidenceId)
                    || ! isset($allowedEvidence[$evidenceId])
                    || in_array($evidenceId, $normalizedEvidenceIds, true)) {
                    return null;
                }
                $normalizedEvidenceIds[] = $evidenceId;
            }

            if ($status === 'needs_data') {
                if ($option !== null || $normalizedEvidenceIds !== []) {
                    return null;
                }
            } elseif (! is_string($option)
                || ! in_array($option, self::DEFINITIONS[$key]['options'], true)
                || ($status === 'preliminary'
                    && ($option !== self::DEFINITIONS[$key]['preliminary_default']
                        || $normalizedEvidenceIds !== []))
                || ($status === 'documented' && ! $this->matchesDocumentedOption(
                    $key,
                    $option,
                    $normalizedEvidenceIds,
                    $documentedOptions,
                ))) {
                return null;
            }

            $decisions[$key] = [
                'option' => $option,
                'status' => $status,
                'confidence' => round((float) $confidence, 4),
                'evidence_ids' => $normalizedEvidenceIds,
            ];
        }

        ksort($decisions, SORT_STRING);

        return count($decisions) === count(self::DEFINITIONS) ? $decisions : null;
    }

    /** @param list<string> $evidenceIds @param array<string, mixed> $documentedOptions */
    private function matchesDocumentedOption(
        string $key,
        string $option,
        array $evidenceIds,
        array $documentedOptions,
    ): bool {
        $documented = $documentedOptions[$key] ?? null;
        if (! is_array($documented)
            || ($documented['option'] ?? null) !== $option
            || $evidenceIds === []) {
            return false;
        }

        $documentedEvidenceIds = array_values(array_unique(array_map(
            'strval',
            is_array($documented['evidence_ids'] ?? null) ? $documented['evidence_ids'] : [],
        )));

        return $documentedEvidenceIds !== []
            && array_diff($evidenceIds, $documentedEvidenceIds) === [];
    }
}
