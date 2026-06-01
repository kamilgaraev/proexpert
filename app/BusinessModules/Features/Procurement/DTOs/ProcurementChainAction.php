<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

final readonly class ProcurementChainAction
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $href = null,
        public string $method = 'GET',
        public ?string $requiredPermission = null,
        public bool $isEnabled = true,
        public ?string $disabledReason = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'href' => $this->href,
            'method' => $this->method,
            'required_permission' => $this->requiredPermission,
            'is_enabled' => $this->isEnabled,
            'disabled_reason' => $this->disabledReason,
        ];
    }
}
