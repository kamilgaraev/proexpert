<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\Filament\Support\FilamentPermission;
use App\Models\SystemAdmin;

final class SystemAdminSessionOperationAuthorizer implements AdminSessionOperationAuthorizer
{
    public function canOperate(int $actorId): bool
    {
        $actor = SystemAdmin::query()->find($actorId);

        return $actor instanceof SystemAdmin
            && $actor->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_OPERATE);
    }
}
