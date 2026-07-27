<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use DateTimeZone;

final class ReportExecutionContextBuilder
{
    private ReportActor $actor;
    private ReportScope $scope;
    private ReportVisibility $visibility;
    private AuthorizationDecisionContext $authorization;

    public function __construct()
    {
        $timezone = new DateTimeZone('UTC');
        $this->actor = new ReportActor(1, 'active', ['reports.view']);
        $this->scope = new ReportScope(1, [1], [], [], $timezone);
        $this->visibility = new ReportVisibility(true, true, true, true, false, false, false);
        $this->authorization = new AuthorizationDecisionContext('http', 1, [1], [], [], $timezone, 'report-test', null);
    }

    public function actor(ReportActor $value): self { $this->actor = $value; return $this; }
    public function scope(ReportScope $value): self { $this->scope = $value; return $this; }
    public function visibility(ReportVisibility $value): self { $this->visibility = $value; return $this; }
    public function authorization(AuthorizationDecisionContext $value): self { $this->authorization = $value; return $this; }

    public function build(): ReportExecutionContext
    {
        return new ReportExecutionContext($this->actor, $this->scope, $this->visibility, $this->authorization);
    }
}
