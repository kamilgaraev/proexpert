<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;

final readonly class CurrentReportAuthorization
{
    public function __construct(
        public ReportActor $actor,
        public AuthorizationDecisionContext $decision,
        public ReportVisibility $visibility,
        public CurrentReportAuthorizationTarget $target,
    ) {
        if ($actor->id < 1 || $decision->organizationId < 1) {
            throw new \InvalidArgumentException('current_report_authorization_invalid');
        }
    }
}
