<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\Organization;
use App\Models\User;

interface AssistantReportComposerInterface
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function compose(Organization $organization, User $user, array $input): array;
}
