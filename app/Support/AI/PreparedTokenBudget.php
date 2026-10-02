<?php

declare(strict_types=1);

namespace App\Support\AI;

final readonly class PreparedTokenBudget
{
    private function __construct(public array $prepared, private string $calibrationModel)
    {
    }

    public static function create(TokenBudgetService $service, array $messages, array $tools, string $profile, ?array $limits): self
    {
        return new self($service->prepare($messages, $tools, $profile, $limits), $service->calibrationModel());
    }

    public function matches(array $messages, array $tools, string $profile, array $limits, string $calibrationModel): bool
    {
        return $messages === $this->prepared['messages'] && $tools === $this->prepared['tools']
            && TokenBudgetService::normalizeProfile($profile) === $this->prepared['profile']
            && $limits['input'] === $this->prepared['input_limit']
            && $limits['output'] === $this->prepared['max_completion_tokens']
            && $limits['calls'] === $this->prepared['max_calls']
            && $calibrationModel === $this->calibrationModel;
    }
}
