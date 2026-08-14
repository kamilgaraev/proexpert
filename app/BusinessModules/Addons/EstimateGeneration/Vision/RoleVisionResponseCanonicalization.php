<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

final readonly class RoleVisionResponseCanonicalization
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}
}
