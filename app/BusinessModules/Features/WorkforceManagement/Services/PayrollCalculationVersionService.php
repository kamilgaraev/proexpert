<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\PayrollReadinessDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\PayrollCalculationVersion;

final readonly class PayrollCalculationVersionService
{
    public function __construct(private PayrollReadinessDatabasePort $database)
    {
    }

    public function build(int $organizationId, int $periodId, int $actorId): PayrollCalculationVersion
    {
        return $this->database->buildVersion($organizationId, $periodId, $actorId);
    }

    public function validate(
        int $organizationId,
        int $calculationVersionId,
        int $actorId,
    ): PayrollCalculationVersion {
        return $this->database->validateVersion($organizationId, $calculationVersionId, $actorId);
    }

    public function lock(
        int $organizationId,
        int $calculationVersionId,
        int $actorId,
    ): PayrollCalculationVersion {
        return $this->database->lockVersion($organizationId, $calculationVersionId, $actorId);
    }

    public function current(int $organizationId, int $periodId): ?PayrollCalculationVersion
    {
        return $this->database->currentVersion($organizationId, $periodId);
    }
}
