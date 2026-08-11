<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use InvalidArgumentException;

final readonly class OrganizationPreferenceContext
{
    public function __construct(public int $organizationId, public array $systemWeights)
    {
        if ($organizationId <= 0 || count($systemWeights) > 20) {
            throw new InvalidArgumentException('Organization preference context is invalid.');
        }
        foreach ($systemWeights as $systemId => $weight) {
            if (! is_string($systemId) || ! is_int($weight) || $weight < -2 || $weight > 2) {
                throw new InvalidArgumentException('Organization preference weight is invalid.');
            }
        }
    }

    public function tieBreaker(string $systemId): int
    {
        return $this->systemWeights[$systemId] ?? 0;
    }
}
