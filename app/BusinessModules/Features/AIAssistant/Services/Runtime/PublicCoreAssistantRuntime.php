<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\Models\User;
use LogicException;

class PublicCoreAssistantRuntime
{
    public function readiness(User $viewer, int $organizationId): array
    {
        return PublicCoreRuntimeResource::unavailable();
    }

    public function submit(User $viewer, int $organizationId, array $command): array
    {
        return PublicCoreRuntimeResource::blocked();
    }

    public function poll(User $viewer, int $organizationId, string $requestRef): array
    {
        return PublicCoreRuntimeResource::blocked();
    }

    public function dispatchOwnedRequest(string $requestRef): void
    {
        throw new LogicException('runtime_not_activated');
    }
}
