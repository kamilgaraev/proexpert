<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrganizationResource\Pages;

use App\Filament\Actions\LegacyEstimatorReconciliationAction;
use App\Filament\Actions\LegacyEstimatorRefundResolutionAction;
use App\Filament\Actions\LegacyEstimatorRestoreAction;
use App\Filament\Resources\OrganizationResource;
use App\Models\Organization;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [
            EditAction::make(),
        ];
        $organization = $this->getRecord();
        if ($organization instanceof Organization) {
            $actions[] = LegacyEstimatorReconciliationAction::make($organization);
            $actions[] = LegacyEstimatorRestoreAction::make($organization);
            $actions[] = LegacyEstimatorRefundResolutionAction::make($organization);
        }

        return $actions;
    }
}
