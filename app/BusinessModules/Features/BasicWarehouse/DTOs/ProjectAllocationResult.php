<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\DTOs;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;

final readonly class ProjectAllocationResult
{
    public function __construct(
        public WarehouseProjectAllocation $allocation,
        public ProjectMaterialDelivery $delivery,
    ) {}
}
