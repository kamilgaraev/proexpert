<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

interface AdminSessionOperationExecutor
{
    public function execute(
        AdminSessionOperationCommand $command,
        AdminSessionOperationSnapshot $snapshot,
    ): AdminSessionOperationResult;
}
