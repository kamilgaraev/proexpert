<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Backfill;

use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionBackfill as AcceptanceEventBackfill;

final readonly class AcceptedProductionBackfill
{
    public function __construct(private AcceptanceEventBackfill $backfill)
    {
    }

    public function run(int $organizationId, array $projectIds, ?int $actorId = null): array
    {
        return $this->backfill->run($organizationId, $projectIds, $actorId);
    }
}
