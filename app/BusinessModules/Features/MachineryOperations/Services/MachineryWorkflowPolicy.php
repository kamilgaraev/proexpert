<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;

final readonly class MachineryWorkflowPolicy
{
    /** @var array<string, string> */
    private const ACTION_PERMISSIONS = [
        'assign' => 'machinery-operations.requests.approve',
        'start_operation' => 'machinery-operations.shifts.create',
        'return_available' => 'machinery-operations.edit',
        'maintenance' => 'machinery-operations.downtime.manage',
        'unavailable' => 'machinery-operations.downtime.manage',
        'archive' => 'machinery-operations.delete',
    ];

    public function __construct(private AuthorizationService $authorization) {}

    public function status(MachineryAsset $asset): string
    {
        $canonical = $asset->organizationAsset;

        if ($canonical === null) {
            return (string) $asset->status;
        }

        if ($canonical->lifecycle_status !== AssetLifecycleStatus::Active) {
            return 'archived';
        }

        if ($canonical->technical_status === AssetTechnicalStatus::Maintenance) {
            return 'maintenance';
        }

        if (in_array($canonical->technical_status, [AssetTechnicalStatus::Restricted, AssetTechnicalStatus::Unavailable], true)) {
            return 'unavailable';
        }

        $metadata = is_array($canonical->metadata) ? $canonical->metadata : [];
        $operationStatus = $metadata['machinery_operation_status'] ?? null;

        if ($canonical->current_project_id !== null && in_array($operationStatus, ['assigned', 'in_operation'], true)) {
            return $operationStatus;
        }

        return $canonical->current_project_id !== null ? 'assigned' : 'available';
    }

    /** @return list<string> */
    public function availableActions(MachineryAsset $asset, ?User $actor): array
    {
        if ($actor === null) {
            return [];
        }

        $actions = match ($this->status($asset)) {
            'available' => ['assign', 'maintenance', 'unavailable', 'archive'],
            'assigned' => ['start_operation', 'return_available', 'maintenance'],
            'in_operation' => ['return_available', 'maintenance', 'unavailable'],
            'maintenance' => ['return_available'],
            'unavailable' => ['return_available', 'maintenance', 'archive'],
            default => [],
        };
        $context = ['organization_id' => (int) $asset->organization_id];

        return array_values(array_filter(
            $actions,
            fn (string $action): bool => $this->authorization->can($actor, self::ACTION_PERMISSIONS[$action], $context),
        ));
    }
}
