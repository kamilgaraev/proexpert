<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO;

use DomainException;

final readonly class ContingencyMovement
{
    private function __construct(
        public string $type,
        public int $amountMinor,
        public string $currency,
        public ?int $projectId = null,
        public ?int $allocationId = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?int $sourceVersion = null,
        public ?string $idempotencyKey = null,
    ) {
        if ($amountMinor < 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('contingency_movement_invalid');
        }
    }

    public static function opening(int $amountMinor, string $currency): self
    {
        return new self('opening', $amountMinor, $currency);
    }

    public static function allocation(int $amountMinor, string $currency): self
    {
        return new self('allocation', $amountMinor, $currency);
    }

    public static function consumption(int $amountMinor, string $currency): self
    {
        return new self('consumption', $amountMinor, $currency);
    }

    public static function release(int $amountMinor, string $currency): self
    {
        return new self('release', $amountMinor, $currency);
    }

    public static function recorded(
        string $type,
        int $amountMinor,
        string $currency,
        int $projectId,
        int $allocationId,
        string $sourceType,
        string $sourceId,
        int $sourceVersion,
        string $idempotencyKey,
    ): self {
        return new self(
            $type,
            $amountMinor,
            $currency,
            $projectId,
            $allocationId,
            $sourceType,
            $sourceId,
            $sourceVersion,
            $idempotencyKey,
        );
    }

    public function signedMinor(): int
    {
        return in_array($this->type, ['consumption', 'release'], true)
            ? -$this->amountMinor
            : $this->amountMinor;
    }
}
