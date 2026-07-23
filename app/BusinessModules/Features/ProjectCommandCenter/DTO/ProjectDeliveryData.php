<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\DTO;

final readonly class ProjectDeliveryData
{
    public function __construct(
        private bool $available,
        private ?string $reasonKey,
        private array $data,
    ) {
    }

    public static function unavailable(string $reasonKey): self
    {
        return new self(false, $reasonKey, []);
    }

    public static function available(array $data): self
    {
        return new self(true, null, $data);
    }

    public function toArray(): array
    {
        return $this->available
            ? ['available' => true, ...$this->data]
            : ['available' => false, 'reason_key' => $this->reasonKey];
    }
}
