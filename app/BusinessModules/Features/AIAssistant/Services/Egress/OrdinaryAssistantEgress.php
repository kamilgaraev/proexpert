<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\Models\User;
use App\Services\Privacy\Contracts\PrivacyDecision;
use Throwable;

final readonly class OrdinaryAssistantEgress
{
    public function __construct(private ?OrdinaryAssistantEgressAuthority $authority = null,
        private OrdinaryAssistantTransport $transport = new OrdinaryAssistantTransport) {}

    public function transport(): OrdinaryAssistantTransport
    {
        return $this->transport;
    }

    public function prepare(array $messages, array $options, int $organizationId, User $actor, string $providerModel): OrdinaryAssistantEgressRequest
    {
        $request = OrdinaryAssistantEgressRequest::capture($messages, $options, $organizationId, $actor, $providerModel);
        $this->assertAuthorized($request);

        return $request;
    }

    public function assertCurrent(OrdinaryAssistantEgressRequest $admitted, array $messages, array $options, int $organizationId, User $actor, string $providerModel): void
    {
        $current = OrdinaryAssistantEgressRequest::capture($messages, $options, $organizationId, $actor, $providerModel, $admitted->attemptRef());
        if (! hash_equals($admitted->binding(), $current->binding())) {
            throw new AssistantEgressDenied('source_stale');
        }
        $this->assertAuthorized($current);
    }

    private function assertAuthorized(OrdinaryAssistantEgressRequest $request): void
    {
        try {
            $decision = $this->authority?->authorize($request) ?? PrivacyDecision::blocked('required_stage_unavailable');
        } catch (Throwable) {
            throw new AssistantEgressDenied('dependency_unavailable');
        }
        if (! $decision->isReady()) {
            throw new AssistantEgressDenied($decision->reason());
        }
        $binding = $decision->value();
        if (! is_string($binding) || ! hash_equals($request->binding(), $binding)) {
            throw new AssistantEgressDenied('scope_mismatch');
        }
    }
}
