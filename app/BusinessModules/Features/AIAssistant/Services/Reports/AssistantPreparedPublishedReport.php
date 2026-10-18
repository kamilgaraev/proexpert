<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\Models\User;

final readonly class AssistantPreparedPublishedReport
{
    public function __construct(public User $actor, public AssistantRequest $request, public array $parameters) {}
}
