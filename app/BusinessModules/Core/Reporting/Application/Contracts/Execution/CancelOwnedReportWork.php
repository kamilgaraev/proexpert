<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

interface CancelOwnedReportWork
{
    public function handle(int $organizationId, int $requesterActorId, ?string $runId, IdempotencyKey $runKey,
        ?string $exportId = null, ?IdempotencyKey $exportKey = null): void;
}
