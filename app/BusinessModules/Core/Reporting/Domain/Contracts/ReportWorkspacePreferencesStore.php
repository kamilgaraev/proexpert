<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences;
use Closure;

interface ReportWorkspacePreferencesStore
{
    public function get(int $organizationId, int $ownerId): ?ReportWorkspacePreferences;

    /** @param Closure(ReportWorkspacePreferences): ReportWorkspacePreferences $change */
    public function updateLocked(int $organizationId, int $ownerId, Closure $change): ReportWorkspacePreferences;
}
