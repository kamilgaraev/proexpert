<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding;

final readonly class AssistantToolEligibility
{
    public function __construct(
        public bool $allowed,
        public ?string $reason,
        public string $category,
        public bool $requiresConfirmation = false,
    ) {}

    public static function allow(string $category, bool $requiresConfirmation = false): self
    {
        return new self(true, null, $category, $requiresConfirmation);
    }

    public static function block(string $category, string $reason, bool $requiresConfirmation = false): self
    {
        return new self(false, $reason, $category, $requiresConfirmation);
    }

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'category' => $this->category,
            'requires_confirmation' => $this->requiresConfirmation,
        ];
    }
}
