<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\Models\User;

final class AssistantCurrentRequest
{
    private ?AssistantRequest $request = null;

    public function replace(?AssistantRequest $request): ?AssistantRequest
    {
        $previous = $this->request;
        $this->request = $request;

        return $previous;
    }

    public function idFor(User $actor, int $organizationId): ?string
    {
        return $this->request !== null && (int) $this->request->user_id === (int) $actor->id
            && (int) $this->request->organization_id === $organizationId ? (string) $this->request->request_id : null;
    }

    public function hasRequest(): bool
    {
        return $this->request !== null;
    }
}
