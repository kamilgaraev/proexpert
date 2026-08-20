<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final readonly class VisualObjectScope
{
    /** @param array<string, string> $segments @param list<string> $unknownTokens */
    public function __construct(
        public array $segments,
        public array $unknownTokens,
    ) {}

    public function roomKey(): ?string
    {
        return isset($this->segments['room']) ? 'room:'.$this->segments['room'] : null;
    }

    public function identityPrefix(): string
    {
        $parts = [];
        foreach (['building', 'section', 'floor', 'room'] as $segment) {
            if (isset($this->segments[$segment])) {
                $parts[] = $segment.':'.$this->segments[$segment];
            }
        }
        if ($this->unknownTokens !== []) {
            $parts[] = 'scope:unknown:'.substr(hash('sha256', implode('.', $this->unknownTokens)), 0, 16);
        }

        return $parts === [] ? 'room:unknown' : implode(':', $parts);
    }
}
