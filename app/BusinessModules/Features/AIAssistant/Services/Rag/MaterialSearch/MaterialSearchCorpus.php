<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch;

use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;

interface MaterialSearchCorpus
{
    public function guard(AuthenticatedPrivateContext $context): ?string;

    public function records(): array;

    public function recordAllowed(AuthenticatedPrivateContext $context, string $ref): bool;

    public function priceAllowed(AuthenticatedPrivateContext $context): bool;

    public function scope(string $kind, array $unitRefs): array;

    public function profileRef(): string;

    public function profileVersion(): string;
}
