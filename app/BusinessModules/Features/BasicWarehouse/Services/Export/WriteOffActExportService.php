<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final readonly class WriteOffActExportService
{
    private const SUPPORTED_CATEGORIES = [
        WarehouseMovement::CATEGORY_LOSS,
        WarehouseMovement::CATEGORY_DAMAGE,
        WarehouseMovement::CATEGORY_DISPOSAL,
        WarehouseMovement::CATEGORY_INVENTORY_ADJUSTMENT,
    ];

    public function __construct(
        private WarehouseMovementDocumentResolver $documentResolver,
        private WarehouseExportManager $exportManager
    ) {}

    public function temporaryUrl(int $movementId, int $organizationId): string
    {
        $movement = WarehouseMovement::query()
            ->with([
                'organization',
                'warehouse',
                'project',
                'user',
                'material.measurementUnit',
            ])
            ->where('organization_id', $organizationId)
            ->find($movementId);

        if (! $movement instanceof WarehouseMovement) {
            throw (new ModelNotFoundException)->setModel(WarehouseMovement::class, [$movementId]);
        }

        if ($movement->movement_type !== WarehouseMovement::TYPE_WRITE_OFF
            || ! in_array($movement->operation_category, self::SUPPORTED_CATEGORIES, true)) {
            throw new InvalidArgumentException('write_off_act_is_not_available');
        }

        $movements = $this->documentResolver->resolve($movement);
        $movements->loadMissing([
            'organization',
            'warehouse',
            'project',
            'user',
            'material.measurementUnit',
        ]);
        $path = $this->exportManager->export('write_off_act', $movements);

        return $this->exportManager->getTemporaryUrl($path);
    }
}
