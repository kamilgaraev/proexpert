<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO;

final readonly class ManagementPnlSourceTuple
{
    public function __construct(
        public object $run,
        public object $snapshot,
    ) {}
}
