<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use InvalidArgumentException;

final readonly class EstimateCommandInterpretation
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        if (! in_array($payload['kind'] ?? null, ['explain', 'correct_fact', 'select_technology'], true)) {
            throw new InvalidArgumentException('estimate_generation.command_intent_invalid');
        }
        if (! is_string($payload['version'] ?? null) || strlen($payload['version']) > 80) {
            throw new InvalidArgumentException('estimate_generation.command_intent_invalid');
        }
    }

    public function kind(): string
    {
        return (string) $this->payload['kind'];
    }
}
