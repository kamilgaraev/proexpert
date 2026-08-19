<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectInstance
{
    public function __construct(
        public string $kind,
        public ?string $canonicalValue,
    ) {}

    public function identitySuffix(): string
    {
        return match ($this->kind) {
            'absent' => '',
            'ordinal' => ':instance:'.$this->canonicalValue,
            default => ':instance:unsupported:'.$this->canonicalValue,
        };
    }
}
