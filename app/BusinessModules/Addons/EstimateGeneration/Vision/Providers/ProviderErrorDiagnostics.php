<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Providers;

final readonly class ProviderErrorDiagnostics
{
    /**
     * @param  array<string, bool|int|string>  $payload
     * @param  array<string, int|string>  $failureContext
     */
    public function __construct(
        public array $payload,
        public array $failureContext,
    ) {}
}
