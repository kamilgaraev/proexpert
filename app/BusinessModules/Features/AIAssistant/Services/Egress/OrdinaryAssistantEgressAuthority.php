<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\Services\Privacy\Contracts\PrivacyDecision;

interface OrdinaryAssistantEgressAuthority
{
    public function authorize(OrdinaryAssistantEgressRequest $request): PrivacyDecision;
}
