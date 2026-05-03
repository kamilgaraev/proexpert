<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Agent;

final readonly class AssistantTaskSlot
{
    public function __construct(
        public string $name,
        public bool $required,
        public mixed $value = null,
        public ?string $label = null
    ) {}

    public function isMissing(): bool
    {
        return $this->required && ($this->value === null || $this->value === '');
    }

    public function withValue(mixed $value, ?string $label = null): self
    {
        return new self($this->name, $this->required, $value, $label ?? $this->label);
    }

    /**
     * @return array{name: string, required: bool, value: mixed, label: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'required' => $this->required,
            'value' => $this->value,
            'label' => $this->label,
        ];
    }

    /**
     * @param  array{name?: mixed, required?: mixed, value?: mixed, label?: mixed}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            required: (bool) ($data['required'] ?? false),
            value: $data['value'] ?? null,
            label: isset($data['label']) ? (string) $data['label'] : null
        );
    }
}
