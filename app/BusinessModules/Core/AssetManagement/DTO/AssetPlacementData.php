<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\DTO;

final readonly class AssetPlacementData
{
    public function __construct(
        public ?int $warehouseId = null,
        public ?int $projectId = null,
        public ?int $userId = null,
    ) {}

    public function destinationCount(): int
    {
        return count(array_filter([
            $this->warehouseId,
            $this->projectId,
            $this->userId,
        ], static fn (?int $id): bool => $id !== null));
    }
}
