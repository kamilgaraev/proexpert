<?php

declare(strict_types=1);

namespace App\Services\ErpControls\DTO;

final readonly class ErpControlDecision
{
    public function __construct(
        public bool $allowed,
        public string $riskLevel,
        public string $operation,
        public string $decision,
        public string $message,
        public array $blockers = [],
        public array $warnings = [],
        public array $requiredActions = [],
        public bool $overrideAvailable = false,
        public array $context = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'risk_level' => $this->riskLevel,
            'operation' => $this->operation,
            'decision' => $this->decision,
            'message' => $this->message,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'required_actions' => $this->requiredActions,
            'override_available' => $this->overrideAvailable,
            'context' => $this->context,
        ];
    }
}
