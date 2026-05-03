<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Agent;

final readonly class AssistantTaskState
{
    /**
     * @param  AssistantTaskSlot[]  $slots
     */
    public function __construct(
        public string $id,
        public string $domain,
        public string $capability,
        public string $toolName,
        public string $status,
        public array $slots,
        public string $sourceMessage
    ) {}

    public function slotValue(string $name): mixed
    {
        foreach ($this->slots as $slot) {
            if ($slot instanceof AssistantTaskSlot && $slot->name === $name) {
                return $slot->value;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function missingRequiredSlotNames(): array
    {
        return array_values(array_map(
            static fn (AssistantTaskSlot $slot): string => $slot->name,
            array_filter($this->slots, static fn (AssistantTaskSlot $slot): bool => $slot->isMissing())
        ));
    }

    public function withSlotValue(string $name, mixed $value, ?string $label = null): self
    {
        $slots = array_map(
            static fn (AssistantTaskSlot $slot): AssistantTaskSlot => $slot->name === $name
                ? $slot->withValue($value, $label)
                : $slot,
            $this->slots
        );

        return new self(
            id: $this->id,
            domain: $this->domain,
            capability: $this->capability,
            toolName: $this->toolName,
            status: count(array_filter($slots, static fn (AssistantTaskSlot $slot): bool => $slot->isMissing())) > 0
                ? 'waiting_for_slots'
                : 'ready_to_execute',
            slots: $slots,
            sourceMessage: $this->sourceMessage
        );
    }

    /**
     * @return array{
     *     id: string,
     *     domain: string,
     *     capability: string,
     *     tool_name: string,
     *     status: string,
     *     slots: array<int, array{name: string, required: bool, value: mixed, label: string|null}>,
     *     source_message: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'capability' => $this->capability,
            'tool_name' => $this->toolName,
            'status' => $this->status,
            'slots' => array_map(
                static fn (AssistantTaskSlot $slot): array => $slot->toArray(),
                $this->slots
            ),
            'source_message' => $this->sourceMessage,
        ];
    }

    /**
     * @param array{
     *     id?: mixed,
     *     domain?: mixed,
     *     capability?: mixed,
     *     tool_name?: mixed,
     *     status?: mixed,
     *     slots?: mixed,
     *     source_message?: mixed
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $slots = is_array($data['slots'] ?? null)
            ? array_map(
                static fn (array $slot): AssistantTaskSlot => AssistantTaskSlot::fromArray($slot),
                array_filter($data['slots'], 'is_array')
            )
            : [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            domain: (string) ($data['domain'] ?? ''),
            capability: (string) ($data['capability'] ?? ''),
            toolName: (string) ($data['tool_name'] ?? ''),
            status: (string) ($data['status'] ?? 'waiting_for_slots'),
            slots: array_values($slots),
            sourceMessage: (string) ($data['source_message'] ?? '')
        );
    }
}
