<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final readonly class WarehouseMovementFormExportService
{
    private const M11_WRITE_OFF_CATEGORIES = [
        WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
        WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
        WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
    ];

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
        return match ($form) {
            'm4', 'm7' => $movement->movement_type === WarehouseMovement::TYPE_RECEIPT,
            'm11' => $this->supportsM11($movement),
            'm15' => $this->supportsM15($movement),
            default => false,
        };
    }

    private function supportsM11(WarehouseMovement $movement): bool
    {
        if (($movement->metadata['is_contractor_transfer'] ?? false) === true) {
            return false;
        }

        if ($movement->movement_type === WarehouseMovement::TYPE_TRANSFER_OUT) {
            return $movement->operation_category !== WarehouseMovement::CATEGORY_PLACEMENT;
        }

        return $movement->movement_type === WarehouseMovement::TYPE_WRITE_OFF
            && in_array($movement->operation_category, self::M11_WRITE_OFF_CATEGORIES, true);
    }

    private function supportsM15(WarehouseMovement $movement): bool
    {
        return in_array($movement->movement_type, [
            WarehouseMovement::TYPE_TRANSFER_OUT,
            WarehouseMovement::TYPE_WRITE_OFF,
        ], true) && ($movement->metadata['is_contractor_transfer'] ?? false) === true;
    }
}
