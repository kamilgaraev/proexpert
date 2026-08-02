<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimSnapshot;

final readonly class ChangeClaimReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'change_claim_contingency';
    }

    public function organizationReady(int $organizationId): bool
    {
        return ChangeClaimSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('quality_status', 'complete')
            ->where('stale_at', '>', now())
            ->exists();
    }
}
