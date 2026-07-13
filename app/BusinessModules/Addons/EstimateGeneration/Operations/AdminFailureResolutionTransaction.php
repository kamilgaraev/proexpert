<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

interface AdminFailureResolutionTransaction
{
    /**
     * @param  callable(AdminFailureResolutionSnapshot, callable(): void): AdminFailureResolutionResult  $resolution
     */
    public function execute(AdminFailureResolutionCommand $command, callable $resolution): AdminFailureResolutionResult;
}
