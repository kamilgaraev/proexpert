<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

use InvalidArgumentException;

final readonly class SketchAssumption
{
    public string $key;

    public int|float|string|array $value;

    public string $source;

    public ?string $evidenceId;

    public bool $requiresConfirmation;

    public bool $evidenced;

    public function __construct(public SketchValueData $typedValue, public SketchProvenanceData $provenance, public float $confidence, bool $confirmed)
    {
        if (! is_finite($confidence) || $confidence < 0 || $confidence > 1 || ($provenance->source === 'catalog_default' && $confirmed)) {
            throw new InvalidArgumentException('Sketch assumption is invalid.');
        }
        $this->key = $typedValue->key;
        $this->value = $typedValue->value;
        $this->source = $provenance->source;
        $this->evidenceId = $provenance->evidenceRef;
        $this->requiresConfirmation = ! $confirmed;
        $this->evidenced = $confirmed && $provenance->evidenceRef !== null;
    }
}
