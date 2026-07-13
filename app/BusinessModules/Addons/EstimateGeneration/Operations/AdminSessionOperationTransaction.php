<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

interface AdminSessionOperationTransaction
{
    /** @param callable(AdminSessionOperationSnapshot): AdminSessionOperationResult $operation */
    public function execute(AdminSessionOperationCommand $command, callable $operation): AdminSessionOperationResult;
}
