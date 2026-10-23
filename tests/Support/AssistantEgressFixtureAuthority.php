<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressAuthority;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest;
use App\Services\Privacy\Contracts\PrivacyDecision;
use Closure;

final class AssistantEgressFixtureAuthority implements OrdinaryAssistantEgressAuthority
{
    public array $approvedPayload = [];

    public array $approvedScope = [];

    public int $calls = 0;

    public ?int $denyAt = null;

    public ?string $bindingOverride = null;

    public ?Closure $onAuthorize = null;

    public function approve(OrdinaryAssistantEgressRequest $fixture): void
    {
        $this->approvedPayload = $fixture->payload();
        $this->approvedScope = array_diff_key($fixture->scope(), ['attempt_ref' => true]);
    }

    public function authorize(OrdinaryAssistantEgressRequest $request): PrivacyDecision
    {
        $this->calls++;
        if ($this->denyAt !== null && $this->calls >= $this->denyAt) {
            return PrivacyDecision::blocked('access_denied');
        }
        if ($request->payload() !== $this->approvedPayload
            || array_diff_key($request->scope(), ['attempt_ref' => true]) !== $this->approvedScope) {
            return PrivacyDecision::blocked('source_unknown');
        }
        ($this->onAuthorize ?? static fn () => null)();

        return PrivacyDecision::ready($this->bindingOverride ?? $request->binding());
    }
}
