<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

interface AdminFailureResolutionAuthorizer
{
    public function canOperate(int $actorId): bool;
}
