<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectProblemItem;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;

interface ProjectProblemSource
{
    public function isAvailable(ProjectContext $projectContext): bool;

    public function collect(Project $project, ProjectContext $projectContext): iterable;
}
