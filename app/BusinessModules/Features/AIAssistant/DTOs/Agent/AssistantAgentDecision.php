<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Agent;

final readonly class AssistantAgentDecision
{
    /**
     * @param  array<string, mixed>  $toolArguments
     */
    public function __construct(
        public string $type,
        public ?AssistantTaskState $state = null,
        public ?string $toolName = null,
        public array $toolArguments = [],
        public ?string $clarificationQuestion = null
    ) {}
}
