<?php

namespace App\BusinessModules\Features\AIAssistant\Contracts;

use App\Models\Organization;
use App\Models\User;

interface AIToolInterface
{
    /**
     * Get the exact tool name (e.g. "generate_report").
     * This name must match [a-zA-Z0-9_-]{1,64}
     */
    public function getName(): string;

    /**
     * Get a human-readable description for the LLM to understand what the tool does.
     */
    public function getDescription(): string;

    /**
     * Get the JSON Schema for the parameters this tool accepts.
     * Example: ['type' => 'object', 'properties' => [...], 'required' => [...]]
     */
    public function getParametersSchema(): array;

    /**
     * Execute the tool with the provided arguments from the LLM.
     * 
     * @param array $arguments The parsed JSON arguments provided by the LLM
     * @param User|null $user The user initiating the request
     * @param Organization $organization The organization context
     * @return array|string The result to expose to the LLM (must be array or string)
     */
    public function execute(array $arguments, ?User $user, Organization $organization): array|string;
}
