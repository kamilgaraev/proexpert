<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactSourceLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity;
use InvalidArgumentException;

final readonly class ObservationClaim
{
    /** @param array{type:string,data:mixed} $value @param array<string,mixed> $locator */
    public function __construct(
        public string $id,
        public string $observerRole,
        public string $entityKey,
        public string $factType,
        public array $value,
        public ?string $unit,
        public ?string $evidenceRef,
        public bool $explicitEvidence,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public array $locator,
        public float $confidence = 0.0,
        public ?array $semanticProposal = null,
        public array $semanticFieldLocators = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/D', $id) !== 1
            || ! in_array($observerRole, ['observer_literal', 'observer_construction', 'observer_risk'], true)
            || trim($entityKey) === '' || mb_strlen($entityKey) > 120
            || trim($factType) === '' || mb_strlen($factType) > 120
            || ! in_array($value['type'] ?? null, ['number', 'string', 'boolean', 'enum', 'unknown'], true)
            || ! array_key_exists('data', $value)
            || ($unit !== null && (trim($unit) === '' || mb_strlen($unit) > 40))
            || ($evidenceRef !== null && preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/D', $evidenceRef) !== 1)
            || $organizationId < 1 || $projectId < 1 || $sessionId < 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1
            || $locator === [] || array_is_list($locator)
            || ! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('observation_claim_invalid');
        }
        $normalized = FactVocabulary::semanticProposal($semanticProposal, $factType, $entityKey, $value, $unit);
        if ($normalized !== null && ($observerRole !== 'observer_construction'
            || DerivedQuantityIdentity::canonicalJson($normalized) !== DerivedQuantityIdentity::canonicalJson($semanticProposal))) {
            throw new InvalidArgumentException('observation_semantic_proposal_invalid');
        }
        $expected = [];
        foreach ($normalized === null ? [] : FactVocabulary::semanticSourceFields($normalized) as $path => $field) {
            if ($field['evidenceRef'] === null) continue;
            $resolved = $semanticFieldLocators[$path] ?? null;
            if (! is_array($resolved) || ($resolved['source_version'] ?? null) !== $sourceVersion
                || ($resolved['organization_id'] ?? null) !== $organizationId || ($resolved['project_id'] ?? null) !== $projectId
                || ($resolved['session_id'] ?? null) !== $sessionId
                || ($resolved['document_id'] ?? null) !== ($locator['document_id'] ?? null)
                || ($resolved['page'] ?? null) !== ($locator['page'] ?? null)
                || ($resolved['page_id'] ?? null) !== ($locator['page_id'] ?? null)
                || ($resolved['processing_unit_id'] ?? null) !== ($locator['processing_unit_id'] ?? null)
                || ($resolved['fact_source'] ?? null) !== self::exactSource($field['sourcePolygonOrNativeRef'])) {
                throw new InvalidArgumentException('observation_semantic_locator_invalid');
            }
            $expected[] = $path;
        }
        if (array_diff(array_keys($semanticFieldLocators), $expected) !== [] || count($expected) !== count($semanticFieldLocators)) {
            throw new InvalidArgumentException('observation_semantic_locator_invalid');
        }
    }

    /**
     * @param  array<string,mixed>  $claim
     * @param  array<string,array<string,mixed>>  $evidence
     */
    public static function fromObserverPayload(
        string $role,
        int $index,
        array $claim,
        array $evidence,
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
    ): self {
        $evidenceRef = is_string($claim['evidenceRef'] ?? null) ? $claim['evidenceRef'] : null;
        $locator = $evidenceRef === null ? null : ($evidence[$evidenceRef] ?? null);
        if (! is_array($locator)
            || ($locator['organization_id'] ?? $organizationId) !== $organizationId
            || ($locator['project_id'] ?? $projectId) !== $projectId
            || ($locator['session_id'] ?? $sessionId) !== $sessionId
            || ($locator['source_version'] ?? null) !== $sourceVersion) {
            throw new InvalidArgumentException('observation_claim_locator_out_of_scope');
        }
        $value = $claim['value'] ?? null;
        if (! is_array($value)) {
            throw new InvalidArgumentException('observation_claim_value_invalid');
        }
        $native = $claim['sourcePolygonOrNativeRef'] ?? null;
        // Never inherit a provider-supplied per-fact locator from page evidence.
        unset($locator['fact_source']);
        $exact = is_array($native)
            ? ['kind' => 'polygon', 'coordinate_space' => $locator['coordinate_space'] ?? null, 'value' => $native]
            : ['kind' => 'native', 'coordinate_space' => 'native', 'value' => $native];
        if (FactSourceLocator::valid($exact)) {
            $locator['fact_source'] = $exact;
        }
        if (($claim['semanticProposal'] ?? null) !== null && ! is_array($claim['semanticProposal'])) {
            throw new InvalidArgumentException('observation_semantic_proposal_invalid');
        }
        $proposal = FactVocabulary::semanticProposal($claim['semanticProposal'] ?? null,
            (string) ($claim['factType'] ?? ''), (string) ($claim['entityKey'] ?? ''), $value, is_string($claim['unit'] ?? null) ? $claim['unit'] : null);
        if ($proposal !== null && ($claim['contractVersion'] ?? null) !== 'sheet-analysis:v4') {
            throw new InvalidArgumentException('observation_semantic_proposal_invalid');
        }
        $fields = [];
        foreach ($proposal === null ? [] : FactVocabulary::semanticSourceFields($proposal) as $path => $field) {
            if ($field['evidenceRef'] === null) continue;
            $fieldLocator = $evidence[$field['evidenceRef']] ?? null;
            if (! is_array($fieldLocator)) throw new InvalidArgumentException('observation_claim_locator_out_of_scope');
            unset($fieldLocator['fact_source']);
            $fieldLocator['fact_source'] = self::exactSource($field['sourcePolygonOrNativeRef']);
            $fields[$path] = $fieldLocator;
        }

        return new self(
            str_replace('observer_', '', $role).':'.($index + 1),
            $role,
            (string) ($claim['entityKey'] ?? ''),
            (string) ($claim['factType'] ?? ''),
            $value,
            is_string($claim['unit'] ?? null) ? $claim['unit'] : null,
            $evidenceRef,
            self::hasExplicitSourceReference($native) || (bool) ($locator['explicit'] ?? false),
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $locator,
            ! array_key_exists('confidence', $claim)
                ? 0.0
                : (is_numeric($claim['confidence']) ? (float) $claim['confidence'] : -1.0),
            $proposal,
            $fields,
        );
    }

    public function semanticIdentity(): ?array
    {
        return $this->semanticProposal === null ? null : ['payload' => $this->semanticProposal, 'field_locators' => $this->semanticFieldLocators];
    }

    private static function exactSource(mixed $source): array
    {
        $exact = ['kind' => is_array($source) ? 'polygon' : 'native',
            'coordinate_space' => is_array($source) ? 'normalized_source_v1' : 'native', 'value' => $source];
        if (! FactSourceLocator::valid($exact)) throw new InvalidArgumentException('observation_claim_locator_out_of_scope');

        return $exact;
    }

    private static function hasExplicitSourceReference(mixed $source): bool
    {
        if (is_string($source)) {
            return trim($source) !== '';
        }
        if (! is_array($source) || count($source) < 2 || count($source) > 64) {
            return false;
        }
        $points = [];
        foreach ($source as $point) {
            if (! is_array($point) || count($point) !== 2
                || ! is_numeric($point[0]) || ! is_numeric($point[1])
                || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1])
                || (float) $point[0] < 0 || (float) $point[0] > 1
                || (float) $point[1] < 0 || (float) $point[1] > 1) {
                return false;
            }
            $points[] = sprintf('%.12F:%.12F', (float) $point[0], (float) $point[1]);
        }

        return count($points) === count(array_unique($points));
    }
}
