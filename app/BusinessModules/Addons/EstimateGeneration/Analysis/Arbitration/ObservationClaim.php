<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

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
            || $locator === [] || array_is_list($locator)) {
            throw new InvalidArgumentException('observation_claim_invalid');
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
        );
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
