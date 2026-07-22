<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services\Sources;

use App\Domain\Project\ValueObjects\ProjectContext;

trait ChecksProjectProblemVisibility
{
    private function canViewProblemSource(ProjectContext $projectContext, string $permission): bool
    {
        return $projectContext->hasPermission($permission)
            || $projectContext->hasPermission('view_all');
    }
}
