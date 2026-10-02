<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\AIAssistant\Services\Rag\WorkforceRagMutationBridge;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\PayrollReadinessDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\PayrollCalculationVersion;
use Illuminate\Support\Facades\DB;
use App\Models\Organization;

final readonly class PayrollCalculationVersionService
{
    public function __construct(private PayrollReadinessDatabasePort $database)
    {
    }

    public function build(int $organizationId, int $periodId, int $actorId): PayrollCalculationVersion
    {
        return DB::transaction(function () use ($organizationId, $periodId, $actorId): PayrollCalculationVersion {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail(['id']);
            $version = $this->database->buildVersion($organizationId, $periodId, $actorId);
            app(WorkforceRagMutationBridge::class)->calculationVersion($organizationId, $version->id);
            return $version;
        });
    }

    public function validate(
        int $organizationId,
        int $calculationVersionId,
        int $actorId,
    ): PayrollCalculationVersion {
        return DB::transaction(function () use ($organizationId, $calculationVersionId, $actorId): PayrollCalculationVersion {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail(['id']);
            $version = $this->database->validateVersion($organizationId, $calculationVersionId, $actorId);
            app(WorkforceRagMutationBridge::class)->calculationVersion($organizationId, $version->id);
            return $version;
        });
    }

    public function lock(
        int $organizationId,
        int $calculationVersionId,
        int $actorId,
    ): PayrollCalculationVersion {
        return DB::transaction(function () use ($organizationId, $calculationVersionId, $actorId): PayrollCalculationVersion {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail(['id']);
            $version = $this->database->lockVersion($organizationId, $calculationVersionId, $actorId);
            app(WorkforceRagMutationBridge::class)->calculationVersion($organizationId, $version->id);
            return $version;
        });
    }

    public function current(int $organizationId, int $periodId): ?PayrollCalculationVersion
    {
        return $this->database->currentVersion($organizationId, $periodId);
    }
}
