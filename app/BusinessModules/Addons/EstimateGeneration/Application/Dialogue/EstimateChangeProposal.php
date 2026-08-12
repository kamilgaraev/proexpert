<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

final readonly class EstimateChangeProposal
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}

    public function id(): string
    {
        return (string) $this->payload['id'];
    }
}
