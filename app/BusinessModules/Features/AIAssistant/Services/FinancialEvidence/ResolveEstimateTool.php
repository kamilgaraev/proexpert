<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\Models\Organization;
use App\Models\User;

final class ResolveEstimateTool extends ReadonlyEstimateTool
{
    public function __construct(private readonly AssistantEstimateResolver $resolver) {}

    public function getName(): string { return 'resolve_estimate'; }

    public function getDescription(): string { return trans_message('ai_assistant_financial.resolve_description'); }

    public function getParametersSchema(): array
    {
        return $this->schema(['query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000]]);
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $actor = $this->actor($user);
        $arguments = $this->validate($arguments, ['query' => ['required', 'string', 'max:4000']]);

        return $this->resolver->resolve($arguments['query'], (int) $organization->id, $actor);
    }
}
