<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\DTO;

final readonly class WorkflowSnapshot
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload, public string $hash) {}
}
