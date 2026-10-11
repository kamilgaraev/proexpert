<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final readonly class WarehouseMovementFormExportService
{
    public function __construct(
        private WarehouseMovementDocumentResolver $documentResolver,
        private WarehouseExportManager $exportManager,
    ) {}

    public function temporaryUrl(string $form, int $movementId, int $organizationId): string
    {
        $movement = WarehouseMovement::query()
            ->with([
                'organization',
                'warehouse',
                'fromWarehouse',
                'toWarehouse',
                'project',
                'user',
                'material.measurementUnit',
            ])
            ->where('organization_id', $organizationId)
            ->find($movementId);

        if (! $movement instanceof WarehouseMovement) {
            throw (new ModelNotFoundException)->setModel(WarehouseMovement::class, [$movementId]);
        }

        if (! $this->supports($form, $movement)) {
            throw new InvalidArgumentException('warehouse_movement_form_is_not_available');
        }

        $movements = $this->documentResolver->resolve($movement);
        $movements->loadMissing([
            'organization',
            'warehouse',
            'fromWarehouse',
            'toWarehouse',
            'project',
            'user',
            'material.measurementUnit',
        ]);
        $path = $this->exportManager->export($form, $movements);

        return $this->exportManager->getTemporaryUrl($path);
    }

    private function supports(string $form, WarehouseMovement $movement): bool
    {
        return in_array($form, $movement->availableDocumentTypes(), true);
    }
}
