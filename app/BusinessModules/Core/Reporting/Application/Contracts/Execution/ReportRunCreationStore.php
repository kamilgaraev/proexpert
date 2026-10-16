<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use Closure;

interface ReportRunCreationStore extends ReportRunStore
{
    public function createOrReuse(ReportExecutionContext $context, ReportQuery $query, ?ReportSavedViewRef $savedView, IdempotencyKey $idempotencyKey, ?Closure $beforeCreate = null): ReportRun;
}
