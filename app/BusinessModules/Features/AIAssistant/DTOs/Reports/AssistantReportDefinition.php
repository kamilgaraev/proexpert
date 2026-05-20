<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Reports;

final readonly class AssistantReportDefinition
{
    /**
     * @param string[] $aliases
     * @param string[] $matchTerms
     * @param array<int, array{name: string, type: string, question: string}> $requiredSlots
     * @param array<int, array{name: string, type: string}> $optionalSlots
     * @param string[] $permissions
     * @param string[] $formats
     */
    public function __construct(
        public string $id,
        public string $capability,
        public string $label,
        public string $toolName,
        public array $aliases,
        public array $matchTerms,
        public array $requiredSlots,
        public array $optionalSlots,
        public array $permissions,
        public string $artifactType,
        public string $defaultFormat,
        public array $formats
    ) {}

    /**
     * @return array{
     *     id: string,
     *     domain: string,
     *     capability: string,
     *     label: string,
     *     tool_name: string,
     *     required_slots: array<int, array{name: string, type: string, question: string}>,
     *     optional_slots: array<int, array{name: string, type: string}>,
     *     read_permissions: string[],
     *     artifact: array{type: string},
     *     intent_examples: string[],
     *     match_terms: string[],
     *     default_format: string,
     *     formats: string[]
     * }
     */
    public function toAgentTask(): array
    {
        return [
            'id' => 'report.'.$this->id,
            'domain' => 'reports',
            'capability' => $this->capability,
            'label' => $this->label,
            'tool_name' => $this->toolName,
            'required_slots' => $this->requiredSlots,
            'optional_slots' => $this->optionalSlots,
            'read_permissions' => $this->permissions,
            'artifact' => ['type' => $this->artifactType],
            'intent_examples' => $this->aliases,
            'match_terms' => $this->matchTerms,
            'default_format' => $this->defaultFormat,
            'formats' => $this->formats,
        ];
    }
}
