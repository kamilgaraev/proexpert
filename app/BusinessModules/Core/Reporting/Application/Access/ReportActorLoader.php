<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;

interface ReportActorLoader
{
    public function loadActive(int $actorId): ReportActor;
}
