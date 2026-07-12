<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class VisionClarificationData
{
    public array $evidenceRefs;

    public function __construct(public string $code, public ?string $elementKey, array $evidenceRefs)
    {
        if (! in_array($code, ['geometry_scale_unconfirmed', 'geometry_scale_conflict', 'geometry_element_conflict', 'geometry_reference_unresolved'], true)
            || ! array_is_list($evidenceRefs) || count($evidenceRefs) !== count(array_unique($evidenceRefs))) {
            throw new InvalidArgumentException('Vision clarification is invalid.');
        }
        foreach ($evidenceRefs as $reference) {
            if (! is_string($reference) || $reference === '') {
                throw new InvalidArgumentException('Vision clarification evidence is invalid.');
            }
        }
        sort($evidenceRefs, SORT_STRING);
        $this->evidenceRefs = $evidenceRefs;
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'element_key' => $this->elementKey, 'evidence_refs' => $this->evidenceRefs];
    }
}
