<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts;

use DateTimeImmutable;

interface ProcurementOwnerWorkflowRuntime
{
    public function within(callable $workflow): mixed;

    public function occurredAt(): DateTimeImmutable;

    public function afterCommit(callable $callback): void;
}
