<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use InvalidArgumentException;

final readonly class ScaleResolutionData
{
    public function __construct(public string $status, public ?float $metersPerUnit, public array $evidenceRefs, public ?string $blockingIssue, public ?ScaleContextData $context = null)
    {
        if (! in_array($status, ['missing', 'confirmed', 'conflict'], true) || ! array_is_list($evidenceRefs)
            || count($evidenceRefs) !== count(array_unique($evidenceRefs))) {
            throw new InvalidArgumentException('Scale resolution is invalid.');
        }
        foreach ($evidenceRefs as $reference) {
            if (! is_string($reference) || $reference === '') {
                throw new InvalidArgumentException('Scale resolution evidence is invalid.');
            }
        }
        $valid = match ($status) {
            'confirmed' => $metersPerUnit !== null && is_finite($metersPerUnit) && $metersPerUnit > 0 && $evidenceRefs !== [] && $blockingIssue === null && $context !== null,
            'missing' => $metersPerUnit === null && $evidenceRefs === [] && $blockingIssue === 'geometry_scale_unconfirmed' && $context === null,
            'conflict' => $metersPerUnit === null && count($evidenceRefs) >= 2 && $blockingIssue === 'geometry_scale_conflict' && $context === null,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Scale resolution state is inconsistent.');
        }
    }
}
